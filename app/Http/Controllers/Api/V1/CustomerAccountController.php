<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Services\Auth\CustomerAccountLifecycleService;
use App\Services\Auth\IranMobileNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = CustomerProfile::query()->firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'account_status' => $user->account_status,
                'deletion_requested_at' => $user->deletion_requested_at?->toISOString(),
                'profile' => [
                    'phone_e164' => $profile->phone_e164,
                    'phone_verified_at' => $profile->phone_verified_at?->toISOString(),
                    'locale' => $profile->locale,
                ],
            ],
        ]);
    }

    public function update(Request $request, IranMobileNormalizer $normalizer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:12'],
        ]);

        try {
            $phone = $normalizer->normalize($data['phone']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        $user = $request->user();
        $duplicate = CustomerProfile::query()
            ->where('phone_e164', $phone)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['phone' => 'This mobile number is already in use.']);
        }

        $profile = DB::transaction(function () use ($user, $data, $phone): CustomerProfile {
            $profile = CustomerProfile::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);
            $phoneChanged = $profile->phone_e164 !== $phone;

            $user->forceFill(['name' => trim($data['name'])])->save();
            $profile->forceFill([
                'phone_e164' => $phone,
                'phone_verified_at' => $phoneChanged ? null : $profile->phone_verified_at,
                'locale' => $data['locale'] ?? null,
            ])->save();

            return $profile->refresh();
        }, 3);

        return response()->json([
            'data' => [
                'name' => $user->fresh()->name,
                'phone_e164' => $profile->phone_e164,
                'phone_verified_at' => $profile->phone_verified_at?->toISOString(),
                'account_complete' => $profile->phone_e164 !== null,
            ],
        ]);
    }

    public function addresses(Request $request): JsonResponse
    {
        return response()->json([
            'data' => CustomerAddress::query()
                ->where('user_id', $request->user()->id)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function storeAddress(Request $request, IranMobileNormalizer $normalizer): JsonResponse
    {
        $data = $this->validatedAddress($request, $normalizer);
        $userId = $request->user()->id;

        $address = DB::transaction(function () use ($data, $userId): CustomerAddress {
            if ($data['is_default']) {
                CustomerAddress::query()->where('user_id', $userId)->update(['is_default' => false]);
            }

            return CustomerAddress::query()->create(['user_id' => $userId, ...$data]);
        }, 3);

        return response()->json(['data' => $address], 201);
    }

    public function updateAddress(Request $request, int $address, IranMobileNormalizer $normalizer): JsonResponse
    {
        $data = $this->validatedAddress($request, $normalizer);
        $model = CustomerAddress::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($address);

        DB::transaction(function () use ($data, $model): void {
            if ($data['is_default']) {
                CustomerAddress::query()
                    ->where('user_id', $model->user_id)
                    ->where('id', '!=', $model->id)
                    ->update(['is_default' => false]);
            }

            $model->update($data);
        }, 3);

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroyAddress(Request $request, int $address): JsonResponse
    {
        CustomerAddress::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($address)
            ->delete();

        return response()->json(status: 204);
    }

    public function consents(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ConsentRecord::query()
                ->where('user_id', $request->user()->id)
                ->latest('occurred_at')
                ->get(),
        ]);
    }

    public function recordConsent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['privacy', 'terms', 'marketing_email', 'marketing_sms', 'marketing_push'])],
            'granted' => ['required', 'boolean'],
            'policy_version' => ['required', 'string', 'max:64'],
        ]);

        $record = ConsentRecord::query()->create([
            'user_id' => $request->user()->id,
            ...$data,
            'source' => 'account',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);

        return response()->json(['data' => $record], 201);
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user()->load(['authIdentities', 'customerProfile', 'customerAddresses']);

        return response()->json([
            'data' => [
                'account' => $user->only(['id', 'name', 'email', 'account_status', 'created_at']),
                'identities' => $user->authIdentities->map->only(['provider', 'email_at_link', 'created_at']),
                'profile' => $user->customerProfile,
                'addresses' => $user->customerAddresses,
                'consents' => ConsentRecord::query()->where('user_id', $user->id)->orderBy('occurred_at')->get(),
            ],
        ], headers: ['Content-Disposition' => 'attachment; filename="sole-account-export.json"']);
    }

    public function requestDeletion(Request $request, CustomerAccountLifecycleService $lifecycle): JsonResponse
    {
        $model = $lifecycle->requestDeletion($request->user());

        return response()->json([
            'data' => [
                'id' => $model->id,
                'status' => $model->status,
                'requested_at' => $model->requested_at?->toISOString(),
            ],
        ], 202);
    }

    public function cancelDeletion(Request $request, CustomerAccountLifecycleService $lifecycle): JsonResponse
    {
        $lifecycle->cancelDeletion($request->user());

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAddress(Request $request, IranMobileNormalizer $normalizer): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'country_code' => ['required', 'string', 'size:2'],
            'province' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'is_default' => ['required', 'boolean'],
        ]);

        try {
            $data['phone_e164'] = $normalizer->normalize($data['phone']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        unset($data['phone']);

        return $data;
    }
}
