<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use UnexpectedValueException;

class GoogleOAuthService
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('sole_auth.google.client_id'),
            'redirect_uri' => config('sole_auth.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{sub:string,email:string,email_verified:bool,name?:string,picture?:string}
     */
    public function identityFromCode(string $code): array
    {
        $this->assertConfigured();

        $token = $this->http()->asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('sole_auth.google.client_id'),
            'client_secret' => config('sole_auth.google.client_secret'),
            'redirect_uri' => config('sole_auth.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        $accessToken = is_array($token) ? ($token['access_token'] ?? null) : null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new UnexpectedValueException('Google token response did not contain an access token.');
        }

        $identity = $this->http()
            ->withToken($accessToken)
            ->get(self::USERINFO_URL)
            ->throw()
            ->json();

        if (
            ! is_array($identity)
            || ! is_string($identity['sub'] ?? null)
            || ! is_string($identity['email'] ?? null)
            || ($identity['email_verified'] ?? false) !== true
        ) {
            throw new UnexpectedValueException('Google returned an unverified or incomplete identity.');
        }

        return [
            'sub' => $identity['sub'],
            'email' => mb_strtolower(trim($identity['email'])),
            'email_verified' => true,
            'name' => is_string($identity['name'] ?? null) ? trim($identity['name']) : null,
            'picture' => is_string($identity['picture'] ?? null) ? $identity['picture'] : null,
        ];
    }

    public function frontendRedirect(string $path): string
    {
        $frontend = rtrim((string) config('sole_auth.frontend_url'), '/');

        return $frontend.$this->normalizeReturnPath($path);
    }

    public function normalizeReturnPath(?string $path): string
    {
        $path = is_string($path) ? trim($path) : '';

        if (
            $path === ''
            || ! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || strlen($path) > 512
        ) {
            return '/account';
        }

        return $path;
    }

    private function assertConfigured(): void
    {
        if (! config('sole_auth.google.enabled')) {
            throw new ServiceUnavailableHttpException(null, 'Google authentication is disabled.');
        }

        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (trim((string) config("sole_auth.google.{$key}")) === '') {
                throw new ServiceUnavailableHttpException(null, 'Google authentication is not configured.');
            }
        }

        if (config('app.env') !== 'production') {
            return;
        }

        $httpsValues = [
            (string) config('app.url'),
            (string) config('sole_auth.frontend_url'),
            (string) config('sole_auth.google.redirect_uri'),
        ];

        if (
            collect($httpsValues)->contains(fn (string $url): bool => ! str_starts_with($url, 'https://'))
            || config('session.secure') !== true
            || config('session.http_only') !== true
            || ! in_array(config('session.same_site'), ['lax', 'strict'], true)
        ) {
            throw new ServiceUnavailableHttpException(null, 'Google authentication is not safely configured for production.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(10)->retry(2, 150, throw: false);
    }
}
