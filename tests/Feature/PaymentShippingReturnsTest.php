<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\BusinessSetting;
use App\Models\CustomerAddress;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentShippingReturnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commerce.payment.provider', 'zarinpal');
        config()->set('commerce.payment.callback_url', 'https://sole.example.test/payment/callback');
        config()->set('commerce.payment.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('commerce.shipping.provider', 'configured');
        config()->set('commerce.shipping.webhook_secret', 'p07-test-webhook-secret');
        Http::preventStrayRequests();
    }

    public function test_verified_payment_fulfillment_return_and_refund_request_are_idempotent_and_auditable(): void
    {
        [$user, $address, $cartToken, $balance, $order] = $this->checkoutOrder(quantity: 2, stock: 3);

        Http::fake([
            'https://api.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'A000000000000000000000000000000001'],
                'errors' => [],
            ]),
            'https://api.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 987654321],
                'errors' => [],
            ]),
        ]);

        $paymentKey = (string) Str::uuid();
        $payment = $this->actingAs($user)
            ->withHeader('Idempotency-Key', $paymentKey)
            ->postJson("/api/v1/commerce/orders/{$order->public_id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.provider', 'zarinpal')
            ->assertJsonPath('data.amount_minor', 2_100_000)
            ->json('data');

        $this->withHeader('Idempotency-Key', $paymentKey)
            ->postJson("/api/v1/commerce/orders/{$order->public_id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.id', $payment['id']);
        $this->assertDatabaseCount('payment_attempts', 1);

        $this->postJson("/api/v1/commerce/payments/{$payment['id']}/verify", [
            'authority' => 'A000000000000000000000000000000001',
            'status' => 'OK',
        ])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.reference_id', '987654321');

        $this->postJson("/api/v1/commerce/payments/{$payment['id']}/verify", [
            'authority' => 'A000000000000000000000000000000001',
            'status' => 'OK',
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'reason' => 'payment_verified']);
        $this->assertSame(1, $order->events()->where('reason', 'payment_verified')->count());
        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'on_hand' => 3, 'reserved' => 2]);

        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
        $this->signedShipmentEvent($shipment, 'ready-1', 'ready');
        $this->signedShipmentEvent($shipment, 'shipped-1', 'shipped', 'TRACK-P07-1');

        $this->assertDatabaseHas('inventory_balances', ['id' => $balance->id, 'on_hand' => 1, 'reserved' => 0]);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'status' => 'committed']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'processing']);

        $this->signedShipmentEvent($shipment->refresh(), 'delivered-1', 'delivered', 'TRACK-P07-1');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'fulfilled']);

        $this->postJson("/api/v1/commerce/orders/{$order->public_id}/returns", [
            'reason' => 'size_or_fit',
        ])->assertCreated()->assertJsonPath('data.status', 'requested');
        $this->assertDatabaseCount('return_requests', 1);

        $refundKey = (string) Str::uuid();
        $refund = $this->withHeader('Idempotency-Key', $refundKey)
            ->postJson("/api/v1/commerce/orders/{$order->public_id}/refunds", [
                'reason' => 'approved_return',
            ])->assertCreated()
            ->assertJsonPath('data.amount_minor', 2_100_000)
            ->json('data');

        $this->withHeader('Idempotency-Key', $refundKey)
            ->postJson("/api/v1/commerce/orders/{$order->public_id}/refunds", ['reason' => 'approved_return'])
            ->assertCreated()
            ->assertJsonPath('data.id', $refund['id']);
        $this->assertDatabaseCount('refund_requests', 1);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/commerce/orders/{$order->public_id}/refunds", ['reason' => 'approved_return'])
            ->assertStatus(409);

        $this->getJson("/api/v1/commerce/orders/{$order->public_id}")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonPath('data.shipment.status', 'delivered')
            ->assertJsonPath('data.return.status', 'requested')
            ->assertJsonPath('data.refunds.0.status', 'requested');

        Http::assertSentCount(2);
        $this->assertNotNull($address);
        $this->assertNotNull($cartToken);
    }

    public function test_payment_reconciliation_fails_closed_when_provider_reports_already_verified_without_local_capture(): void
    {
        [$user, , , , $order] = $this->checkoutOrder();

        Http::fake([
            'https://api.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'A000000000000000000000000000000002'],
            ]),
            'https://api.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => ['code' => 101],
            ]),
        ]);

        $payment = $this->actingAs($user)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/commerce/orders/{$order->public_id}/payments")
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/v1/commerce/payments/{$payment['id']}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'awaiting_payment']);
        $this->assertDatabaseHas('payment_reconciliations', ['order_id' => $order->id, 'observed_status' => 'unknown']);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_payment_is_disabled_by_default_and_shipping_webhook_requires_valid_signature(): void
    {
        [$user, , , , $order] = $this->checkoutOrder();
        config()->set('commerce.payment.provider', 'disabled');
        $this->app->forgetInstance(PaymentGateway::class);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/commerce/orders/{$order->public_id}/payments")
            ->assertStatus(503);

        $shipment = Shipment::query()->create([
            'public_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'provider' => 'configured',
            'service_code' => 'standard',
            'status' => 'pending',
        ]);

        $payload = json_encode([
            'shipment_id' => $shipment->public_id,
            'event_id' => 'bad-signature-event',
            'status' => 'ready',
            'reason' => 'provider_update',
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/commerce/shipping/provider-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SOLE_SIGNATURE' => 'invalid',
        ], $payload)->assertStatus(401);
        $this->assertDatabaseMissing('shipment_events', ['event_key' => 'bad-signature-event']);
    }

    /** @return array{User, CustomerAddress, string, InventoryBalance, Order} */
    private function checkoutOrder(int $quantity = 2, int $stock = 3): array
    {
        $user = User::factory()->create();
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create(['price_minor' => 1_000_000, 'currency' => 'IRR']);
        $location = InventoryLocation::factory()->create();
        app(InventoryLedger::class)->adjust($variant, $location, $stock, 'P07 stock');
        $balance = InventoryBalance::query()->firstOrFail();
        $address = $user->addresses()->create([
            'recipient_name' => 'SOLE P07 Customer',
            'phone_e164' => '+989121234567',
            'country_code' => 'IR',
            'province' => 'Tehran',
            'city' => 'Tehran',
            'postal_code' => '1234567890',
            'address_line1' => 'P07 address',
            'is_default' => true,
        ]);
        BusinessSetting::query()->create([
            'key' => 'checkout_policy',
            'value' => ['allowed_country_codes' => ['IR'], 'reservation_minutes' => 15],
        ]);
        BusinessSetting::query()->create([
            'key' => 'shipping_provider_policy',
            'value' => [
                'quote_ttl_minutes' => 15,
                'services' => [[
                    'code' => 'standard',
                    'label' => 'Standard shipping',
                    'currency' => 'IRR',
                    'amount_minor' => 100_000,
                    'allowed_country_codes' => ['IR'],
                    'eta_min_days' => 2,
                    'eta_max_days' => 4,
                ]],
            ],
        ]);

        $cartToken = $this->actingAs($user)->getJson('/api/v1/commerce/cart')->headers->get('X-Sole-Cart');
        $this->withHeader('X-Sole-Cart', $cartToken)
            ->putJson("/api/v1/commerce/cart/items/{$variant->id}", ['quantity' => $quantity])
            ->assertOk();
        $quote = $this->withHeader('X-Sole-Cart', $cartToken)
            ->postJson('/api/v1/commerce/shipping/quotes', ['address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('data.0.amount_minor', 100_000)
            ->json('data.0');

        $orderPayload = $this->withHeaders([
            'X-Sole-Cart' => $cartToken,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/commerce/checkout', [
            'address_id' => $address->id,
            'shipping_quote_id' => $quote['id'],
        ])->assertCreated()->json('data');

        return [$user, $address, $cartToken, $balance, Order::query()->where('public_id', $orderPayload['id'])->firstOrFail()];
    }

    private function signedShipmentEvent(Shipment $shipment, string $eventId, string $status, ?string $tracking = null): void
    {
        $body = [
            'shipment_id' => $shipment->public_id,
            'event_id' => $eventId,
            'status' => $status,
            'reason' => 'provider_update',
        ];
        if ($tracking !== null) {
            $body['tracking_number'] = $tracking;
        }
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, 'p07-test-webhook-secret');

        $this->call('POST', '/api/v1/commerce/shipping/provider-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SOLE_SIGNATURE' => $signature,
        ], $payload)->assertOk()->assertJsonPath('data.status', $status);
    }
}
