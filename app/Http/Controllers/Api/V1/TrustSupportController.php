<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductReview;
use App\Models\SupportCase;
use App\Models\TransactionalMessage;
use App\Models\TrustContent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrustSupportController extends Controller
{
    public function content(): JsonResponse
    {
        $content = TrustContent::query()
            ->where('status', 'published')
            ->whereNotNull('approved_by')->whereNotNull('approved_at')->whereNotNull('provenance_url')
            ->orderBy('kind')->orderBy('slug')
            ->get(['slug', 'kind', 'title', 'body', 'version', 'provenance_url', 'approved_at']);

        return response()->json(['data' => $content]);
    }

    public function cases(Request $request): JsonResponse
    {
        $cases = SupportCase::query()->where('user_id', $request->user()->id)->with('events')->latest('id')->get();

        return response()->json(['data' => $cases->map(fn (SupportCase $case): array => $this->casePayload($case))]);
    }

    public function createCase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180'], 'category' => ['required', 'in:order,delivery,return,payment,product,account,other'],
            'message' => ['required', 'string', 'max:5000'], 'order_id' => ['nullable', 'uuid'],
        ]);
        $user = $request->user();
        $order = isset($data['order_id']) ? Order::query()->where('user_id', $user->id)->where('public_id', $data['order_id'])->firstOrFail() : null;
        $policy = BusinessSetting::query()->where('key', 'support_policy')->first()?->value;
        $hours = is_array($policy) ? filter_var($policy['response_hours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 720]]) : false;

        $case = DB::transaction(function () use ($data, $user, $order, $hours): SupportCase {
            $case = SupportCase::query()->create([
                'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'order_id' => $order?->id,
                'subject' => $data['subject'], 'category' => $data['category'], 'status' => 'open',
                'sla_due_at' => $hours === false ? null : now()->addHours($hours),
            ]);
            $case->events()->create(['actor_id' => $user->id, 'type' => 'opened', 'body' => $data['message'], 'created_at' => now()]);
            TransactionalMessage::query()->create([
                'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'order_id' => $order?->id,
                'event_key' => "support.case.{$case->public_id}.opened", 'template' => 'support_case_opened',
                'payload' => ['case_id' => $case->public_id], 'status' => 'pending',
            ]);

            return $case->load('events');
        });

        return response()->json(['data' => $this->casePayload($case)], 201);
    }

    public function case(Request $request, string $case): JsonResponse
    {
        $model = SupportCase::query()->where('user_id', $request->user()->id)->where('public_id', $case)->with('events')->firstOrFail();

        return response()->json(['data' => $this->casePayload($model)]);
    }

    public function message(Request $request, string $case): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $model = SupportCase::query()->where('user_id', $request->user()->id)->where('public_id', $case)->firstOrFail();
        if (in_array($model->status, ['resolved', 'closed'], true)) {
            return response()->json(['message' => 'Closed support cases cannot receive messages.'], 409);
        }

        $model->events()->create(['actor_id' => $request->user()->id, 'type' => 'customer_message', 'body' => $data['message'], 'created_at' => now()]);

        return response()->json(['data' => $this->casePayload($model->load('events'))], 201);
    }

    public function tracking(Request $request, string $order): JsonResponse
    {
        $model = Order::query()->where('user_id', $request->user()->id)->where('public_id', $order)
            ->with(['events', 'shipment.events'])->firstOrFail();

        return response()->json(['data' => [
            'order_id' => $model->public_id, 'order_status' => $model->status,
            'shipment' => $model->shipment === null ? null : [
                'status' => $model->shipment->status, 'provider' => $model->shipment->provider,
                'tracking_number' => $model->shipment->tracking_number,
                'shipped_at' => $model->shipment->shipped_at?->toISOString(), 'delivered_at' => $model->shipment->delivered_at?->toISOString(),
            ],
            'events' => $model->events->map(fn ($event): array => ['type' => 'order', 'status' => $event->to_status, 'reason' => $event->reason, 'at' => $event->created_at?->toISOString()])
                ->concat($model->shipment?->events?->map(fn ($event): array => ['type' => 'shipment', 'status' => $event->to_status, 'reason' => $event->reason, 'at' => $event->created_at?->toISOString()]) ?? [])->sortBy('at')->values(),
        ]]);
    }

    public function messages(Request $request): JsonResponse
    {
        $rows = TransactionalMessage::query()->where('user_id', $request->user()->id)->latest('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (TransactionalMessage $message): array => [
            'id' => $message->public_id, 'template' => $message->template, 'channel' => $message->channel,
            'status' => $message->status, 'payload' => $message->payload,
            'sent_at' => $message->sent_at?->toISOString(), 'failed_at' => $message->failed_at?->toISOString(),
        ])]);
    }

    public function review(Request $request): JsonResponse
    {
        $data = $request->validate(['order_item_id' => ['required', 'integer'], 'rating' => ['required', 'integer', 'between:1,5'], 'title' => ['nullable', 'string', 'max:120'], 'body' => ['required', 'string', 'max:5000']]);
        $item = OrderItem::query()->whereKey($data['order_item_id'])->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', 'fulfilled'))->firstOrFail();

        try {
            $review = ProductReview::query()->create([
                'public_id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'order_id' => $item->order_id,
                'order_item_id' => $item->id, 'product_variant_id' => $item->product_variant_id,
                'rating' => $data['rating'], 'title' => $data['title'] ?? null, 'body' => $data['body'], 'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'This purchase has already been reviewed.'], 409);
        }

        return response()->json(['data' => ['id' => $review->public_id, 'status' => 'pending', 'verified_purchase' => true]], 201);
    }

    /** @return array<string, mixed> */
    private function casePayload(SupportCase $case): array
    {
        return ['id' => $case->public_id, 'subject' => $case->subject, 'category' => $case->category, 'priority' => $case->priority,
            'status' => $case->status, 'sla_due_at' => $case->sla_due_at?->toISOString(),
            'events' => $case->events->map(fn ($event): array => ['type' => $event->type, 'body' => $event->body, 'at' => $event->created_at?->toISOString()])->values()];
    }
}
