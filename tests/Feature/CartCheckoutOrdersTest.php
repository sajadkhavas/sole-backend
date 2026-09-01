<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CustomerAddress;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CartCheckoutOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_is_capability_scoped_and_uses_authoritative_price_and_inventory(): void
    {
        [$variant] = $this->sellableVariant(stock: 2, priceMinor: 1_000_000);

        $cartResponse = $this->getJson('/api/v1/commerce/cart')->assertOk();
        $cartToken = $cartResponse->headers->get('X-Sole-Cart');

        $this->assertNotNull($cartToken);
        $this->withHeader('X-Sole-Cart', $cartToken)
            ->putJson("/api/v1/commerce/cart/items/{$variant->id}", ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price_minor', 1_000_000)
            ->assertJsonPath('data.items.0.available_quantity', 2)
            ->assertJsonPath('data.summary.subtotal_minor', 2_000_000)
            ->assertJsonPath('data.summary.checkout_ready', true);

        $this->withHeader('X-Sole-Cart', $cartToken)
            ->putJson("/api/v1/commerce/cart/items/{$variant->id}", ['quantity' => 3])
            ->assertStatus(409);
    }

    public function test_checkout_is_idempotent_reserves_inventory_and_creates_durable_order(): void
    {
        $user = User::factory()->create();
        [$variant, $balance] = $this->sellableVariant(stock: 3, priceMinor: 1_000_000);
        $address = $this->address($user);
        $this->checkoutPolicy();

        $cartToken = $this->actingAs($user)->getJson('/api/v1/commerce/cart')->headers->get('X-Sole-Cart');
        $this->withHeader('X-Sole-Cart', $cartToken)
            ->putJson("/api/v1/commerce/cart/items/{$variant->id}", ['quantity' => 2])
            ->assertOk();

        $idempotencyKey = (string) Str::uuid();
        $headers = ['X-Sole-Cart' => $cartToken, 'Idempotency-Key' => $idempotencyKey];
        $first = $this->withHeaders($headers)->postJson('/api/v1/commerce/checkout', ['address_id' => $address->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.subtotal_minor', 2_000_000)
            ->assertJsonPath('data.shipping_minor', 100_000)
            ->assertJsonPath('data.total_minor', 2_100_000);
        $orderId = $first->json('data.id');

        $this->withHeaders($headers)->postJson('/api/v1/commerce/checkout', ['address_id' => $address->id])
            ->assertCreated()
            ->assertJsonPath('data.id', $orderId);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('checkout_attempts', 1);
        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'on_hand' => 3, 'reserved' => 2]);
        $this->assertDatabaseHas('order_events', ['from_status' => null, 'to_status' => 'awaiting_payment']);

        $this->getJson('/api/v1/commerce/orders')->assertOk()->assertJsonPath('data.0.id', $orderId);
        $this->getJson("/api/v1/commerce/orders/{$orderId}")->assertOk()->assertJsonPath('data.items.0.quantity', 2);
    }

    public function test_checkout_fails_closed_without_authoritative_policy_and_protects_order_ownership(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$variant] = $this->sellableVariant();
        $address = $this->address($user);
        $cartToken = $this->actingAs($user)->getJson('/api/v1/commerce/cart')->headers->get('X-Sole-Cart');
        $this->withHeader('X-Sole-Cart', $cartToken)->putJson("/api/v1/commerce/cart/items/{$variant->id}", ['quantity' => 1]);

        $this->withHeaders(['X-Sole-Cart' => $cartToken, 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/commerce/checkout', ['address_id' => $address->id])
            ->assertStatus(503);

        $order = Order::factory()->for($user)->create();
        $this->actingAs($other)->getJson("/api/v1/commerce/orders/{$order->public_id}")->assertNotFound();
    }

    public function test_expiry_releases_inventory_and_appends_state_event(): void
    {
        $user = User::factory()->create();
        [$variant, $balance] = $this->sellableVariant(stock: 1);
        $address = $this->address($user);
        $this->checkoutPolicy();
        $cartToken = $this->actingAs($user)->getJson('/api/v1/commerce/cart')->headers->get('X-Sole-Cart');
        $this->withHeader('X-Sole-Cart', $cartToken)->putJson("/api/v1/commerce/cart/items/{$variant->id}", ['quantity' => 1]);
        $this->withHeaders(['X-Sole-Cart' => $cartToken, 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/commerce/checkout', ['address_id' => $address->id])
            ->assertCreated();

        Order::query()->update(['reservation_expires_at' => now()->subMinute()]);
        $this->artisan('sole:orders:expire')->assertSuccessful();

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'reserved' => 0]);
        $this->assertDatabaseHas('orders', ['status' => 'expired']);
        $this->assertDatabaseHas('order_events', ['from_status' => 'awaiting_payment', 'to_status' => 'expired']);
    }

    public function test_order_status_and_events_are_not_directly_rewritable(): void
    {
        $order = Order::factory()->create();

        try {
            $order->forceFill(['status' => 'fulfilled'])->save();
            $this->fail('Direct order status mutation should fail.');
        } catch (LogicException) {
            $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'awaiting_payment']);
        }

        $event = $order->events()->create([
            'to_status' => 'awaiting_payment',
            'reason' => 'test',
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $event->forceFill(['reason' => 'rewritten'])->save();
    }

    /** @return array{ProductVariant, InventoryBalance} */
    private function sellableVariant(int $stock = 5, int $priceMinor = 1_000_000): array
    {
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create(['price_minor' => $priceMinor, 'currency' => 'IRR']);
        $location = InventoryLocation::factory()->create();
        app(InventoryLedger::class)->adjust($variant, $location, $stock, 'Test stock');

        return [$variant, InventoryBalance::query()->firstOrFail()];
    }

    private function address(User $user): CustomerAddress
    {
        return CustomerAddress::query()->create([
            'user_id' => $user->id,
            'recipient_name' => 'SOLE Customer',
            'phone_e164' => '+989121234567',
            'country_code' => 'IR',
            'province' => 'Tehran',
            'city' => 'Tehran',
            'postal_code' => '1234567890',
            'address_line1' => 'Test address line',
            'is_default' => true,
        ]);
    }

    private function checkoutPolicy(): void
    {
        BusinessSetting::query()->create([
            'key' => 'checkout_policy',
            'value' => [
                'allowed_country_codes' => ['IR'],
                'shipping_minor' => 100_000,
                'free_shipping_threshold_minor' => 5_000_000,
                'reservation_minutes' => 15,
            ],
        ]);
    }
}
