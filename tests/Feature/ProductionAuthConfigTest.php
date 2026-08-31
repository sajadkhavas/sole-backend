<?php

namespace Tests\Feature;

use App\Services\Auth\GoogleOAuthService;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class ProductionAuthConfigTest extends TestCase
{
    public function test_google_auth_rejects_insecure_production_session_contract(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.url' => 'http://api.example.test',
            'sole_auth.frontend_url' => 'http://example.test',
            'sole_auth.google.enabled' => true,
            'sole_auth.google.client_id' => 'client',
            'sole_auth.google.client_secret' => 'secret',
            'sole_auth.google.redirect_uri' => 'http://api.example.test/auth/google/callback',
            'session.secure' => false,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);

        $this->expectException(ServiceUnavailableHttpException::class);

        app(GoogleOAuthService::class)->authorizationUrl('state');
    }

    public function test_google_auth_accepts_https_secure_production_session_contract(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.url' => 'https://api.example.test',
            'sole_auth.frontend_url' => 'https://example.test',
            'sole_auth.google.enabled' => true,
            'sole_auth.google.client_id' => 'client',
            'sole_auth.google.client_secret' => 'secret',
            'sole_auth.google.redirect_uri' => 'https://api.example.test/auth/google/callback',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);

        $url = app(GoogleOAuthService::class)->authorizationUrl('state');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('state=state', $url);
    }
}
