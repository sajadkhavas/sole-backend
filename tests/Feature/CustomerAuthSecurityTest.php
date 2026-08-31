<?php

namespace Tests\Feature;

use App\Contracts\OtpSender;
use App\Models\AccountLifecycleRequest;
use App\Models\AuthIdentity;
use App\Models\ConsentRecord;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'sole_auth.frontend_url' => 'http://localhost:5173',
            'sole_auth.google.enabled' => true,
            'sole_auth.google.client_id' => 'google-client',
            'sole_auth.google.client_secret' => 'google-secret',
            'sole_auth.google.redirect_uri' => 'http://localhost/auth/google/callback',
            'sole_auth.otp.enabled' => false,
        ]);
    }

    public function test_google_login_requires_state_and_creates_social_customer_without_storing_tokens(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-subject-1',
                'email' => 'customer@example.com',
                'email_verified' => true,
                'name' => 'SOLE Customer',
                'picture' => 'https://example.test/avatar.png',
            ]),
        ]);

        $response = $this->withSession([
            'google_oauth_state' => 'trusted-state',
            'google_return_to' => '/account',
        ])->get('/auth/google/callback?code=trusted-code&state=trusted-state');

        $response->assertRedirect('http://localhost:5173/auth?complete=phone');
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'customer@example.com')->firstOrFail();
        $this->assertSame('active', $user->account_status);
        $this->assertNotNull($user->email_verified_at);

        $identity = AuthIdentity::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('google', $identity->provider);
        $this->assertSame('google-subject-1', $identity->provider_subject);
        $this->assertArrayNotHasKey('access_token', $identity->metadata ?? []);
        $this->assertArrayNotHasKey('refresh_token', $identity->metadata ?? []);
    }

    public function test_google_callback_rejects_state_mismatch_before_exchanging_code(): void
    {
        Http::fake();

        $this->withSession(['google_oauth_state' => 'expected-state'])
            ->get('/auth/google/callback?code=trusted-code&state=wrong-state')
            ->assertStatus(419);

        Http::assertNothingSent();
    }

    public function test_customer_google_login_cannot_link_to_privileged_admin_identity(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com', 'is_active' => true]);
        $admin->roles()->create(['name' => 'Administrator', 'slug' => 'test-admin']);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'admin-google-subject',
                'email' => 'admin@example.com',
                'email_verified' => true,
                'name' => 'Admin',
            ]),
        ]);

        $this->withSession([
            'google_oauth_state' => 'trusted-state',
            'google_return_to' => '/account',
        ])->get('/auth/google/callback?code=trusted-code&state=trusted-state')
            ->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseMissing('auth_identities', ['provider_subject' => 'admin-google-subject']);
    }

    public function test_customer_profile_requires_and_normalizes_mobile_without_claiming_verification(): void
    {
        $user = User::factory()->create(['account_status' => 'active']);

        $this->actingAs($user)
            ->putJson('/api/v1/customer', [
                'name' => 'Customer',
                'phone' => '09121234567',
                'locale' => 'fa-IR',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone_e164', '+989121234567')
            ->assertJsonPath('data.phone_verified_at', null)
            ->assertJsonPath('data.account_complete', true);

        $this->assertDatabaseHas('customer_profiles', [
            'user_id' => $user->id,
            'phone_e164' => '+989121234567',
            'phone_verified_at' => null,
        ]);
    }

    public function test_otp_is_not_exposed_when_feature_flag_is_disabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/customer/otp', ['phone' => '09121234567'])
            ->assertNotFound();
    }

    public function test_otp_is_single_use_and_marks_phone_verified_when_enabled(): void
    {
        config()->set('sole_auth.otp.enabled', true);

        $sender = new class implements OtpSender
        {
            public ?string $code = null;

            public function send(string $phoneE164, string $code): void
            {
                $this->code = $code;
            }
        };

        $this->app->instance(OtpSender::class, $sender);

        $user = User::factory()->create();
        CustomerProfile::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+989121234567',
        ]);

        $request = $this->actingAs($user)
            ->postJson('/api/v1/customer/otp', ['phone' => '09121234567'])
            ->assertStatus(202);

        $challenge = (string) $request->json('data.id');
        $this->assertNotNull($sender->code);

        $this->actingAs($user)
            ->postJson('/api/v1/customer/otp/verify', [
                'challenge_id' => $challenge,
                'code' => $sender->code,
            ])
            ->assertOk()
            ->assertJsonPath('data.phone_e164', '+989121234567');

        $this->assertNotNull(CustomerProfile::query()->where('user_id', $user->id)->value('phone_verified_at'));

        $this->actingAs($user)
            ->postJson('/api/v1/customer/otp/verify', [
                'challenge_id' => $challenge,
                'code' => $sender->code,
            ])
            ->assertUnprocessable();
    }

    public function test_address_ownership_is_enforced(): void
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $other = User::factory()->create(['account_status' => 'active']);

        $address = CustomerAddress::query()->create([
            'user_id' => $other->id,
            'recipient_name' => 'Other',
            'phone_e164' => '+989121234568',
            'country_code' => 'IR',
            'province' => 'Tehran',
            'city' => 'Tehran',
            'address_line1' => 'Private address',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/customer/addresses/'.$address->id, [
                'recipient_name' => 'Hijack',
                'phone' => '09121234567',
                'country_code' => 'IR',
                'province' => 'Alborz',
                'city' => 'Karaj',
                'address_line1' => 'No',
                'is_default' => false,
            ])
            ->assertNotFound();
    }

    public function test_consent_history_is_append_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/customer/consents', [
                'type' => 'privacy',
                'granted' => true,
                'policy_version' => '2026-08-31',
            ])
            ->assertCreated();

        $consent = ConsentRecord::query()->findOrFail($response->json('data.id'));

        $this->expectException(\LogicException::class);
        $consent->update(['granted' => false]);
    }

    public function test_account_export_and_deletion_request_can_be_cancelled_before_fulfillment(): void
    {
        $user = User::factory()->create(['account_status' => 'active']);
        CustomerProfile::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+989121234567',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/customer/export')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="sole-account-export.json"')
            ->assertJsonPath('data.account.email', $user->email);

        $this->actingAs($user)
            ->postJson('/api/v1/customer/deletion')
            ->assertStatus(202);

        $this->assertSame('deletion_requested', $user->fresh()->account_status);
        $this->assertDatabaseHas('account_lifecycle_requests', [
            'user_id' => $user->id,
            'type' => 'deletion',
            'status' => 'requested',
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/customer/deletion')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('active', $user->fresh()->account_status);
        $this->assertDatabaseHas('account_lifecycle_requests', [
            'user_id' => $user->id,
            'type' => 'deletion',
            'status' => 'cancelled',
        ]);
    }

    public function test_operator_can_fulfill_pending_deletion_without_retaining_social_profile_rows(): void
    {
        $user = User::factory()->create(['account_status' => 'deletion_requested']);
        $profile = CustomerProfile::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+989121234567',
        ]);
        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => 'subject-delete',
            'email_at_link' => $user->email,
        ]);

        $request = AccountLifecycleRequest::query()->create([
            'user_id' => $user->id,
            'type' => 'deletion',
            'status' => 'requested',
            'requested_at' => now(),
        ]);

        $this->artisan('sole:account:fulfill-deletion', ['request' => $request->id])->assertSuccessful();

        $deleted = $user->fresh();
        $this->assertSame('deleted', $deleted->account_status);
        $this->assertSame('Deleted customer', $deleted->name);
        $this->assertStringEndsWith('@privacy.invalid', $deleted->email);
        $this->assertDatabaseMissing('customer_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('auth_identities', ['provider_subject' => 'subject-delete']);
        $this->assertDatabaseHas('account_lifecycle_requests', [
            'id' => $request->id,
            'status' => 'completed',
        ]);
    }

    public function test_production_google_auth_fails_closed_without_https_secure_session_contract(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.url' => 'http://api.example.test',
            'sole_auth.frontend_url' => 'http://example.test',
            'sole_auth.google.redirect_uri' => 'http://api.example.test/auth/google/callback',
            'session.secure' => false,
        ]);

        $this->get('/auth/google/redirect')->assertStatus(503);
    }
}
