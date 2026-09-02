<?php

namespace Tests\Feature;

use App\Models\BackInStockIntent;
use App\Models\CustomerWishlistItem;
use App\Models\InventoryLocation;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationPreference;
use App\Models\NotificationSignal;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Engagement\LoyaltyLedgerService;
use App\Services\Engagement\NotificationOrchestrator;
use App\Services\InventoryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LoyaltyCrmNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_wishlist_is_authenticated_owner_scoped_and_local_migration_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->published()->create();
        $first = ProductVariant::factory()->for($product)->create(['price_minor' => 2_000_000]);
        $second = ProductVariant::factory()->for($product)->create(['price_minor' => 2_500_000]);

        $this->getJson('/api/v1/customer/wishlist')->assertUnauthorized();

        $this->actingAs($owner)
            ->postJson('/api/v1/customer/wishlist/migrate', ['variant_ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('accepted_variant_ids.0', $first->id);

        $this->actingAs($owner)
            ->postJson('/api/v1/customer/wishlist/migrate', ['variant_ids' => [$first->id, $second->id]])
            ->assertOk();
        $this->assertDatabaseCount('customer_wishlist_items', 2);
        $this->assertDatabaseHas('customer_wishlist_items', [
            'user_id' => $owner->id,
            'product_variant_id' => $first->id,
            'price_anchor_minor' => 2_000_000,
        ]);

        $this->actingAs($other)->deleteJson("/api/v1/customer/wishlist/{$first->id}")->assertNotFound();
        $this->assertDatabaseHas('customer_wishlist_items', [
            'user_id' => $owner->id,
            'product_variant_id' => $first->id,
        ]);

        $this->actingAs($other)
            ->putJson("/api/v1/customer/wishlist/{$first->id}")
            ->assertCreated()
            ->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('customer_wishlist_items', 3);
    }

    public function test_notification_preferences_require_explicit_owner_mutation_and_unsubscribe_fails_closed(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)
            ->putJson('/api/v1/customer/notification-preferences/email', [
                'enabled' => true,
                'daily_cap' => 2,
                'quiet_start' => '22:00',
                'quiet_end' => '07:00',
                'timezone' => 'Asia/Tehran',
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.daily_cap', 2);

        $this->actingAs($other)
            ->getJson('/api/v1/customer/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.0.enabled', false);

        $this->actingAs($owner)
            ->deleteJson('/api/v1/customer/notification-preferences/email')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $owner->id,
            'channel' => 'email',
            'enabled' => false,
        ]);
    }

    public function test_price_drop_signals_are_derived_only_from_backend_price_truth_and_are_idempotent(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create(['price_minor' => 3_000_000]);
        CustomerWishlistItem::query()->create([
            'user_id' => $user->id,
            'product_variant_id' => $variant->id,
            'price_anchor_minor' => 3_000_000,
        ]);

        $orchestrator = app(NotificationOrchestrator::class);
        $this->assertSame(0, $orchestrator->scan()['price_drop']);

        $variant->update(['price_minor' => 2_400_000]);
        $this->assertSame(1, $orchestrator->scan()['price_drop']);
        $this->assertSame(0, $orchestrator->scan()['price_drop']);

        $signal = NotificationSignal::query()->where('type', 'price_drop')->firstOrFail();
        $this->assertSame($user->id, $signal->user_id);
        $this->assertSame(3_000_000, $signal->facts['previous_price_minor']);
        $this->assertSame(2_400_000, $signal->facts['current_price_minor']);
        $this->assertSame(2_400_000, CustomerWishlistItem::query()->firstOrFail()->price_anchor_minor);
    }

    public function test_back_in_stock_signal_uses_inventory_and_explicit_consent_and_unsubscribe_revokes_it(): void
    {
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create();
        $location = InventoryLocation::factory()->create();

        $registration = $this->postJson("/api/v1/catalog/products/{$product->slug}/back-in-stock", [
            'variant_id' => $variant->id,
            'email' => 'person@example.com',
            'consent' => true,
            'consent_version' => 'p05-v1',
        ])->assertCreated();

        $intentId = $registration->json('intent_id');
        $token = $registration->json('unsubscribe_token');
        $this->assertIsInt($intentId);
        $this->assertIsString($token);

        app(InventoryLedger::class)->adjust($variant, $location, 1, 'P09 test restock');
        $orchestrator = app(NotificationOrchestrator::class);
        $this->assertSame(1, $orchestrator->scan()['back_in_stock']);
        $this->assertSame(0, $orchestrator->scan()['back_in_stock']);
        $this->assertDatabaseHas('notification_signals', [
            'type' => 'back_in_stock',
            'source_type' => 'back_in_stock_intent',
            'source_id' => $intentId,
        ]);

        $this->deleteJson("/api/v1/catalog/back-in-stock/{$intentId}", ['token' => str_repeat('x', 64)])
            ->assertNotFound();
        $this->deleteJson("/api/v1/catalog/back-in-stock/{$intentId}", ['token' => $token])
            ->assertNoContent();
        $this->assertNotNull(BackInStockIntent::query()->findOrFail($intentId)->unsubscribed_at);
    }

    public function test_order_lifecycle_signals_are_materialized_from_append_only_order_events(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        $event = OrderEvent::query()->create([
            'order_id' => $order->id,
            'actor_id' => null,
            'from_status' => 'awaiting_payment',
            'to_status' => 'paid',
            'reason' => 'provider_verified',
            'metadata' => null,
            'created_at' => now(),
        ]);

        $orchestrator = app(NotificationOrchestrator::class);
        $this->assertSame(1, $orchestrator->scan()['order_lifecycle']);
        $this->assertSame(0, $orchestrator->scan()['order_lifecycle']);
        $this->assertDatabaseHas('notification_signals', [
            'user_id' => $user->id,
            'type' => 'order_lifecycle',
            'source_type' => 'order_event',
            'source_id' => $event->id,
        ]);
    }

    public function test_delivery_audit_blocks_missing_preference_quiet_hours_frequency_cap_and_unconfigured_adapter_without_claiming_delivery(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'));
        $user = User::factory()->create();
        $orchestrator = app(NotificationOrchestrator::class);

        $missing = $this->signal($user, 'missing');
        $orchestrator->dispatchPending();
        $this->assertDatabaseHas('notification_delivery_attempts', [
            'notification_signal_id' => $missing->id,
            'status' => 'blocked',
            'reason' => 'preference_missing',
        ]);

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'channel' => 'email'],
            [
                'enabled' => true,
                'daily_cap' => 1,
                'quiet_start' => '11:00',
                'quiet_end' => '13:00',
                'timezone' => 'UTC',
            ],
        );
        $quiet = $this->signal($user, 'quiet');
        $orchestrator->dispatchPending();
        $this->assertDatabaseHas('notification_delivery_attempts', [
            'notification_signal_id' => $quiet->id,
            'reason' => 'quiet_hours',
        ]);
        $this->assertSame('pending', $quiet->refresh()->status);

        NotificationPreference::query()->where('user_id', $user->id)->where('channel', 'email')->update([
            'quiet_start' => null,
            'quiet_end' => null,
        ]);
        $capSource = $this->signal($user, 'cap-source');
        NotificationDeliveryAttempt::query()->create([
            'notification_signal_id' => $capSource->id,
            'attempt_key' => 'test-sent-'.Str::uuid(),
            'channel' => 'email',
            'provider' => 'test-provider',
            'status' => 'sent',
            'reason' => 'test_evidence',
            'response_hash' => hash('sha256', 'provider-evidence'),
            'attempted_at' => now(),
        ]);
        $cap = $this->signal($user, 'cap');
        $orchestrator->dispatchPending();
        $this->assertDatabaseHas('notification_delivery_attempts', [
            'notification_signal_id' => $cap->id,
            'reason' => 'frequency_cap',
        ]);

        NotificationPreference::query()->where('user_id', $user->id)->where('channel', 'email')->update(['daily_cap' => 20]);
        $adapter = $this->signal($user, 'adapter');
        $orchestrator->dispatchPending();
        $this->assertDatabaseHas('notification_delivery_attempts', [
            'notification_signal_id' => $adapter->id,
            'provider' => null,
            'status' => 'blocked',
            'reason' => 'adapter_unconfigured',
        ]);
        $this->assertSame('blocked', $adapter->refresh()->status);
    }

    public function test_notification_signals_are_owner_scoped_in_customer_api(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->signal($owner, 'owner');
        $this->signal($other, 'other');

        $response = $this->actingAs($owner)->getJson('/api/v1/customer/notification-signals')->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('owner', $response->json('data.0.facts.key'));
    }

    public function test_loyalty_ledger_is_append_only_idempotent_prevents_overspend_and_expires_without_negative_balance(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'));
        $user = User::factory()->create();
        $ledger = app(LoyaltyLedgerService::class);

        $earn = $ledger->earn($user, 100, 'earn-1', 'qa_earn', expiresAt: now()->subMinute());
        $duplicate = $ledger->earn($user, 100, 'earn-1', 'qa_earn', expiresAt: now()->subMinute());
        $this->assertSame($earn->id, $duplicate->id);
        $this->assertSame(100, $ledger->balance($user));

        $redeem = $ledger->redeem($user, 60, 'redeem-1', 'qa_redeem');
        $this->assertSame(-60, $redeem->points_delta);
        $this->assertSame(40, $ledger->balance($user));

        try {
            $ledger->redeem($user, 41, 'redeem-over', 'qa_redeem');
            $this->fail('Overspend must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Insufficient loyalty balance.', $exception->getMessage());
        }
        $this->assertSame(40, $ledger->balance($user));

        $expired = $ledger->expireEarn($earn);
        $this->assertNotNull($expired);
        $this->assertSame(-40, $expired->points_delta);
        $this->assertSame(0, $ledger->balance($user));
        $this->assertSame($expired->id, $ledger->expireEarn($earn)?->id);

        $this->expectException(RuntimeException::class);
        $earn->forceFill(['reason' => 'tamper'])->save();
    }

    public function test_loyalty_release_restores_balance_once_and_customer_api_exposes_truthful_non_cash_terms(): void
    {
        $user = User::factory()->create();
        $ledger = app(LoyaltyLedgerService::class);
        $ledger->earn($user, 120, 'earn-2', 'qa_earn');
        $ledger->redeem($user, 50, 'redeem-2', 'qa_redeem');
        $release = $ledger->release($user, 50, 'release-2', 'qa_release');
        $this->assertSame($release->id, $ledger->release($user, 50, 'release-2', 'qa_release')->id);
        $this->assertSame(120, $ledger->balance($user));

        $this->actingAs($user)
            ->getJson('/api/v1/customer/loyalty')
            ->assertOk()
            ->assertJsonPath('data.balance', 120)
            ->assertJsonPath('data.terms.cash_value', false)
            ->assertJsonPath('data.terms.server_authoritative', true)
            ->assertJsonPath('data.terms.earning_rate_published', false);
    }

    private function signal(User $user, string $key): NotificationSignal
    {
        return NotificationSignal::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'product_variant_id' => null,
            'type' => 'order_lifecycle',
            'source_type' => 'qa',
            'source_id' => random_int(1, 1_000_000_000),
            'idempotency_key' => 'qa:'.$key.':'.Str::uuid(),
            'facts' => ['key' => $key],
            'status' => 'pending',
            'eligible_at' => now(),
        ]);
    }
}
