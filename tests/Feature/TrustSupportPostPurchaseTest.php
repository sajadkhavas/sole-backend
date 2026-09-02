<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductReview;
use App\Models\SupportCase;
use App\Models\TransactionalMessage;
use App\Models\TrustContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrustSupportPostPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_trust_content_fails_closed_without_complete_approval_provenance(): void
    {
        $approver = User::factory()->create();
        TrustContent::query()->create(['slug' => 'draft', 'kind' => 'faq', 'title' => 'Draft', 'body' => 'No', 'status' => 'draft']);
        TrustContent::query()->create(['slug' => 'incomplete', 'kind' => 'policy', 'title' => 'Incomplete', 'body' => 'No', 'status' => 'published', 'approved_by' => $approver->id, 'approved_at' => now()]);
        TrustContent::query()->create(['slug' => 'approved', 'kind' => 'faq', 'title' => 'Approved', 'body' => 'Yes', 'status' => 'published', 'approved_by' => $approver->id, 'approved_at' => now(), 'provenance_url' => 'https://example.test/source']);

        $this->getJson('/api/v1/trust/content')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'approved');
    }

    public function test_support_cases_enforce_order_and_case_ownership_and_do_not_invent_sla(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/support/cases', [
            'subject' => 'Where is my order?', 'category' => 'delivery', 'message' => 'Please check.', 'order_id' => $order->public_id,
        ])->assertCreated()->assertJsonPath('data.sla_due_at', null)->assertJsonPath('data.events.0.type', 'opened');
        $caseId = $response->json('data.id');

        $this->actingAs($other)->getJson("/api/v1/support/cases/{$caseId}")->assertNotFound();
        $this->actingAs($other)->postJson('/api/v1/support/cases', ['subject' => 'No', 'category' => 'order', 'message' => 'No', 'order_id' => $order->public_id])->assertNotFound();
        $this->assertDatabaseHas('transactional_messages', ['template' => 'support_case_opened', 'status' => 'pending']);
    }

    public function test_authoritative_support_policy_sets_disclosed_sla(): void
    {
        $owner = User::factory()->create();
        BusinessSetting::query()->create(['key' => 'support_policy', 'value' => ['response_hours' => 24]]);
        $this->actingAs($owner)->postJson('/api/v1/support/cases', ['subject' => 'Help', 'category' => 'other', 'message' => 'Please help.'])
            ->assertCreated()->assertJsonPath('data.status', 'open');
        $this->assertNotNull(SupportCase::query()->firstOrFail()->sla_due_at);
    }

    public function test_tracking_and_transactional_messages_are_owner_scoped_and_truthful(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->for($owner)->create();
        TransactionalMessage::query()->create(['public_id' => fake()->uuid(), 'user_id' => $owner->id, 'order_id' => $order->id, 'event_key' => fake()->uuid(), 'template' => 'order_update', 'payload' => [], 'status' => 'pending']);

        $this->actingAs($owner)->getJson("/api/v1/commerce/orders/{$order->public_id}/tracking")->assertOk()->assertJsonPath('data.order_status', 'awaiting_payment');
        $this->actingAs($other)->getJson("/api/v1/commerce/orders/{$order->public_id}/tracking")->assertNotFound();
        $this->actingAs($owner)->getJson('/api/v1/communications')->assertOk()->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.sent_at', null);
        $this->actingAs($other)->getJson('/api/v1/communications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reviews_require_an_owned_fulfilled_order_item_and_begin_pending(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->for($owner)->create(['status' => 'fulfilled']);
        $item = OrderItem::factory()->for($order)->create();

        $payload = ['order_item_id' => $item->id, 'rating' => 5, 'title' => 'Great', 'body' => 'A verified review.'];
        $this->actingAs($other)->postJson('/api/v1/reviews', $payload)->assertNotFound();
        $this->actingAs($owner)->postJson('/api/v1/reviews', $payload)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.verified_purchase', true);
        $this->actingAs($owner)->postJson('/api/v1/reviews', $payload)->assertStatus(409);
        $this->assertSame('pending', ProductReview::query()->firstOrFail()->status);
    }
}
