<?php

namespace App\Services\Auth;

use App\Contracts\OtpSender;
use App\Models\CustomerProfile;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class OtpChallengeService
{
    public function __construct(
        private readonly OtpSender $sender,
        private readonly IranMobileNormalizer $normalizer,
    ) {}

    public function request(User $user, string $phone, Request $request): OtpChallenge
    {
        $this->assertEnabled();

        $phoneE164 = $this->normalizer->normalize($phone);
        $profile = CustomerProfile::query()->firstOrCreate(['user_id' => $user->id]);

        if ($profile->phone_e164 !== $phoneE164) {
            $profile->forceFill([
                'phone_e164' => $phoneE164,
                'phone_verified_at' => null,
            ])->save();
        }

        $rateKey = 'otp:request:'.hash('sha256', $user->id.'|'.$phoneE164.'|'.($request->ip() ?? 'unknown'));
        $limit = (int) config('sole_auth.otp.request_limit', 5);
        $decay = (int) config('sole_auth.otp.request_decay_seconds', 600);

        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($rateKey), 'Too many OTP requests.');
        }

        $latest = OtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('phone_e164', $phoneE164)
            ->where('purpose', 'verify_phone')
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if ($latest !== null && $latest->resend_available_at->isFuture()) {
            throw new TooManyRequestsHttpException(
                max(1, now()->diffInSeconds($latest->resend_available_at)),
                'OTP resend is not available yet.',
            );
        }

        RateLimiter::hit($rateKey, $decay);

        $code = (string) random_int(100000, 999999);
        $challenge = OtpChallenge::query()->create([
            'user_id' => $user->id,
            'phone_e164' => $phoneE164,
            'purpose' => 'verify_phone',
            'code_digest' => $this->digest($code),
            'max_attempts' => (int) config('sole_auth.otp.max_attempts', 5),
            'expires_at' => now()->addSeconds((int) config('sole_auth.otp.ttl_seconds', 300)),
            'resend_available_at' => now()->addSeconds((int) config('sole_auth.otp.resend_seconds', 60)),
            'request_ip' => $request->ip(),
        ]);

        try {
            $this->sender->send($phoneE164, $code);
        } catch (\Throwable $exception) {
            $challenge->delete();
            throw $exception;
        }

        return $challenge;
    }

    public function verify(User $user, string $challengeId, string $code): CustomerProfile
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($user, $challengeId, $code): CustomerProfile {
            $challenge = OtpChallenge::query()
                ->whereKey($challengeId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                throw new NotFoundHttpException('OTP challenge not found.');
            }

            if ($challenge->consumed_at !== null) {
                throw ValidationException::withMessages(['code' => 'This OTP has already been used.']);
            }

            if ($challenge->expires_at->isPast()) {
                throw ValidationException::withMessages(['code' => 'This OTP has expired.']);
            }

            if ($challenge->attempt_count >= $challenge->max_attempts) {
                throw new TooManyRequestsHttpException(null, 'OTP attempt limit reached.');
            }

            $challenge->increment('attempt_count');
            $challenge->refresh();

            if (! hash_equals($challenge->code_digest, $this->digest($code))) {
                throw ValidationException::withMessages(['code' => 'The OTP code is invalid.']);
            }

            $challenge->forceFill(['consumed_at' => now()])->save();

            $profile = CustomerProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            if ($profile->phone_e164 !== $challenge->phone_e164) {
                throw ValidationException::withMessages(['phone' => 'The profile phone changed after this OTP was issued.']);
            }

            $profile->forceFill(['phone_verified_at' => now()])->save();

            return $profile->refresh();
        }, 3);
    }

    private function assertEnabled(): void
    {
        if (! config('sole_auth.otp.enabled')) {
            throw new NotFoundHttpException;
        }
    }

    private function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
