<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthIdentity;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Auth\GoogleOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CustomerAuthController extends Controller
{
    public function redirect(Request $request, GoogleOAuthService $google): RedirectResponse
    {
        $state = Str::random(64);
        $returnTo = $google->normalizeReturnPath($request->query('return_to'));

        $request->session()->put('google_oauth_state', $state);
        $request->session()->put('google_return_to', $returnTo);

        return redirect()->away($google->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleOAuthService $google): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:4096'],
            'state' => ['required', 'string', 'max:512'],
        ]);

        $expectedState = (string) $request->session()->pull('google_oauth_state', '');
        $returnTo = (string) $request->session()->pull('google_return_to', '/account');

        if ($expectedState === '' || ! hash_equals($expectedState, (string) $request->query('state'))) {
            abort(419, 'OAuth state validation failed.');
        }

        $identity = $google->identityFromCode((string) $request->query('code'));

        $user = DB::transaction(function () use ($identity): User {
            $linked = AuthIdentity::query()
                ->where('provider', 'google')
                ->where('provider_subject', $identity['sub'])
                ->lockForUpdate()
                ->first();

            if ($linked !== null) {
                return $linked->user()->lockForUpdate()->firstOrFail();
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$identity['email']])
                ->lockForUpdate()
                ->first();

            if ($user !== null && $user->roles()->exists()) {
                throw new AccessDeniedHttpException('Privileged administrator identities cannot use the customer sign-in boundary.');
            }

            if ($user === null) {
                $user = User::query()->make([
                    'name' => $identity['name'] ?: Str::before($identity['email'], '@'),
                    'email' => $identity['email'],
                    'password' => Str::random(64),
                    'is_active' => false,
                    'account_status' => 'active',
                ]);
                $user->forceFill(['email_verified_at' => now()]);
                $user->save();
            } elseif ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            AuthIdentity::query()->create([
                'user_id' => $user->id,
                'provider' => 'google',
                'provider_subject' => $identity['sub'],
                'email_at_link' => $identity['email'],
                'avatar_url' => $identity['picture'] ?? null,
                'metadata' => ['name' => $identity['name'] ?? null],
            ]);

            CustomerProfile::query()->firstOrCreate(['user_id' => $user->id]);

            return $user;
        }, 3);

        if ($user->account_status === 'deleted' || $user->account_status === 'deactivated') {
            throw new AccessDeniedHttpException('This customer account is not active.');
        }

        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $profile = CustomerProfile::query()->firstOrCreate(['user_id' => $user->id]);
        $target = $profile->phone_e164 === null ? '/auth?complete=phone' : $returnTo;

        return redirect()->away($google->frontendRedirect($target));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = CustomerProfile::query()->firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_status' => $user->account_status,
                'account_complete' => $profile->phone_e164 !== null,
                'phone_e164' => $profile->phone_e164,
                'phone_verified_at' => $profile->phone_verified_at?->toISOString(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
