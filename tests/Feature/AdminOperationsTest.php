<?php

namespace Tests\Feature;

use App\Models\LoyaltyLedgerEntry;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationSignal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\RefundRequest;
use App\Models\ReturnRequest;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\SupportCase;
use App\Models\User;
use App\Services\Operations\AdminOperationsService;
use App\Services\RbacProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_role_is_least_privilege_and_auditor_is_read_only(): void
    {
        app(RbacProvisioner::class)->sync();
        $operator = $this->adminWithRole('operations-manager');
        $auditor = $this->adminWithRole('auditor');
        $customer = User::factory()->create();
        $order = Order::factory()->for($customer)->create();

        $this->assertTrue(Gate::forUser($operator)->allows('viewAny', Order::class));
        $this->assertTrue(Gate::forUser($operator)->allows('update', $order));
        $this->assertFalse(Gate::forUser($operator)->allows('update', Product::factory()->create()));
        $this->assertTrue(Gate::forUser($auditor)->allows('viewAny', Order::class));
        $this->assertFalse(Gate::forUser($auditor)->allows('update', $order));
        $this->assertFalse(Gate::forUser($auditor)->allows('create', LoyaltyLedgerEntry::class));
    }

    public function test_guarded_operations_use_state_machines_locks_and_append_audit_evidence(): void
    {
        app(RbacProvisioner::class)->sync();
        $operator = $this->adminWithRole('operations-manager');
        $customer = User::factory()->create();
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create();
        $order = Order::factory()->for($customer)->create(['shipping_provider' => 'configured', 'shipping_service_code' => 'standard']);
        $item = OrderItem::factory()->for($order)->create(['product_variant_id' => $variant->id]);
        $service = app(AdminOperationsService::class);

        $this->actingAs($operator);
        $service->cancelOrder($order, 'customer confirmed cancellation');
        $this->assertSame('cancelled', $order->refresh()->status);

        $support = SupportCase::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $customer->id, 'order_id' => $order->id,
            'subject' => 'Help', 'category' => 'order', 'priority' => 'normal', 'status' => 'open',
        ]);
        $service->updateSupportCase($support, 'waiting_customer', 'high', 'Please confirm the requested detail.');
        $this->assertDatabaseHas('support_case_events', ['support_case_id' => $support->id, 'actor_id' => $operator->id, 'type' => 'admin_reply']);

        $review = ProductReview::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $customer->id, 'order_id' => $order->id,
            'order_item_id' => $item->id, 'product_variant_id' => $variant->id, 'rating' => 5,
            'title' => 'Verified', 'body' => 'Verified purchase review.', 'status' => 'pending',
        ]);
        $service->moderateReview($review, 'published', 'meets moderation policy');
        $this->assertSame('published', $review->refresh()->status);
        $this->assertSame($operator->id, $review->moderated_by);

        $entry = $service->adjustLoyalty($customer, 'credit', 25, 'case-25', 'service recovery');
        $this->assertSame(25, $entry->points_delta);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $operator->id, 'action' => 'operations.order.cancelled']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $operator->id, 'action' => 'operations.support.updated']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $operator->id, 'action' => 'operations.review.moderated']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $operator->id, 'action' => 'operations.loyalty.adjusted']);
    }

    public function test_fulfillment_return_and_refund_admin_transitions_preserve_immutable_money(): void
    {
        app(RbacProvisioner::class)->sync();
        $operator = $this->adminWithRole('operations-manager');
        $customer = User::factory()->create();
        $order = Order::factory()->for($customer)->create(['status' => 'paid', 'shipping_provider' => 'configured', 'shipping_service_code' => 'standard']);
        $shipment = Shipment::query()->create([
            'public_id' => (string) Str::uuid(), 'order_id' => $order->id, 'provider' => 'configured', 'service_code' => 'standard', 'status' => 'pending',
        ]);
        $return = ReturnRequest::query()->create([
            'public_id' => (string) Str::uuid(), 'order_id' => $order->id, 'user_id' => $customer->id,
            'status' => 'requested', 'reason' => 'damaged', 'requested_at' => now(),
        ]);
        $payment = PaymentAttempt::query()->create([
            'public_id' => (string) Str::uuid(), 'order_id' => $order->id, 'provider' => 'disabled',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'p13'),
            'currency' => 'IRR', 'amount_minor' => 1_100_000, 'status' => 'paid', 'started_at' => now(), 'verified_at' => now(),
        ]);
        $refund = RefundRequest::query()->create([
            'public_id' => (string) Str::uuid(), 'order_id' => $order->id, 'payment_attempt_id' => $payment->id,
            'user_id' => $customer->id, 'idempotency_key' => (string) Str::uuid(), 'amount_minor' => 1_100_000,
            'reason' => 'damaged', 'status' => 'requested', 'requested_at' => now(),
        ]);

        $this->actingAs($operator);
        $service = app(AdminOperationsService::class);
        $service->transitionShipment($shipment, 'ready', 'warehouse accepted fulfillment');
        $service->transitionReturn($return, 'approved', 'evidence reviewed');
        $service->transitionRefund($refund, 'manual_review', 'provider action requires review');

        $this->assertSame('ready', $shipment->refresh()->status);
        $this->assertSame('approved', $return->refresh()->status);
        $this->assertSame('manual_review', $refund->refresh()->status);
        $this->assertSame(1_100_000, $refund->amount_minor);
        $this->expectException(RuntimeException::class);
        $refund->forceFill(['amount_minor' => 1])->save();
    }

    public function test_operational_truth_models_reject_direct_state_or_evidence_rewrites(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->for($customer)->create();
        $support = SupportCase::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $customer->id, 'subject' => 'Help',
            'category' => 'other', 'priority' => 'normal', 'status' => 'open',
        ]);

        try {
            $support->forceFill(['status' => 'closed'])->save();
            $this->fail('Direct support state rewrite was accepted.');
        } catch (RuntimeException) {
            $this->assertSame('open', $support->refresh()->status);
        }

        $this->expectException(LogicException::class);
        $order->forceFill(['status' => 'fulfilled'])->save();
    }

    public function test_notification_delivery_evidence_is_visible_but_append_only(): void
    {
        app(RbacProvisioner::class)->sync();
        $operator = $this->adminWithRole('operations-manager');
        $signal = NotificationSignal::query()->create([
            'public_id' => (string) Str::uuid(), 'type' => 'order_lifecycle', 'source_type' => 'order',
            'source_id' => 1, 'idempotency_key' => 'p13-signal', 'facts' => [], 'status' => 'pending',
        ]);
        $attempt = NotificationDeliveryAttempt::query()->create([
            'notification_signal_id' => $signal->id, 'attempt_key' => 'p13-attempt', 'channel' => 'email',
            'provider' => null, 'status' => 'blocked', 'reason' => 'provider_disabled', 'attempted_at' => now(),
        ]);

        $this->assertTrue(Gate::forUser($operator)->allows('view', $attempt));
        $this->assertFalse(Gate::forUser($operator)->allows('update', $attempt));
        $this->expectException(RuntimeException::class);
        $attempt->forceFill(['status' => 'sent'])->save();
    }

    private function adminWithRole(string $slug): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::query()->where('slug', $slug)->firstOrFail());

        return $admin;
    }
}
