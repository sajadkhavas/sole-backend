<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerWishlistItem;
use App\Models\LoyaltyLedgerEntry;
use App\Models\NotificationPreference;
use App\Models\NotificationSignal;
use App\Models\ProductVariant;
use App\Services\Engagement\LoyaltyLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EngagementController extends Controller
{
    private const CHANNELS = ['email', 'sms', 'push'];

    public function wishlist(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->wishlistItems($request->user()->id)]);
    }

    public function addWishlist(Request $request, int $variant): JsonResponse
    {
        $model = $this->sellableVariant($variant);
        CustomerWishlistItem::query()->firstOrCreate(
            ['user_id' => $request->user()->id, 'product_variant_id' => $model->id],
            ['price_anchor_minor' => $model->price_minor],
        );

        return response()->json(['data' => $this->wishlistItems($request->user()->id)], 201);
    }

    public function removeWishlist(Request $request, int $variant): JsonResponse
    {
        $item = CustomerWishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->where('product_variant_id', $variant)
            ->firstOrFail();
        $item->delete();

        return response()->json(status: 204);
    }

    public function migrateWishlist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_ids' => ['required', 'array', 'max:100'],
            'variant_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);
        $variants = ProductVariant::query()
            ->whereIn('id', $data['variant_ids'])
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('status', 'published'))
            ->get();

        DB::transaction(function () use ($request, $variants): void {
            foreach ($variants as $variant) {
                CustomerWishlistItem::query()->firstOrCreate(
                    ['user_id' => $request->user()->id, 'product_variant_id' => $variant->id],
                    ['price_anchor_minor' => $variant->price_minor],
                );
            }
        }, 3);

        return response()->json([
            'data' => $this->wishlistItems($request->user()->id),
            'accepted_variant_ids' => $variants->pluck('id')->values(),
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->get()
            ->keyBy('channel');

        return response()->json([
            'data' => collect(self::CHANNELS)->map(fn (string $channel): array => $this->preferencePayload($channel, $stored->get($channel)))->values(),
        ]);
    }

    public function updatePreference(Request $request, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, self::CHANNELS, true), 404);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'daily_cap' => ['required', 'integer', 'min:1', 'max:20'],
            'quiet_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_end'],
            'quiet_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_start'],
            'timezone' => ['required', 'timezone'],
        ]);

        $preference = NotificationPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'channel' => $channel],
            $data,
        );

        return response()->json(['data' => $this->preferencePayload($channel, $preference)]);
    }

    public function unsubscribe(Request $request, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, self::CHANNELS, true), 404);
        $preference = NotificationPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'channel' => $channel],
            ['enabled' => false],
        );

        return response()->json(['data' => $this->preferencePayload($channel, $preference)]);
    }

    public function signals(Request $request): JsonResponse
    {
        $signals = NotificationSignal::query()
            ->where('user_id', $request->user()->id)
            ->with('deliveryAttempts')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (NotificationSignal $signal): array => [
                'id' => $signal->public_id,
                'type' => $signal->type,
                'status' => $signal->status,
                'facts' => $signal->facts,
                'created_at' => $signal->created_at?->toISOString(),
                'delivery_attempts' => $signal->deliveryAttempts->map(fn ($attempt): array => [
                    'channel' => $attempt->channel,
                    'status' => $attempt->status,
                    'reason' => $attempt->reason,
                    'provider' => $attempt->provider,
                    'attempted_at' => $attempt->attempted_at?->toISOString(),
                ])->values(),
            ]);

        return response()->json(['data' => $signals]);
    }

    public function loyalty(Request $request, LoyaltyLedgerService $ledger): JsonResponse
    {
        $history = LoyaltyLedgerEntry::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (LoyaltyLedgerEntry $entry): array => [
                'id' => $entry->public_id,
                'type' => $entry->type,
                'points_delta' => $entry->points_delta,
                'reason' => $entry->reason,
                'available_at' => $entry->available_at?->toISOString(),
                'expires_at' => $entry->expires_at?->toISOString(),
                'created_at' => $entry->created_at?->toISOString(),
            ]);

        return response()->json([
            'data' => [
                'balance' => $ledger->balance($request->user()),
                'history' => $history,
                'terms' => [
                    'cash_value' => false,
                    'server_authoritative' => true,
                    'earning_rate_published' => false,
                ],
            ],
        ]);
    }

    private function sellableVariant(int $id): ProductVariant
    {
        return ProductVariant::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('status', 'published'))
            ->firstOrFail();
    }

    private function wishlistItems(int $userId): array
    {
        return CustomerWishlistItem::query()
            ->where('user_id', $userId)
            ->with(['productVariant.product', 'productVariant.inventoryBalances'])
            ->latest('id')
            ->get()
            ->map(function (CustomerWishlistItem $item): array {
                $variant = $item->productVariant;
                $available = $variant?->inventoryBalances->sum(
                    fn ($balance): int => max(0, (int) $balance->on_hand - (int) $balance->reserved),
                ) ?? 0;

                return [
                    'id' => $item->id,
                    'variant_id' => $variant?->id,
                    'product_id' => $variant?->product_id,
                    'product_slug' => $variant?->product?->slug,
                    'product_name' => $variant?->product?->name,
                    'variant_title' => $variant?->title,
                    'size' => $variant?->size,
                    'color' => $variant?->color,
                    'price_minor' => $variant?->price_minor,
                    'currency' => $variant?->currency,
                    'available_quantity' => $available,
                    'added_at' => $item->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    private function preferencePayload(string $channel, ?NotificationPreference $preference): array
    {
        return [
            'channel' => $channel,
            'enabled' => $preference?->enabled ?? false,
            'daily_cap' => $preference?->daily_cap ?? 1,
            'quiet_start' => $preference?->quiet_start,
            'quiet_end' => $preference?->quiet_end,
            'timezone' => $preference?->timezone ?? 'Asia/Tehran',
        ];
    }
}
