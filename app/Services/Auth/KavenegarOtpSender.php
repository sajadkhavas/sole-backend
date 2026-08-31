<?php

namespace App\Services\Auth;

use App\Contracts\OtpSender;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class KavenegarOtpSender implements OtpSender
{
    public function send(string $phoneE164, string $code): void
    {
        $apiKey = trim((string) config('sole_auth.otp.kavenegar.api_key'));
        $template = trim((string) config('sole_auth.otp.kavenegar.verify_template'));

        if ($apiKey === '' || $template === '') {
            throw new ServiceUnavailableHttpException(null, 'OTP delivery is not configured.');
        }

        $receptor = str_replace('+', '', $phoneE164);

        Http::asForm()
            ->acceptJson()
            ->timeout((int) config('sole_auth.otp.kavenegar.timeout_seconds', 10))
            ->post('https://api.kavenegar.com/v1/'.rawurlencode($apiKey).'/verify/lookup.json', [
                'receptor' => $receptor,
                'token' => $code,
                'template' => $template,
            ])
            ->throw();
    }
}
