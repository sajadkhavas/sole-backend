<?php

namespace App\Services\Auth;

use App\Models\AccountLifecycleRequest;
use App\Models\AuthIdentity;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerAccountLifecycleService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function requestDeletion(User $user): AccountLifecycleRequest
    {
        return DB::transaction(function () use ($user): AccountLifecycleRequest {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->account_status === 'deleted') {
                throw ValidationException::withMessages(['account' => 'This account has already been deleted.']);
            }

            $existing = AccountLifecycleRequest::query()
                ->where('user_id', $locked->id)
                ->where('type', 'deletion')
                ->where('status', 'requested')
                ->latest()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $before = $locked->only(['account_status', 'deletion_requested_at']);
            $locked->forceFill([
                'account_status' => 'deletion_requested',
                'deletion_requested_at' => now(),
            ])->save();

            $request = AccountLifecycleRequest::query()->create([
                'user_id' => $locked->id,
                'type' => 'deletion',
                'status' => 'requested',
                'metadata' => ['grace_state' => 'cancelable'],
                'requested_at' => now(),
            ]);

            $this->auditLogger->record('customer.deletion_requested', $locked, $before, $locked->only([
                'account_status',
                'deletion_requested_at',
            ]));

            return $request;
        }, 3);
    }

    public function cancelDeletion(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->account_status !== 'deletion_requested') {
                throw ValidationException::withMessages(['account' => 'There is no pending deletion request.']);
            }

            $request = AccountLifecycleRequest::query()
                ->where('user_id', $locked->id)
                ->where('type', 'deletion')
                ->where('status', 'requested')
                ->latest()
                ->lockForUpdate()
                ->firstOrFail();

            $before = $locked->only(['account_status', 'deletion_requested_at']);
            $locked->forceFill([
                'account_status' => 'active',
                'deletion_requested_at' => null,
            ])->save();

            $request->forceFill([
                'status' => 'cancelled',
                'completed_at' => now(),
            ])->save();

            $this->auditLogger->record('customer.deletion_cancelled', $locked, $before, $locked->only([
                'account_status',
                'deletion_requested_at',
            ]));
        }, 3);
    }

    public function fulfillDeletion(string $requestId): void
    {
        DB::transaction(function () use ($requestId): void {
            $request = AccountLifecycleRequest::query()
                ->whereKey($requestId)
                ->where('type', 'deletion')
                ->where('status', 'requested')
                ->lockForUpdate()
                ->firstOrFail();

            $user = User::query()->lockForUpdate()->findOrFail($request->user_id);

            AuthIdentity::query()->where('user_id', $user->id)->delete();
            CustomerAddress::query()->where('user_id', $user->id)->delete();
            CustomerProfile::query()->where('user_id', $user->id)->delete();

            $before = $user->only(['account_status', 'deletion_requested_at']);
            $user->forceFill([
                'name' => 'Deleted customer',
                'email' => 'deleted+'.Str::uuid().'@privacy.invalid',
                'password' => Str::random(64),
                'remember_token' => null,
                'account_status' => 'deleted',
                'deletion_requested_at' => null,
                'is_active' => false,
            ])->saveQuietly();

            $request->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'metadata' => ['result' => 'pseudonymized'],
            ])->save();

            $this->auditLogger->record('customer.deletion_fulfilled', $user, $before, $user->only([
                'account_status',
                'deletion_requested_at',
            ]));
        }, 3);
    }
}
