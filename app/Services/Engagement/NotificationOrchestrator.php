<?php

namespace App\Services\Engagement;

use App\Contracts\NotificationChannelAdapter;
use App\Models\BackInStockIntent;
use App\Models\CustomerWishlistItem;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationPreference;
use App\Models\NotificationSignal;
use App\Models\OrderEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationOrchestrator
{
    public function __construct(private readonly NotificationChannelAdapter $adapter) {}

    /**
     * @return array{back_in_stock: int, price_drop: int, order_lifecycle: int}
     */
    public function scan(): array
    {
        return [
            'back_in_stock' => $this->scanBackInStock(),
            'price_drop' => $this->scanPriceDrops(),
            'order_lifecycle' => $this->scanOrderLifecycle(),
        ];
    }

    public function dispatchPending(int $limit = 100): int
    {
        $processed = 0;

        NotificationSignal::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('eligible_at')->orWhere('eligible_at', '<=', now()))
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->each(function (NotificationSignal $signal) use (&$processed): void {
                $this->dispatchSignal($signal);
                $processed++;
            });

        return $processed;
    }

    private function scanBackInStock(): int
    {
        $created = 0;

        BackInStockIntent::query()
            ->with('productVariant.inventoryBalances')
            ->where('status', 'pending')
            ->whereNotNull('consent_granted_at')
            ->whereNull('unsubscribed_at')
            ->whereNull('last_signalled_at')
            ->orderBy('id')
            ->get()
            ->each(function (BackInStockIntent $intent) use (&$created): void {
                $variant = $intent->productVariant;
                if ($variant === null || ! $variant->is_active) {
                    return;
                }

                $available = $variant->inventoryBalances->sum(
                    fn ($balance): int => max(0, (int) $balance->on_hand - (int) $balance->reserved),
                );
                if ($available <= 0) {
                    return;
                }

                $signal = $this->firstOrCreateSignal(
                    idempotencyKey: 'back-in-stock:intent:'.$intent->id,
                    type: 'back_in_stock',
                    sourceType: 'back_in_stock_intent',
                    sourceId: $intent->id,
                    userId: $intent->user_id,
                    variantId: $variant->id,
                    facts: [
                        'intent_id' => $intent->id,
                        'variant_id' => $variant->id,
                        'available_quantity' => $available,
                        'consent_version' => $intent->consent_version,
                    ],
                );

                if ($signal->wasRecentlyCreated) {
                    $intent->forceFill(['last_signalled_at' => now()])->save();
                    $created++;
                }
            });

        return $created;
    }

    private function scanPriceDrops(): int
    {
        $created = 0;

        CustomerWishlistItem::query()
            ->with(['productVariant.product'])
            ->orderBy('id')
            ->get()
            ->each(function (CustomerWishlistItem $item) use (&$created): void {
                $variant = $item->productVariant;
                if ($variant === null || ! $variant->is_active || $variant->product?->status !== 'published') {
                    return;
                }

                $current = (int) $variant->price_minor;
                $anchor = (int) $item->price_anchor_minor;
                if ($current < $anchor) {
                    $signal = $this->firstOrCreateSignal(
                        idempotencyKey: "price-drop:wishlist:{$item->id}:{$anchor}:{$current}",
                        type: 'price_drop',
                        sourceType: 'wishlist_item',
                        sourceId: $item->id,
                        userId: $item->user_id,
                        variantId: $variant->id,
                        facts: [
                            'wishlist_item_id' => $item->id,
                            'variant_id' => $variant->id,
                            'previous_price_minor' => $anchor,
                            'current_price_minor' => $current,
                            'currency' => $variant->currency,
                        ],
                    );
                    if ($signal->wasRecentlyCreated) {
                        $created++;
                    }
                }

                if ($anchor !== $current) {
                    $item->forceFill(['price_anchor_minor' => $current])->save();
                }
            });

        return $created;
    }

    private function scanOrderLifecycle(): int
    {
        $created = 0;

        OrderEvent::query()
            ->with('order')
            ->orderBy('id')
            ->get()
            ->each(function (OrderEvent $event) use (&$created): void {
                $order = $event->order;
                if ($order === null || $order->user_id === null) {
                    return;
                }

                $signal = $this->firstOrCreateSignal(
                    idempotencyKey: 'order-lifecycle:event:'.$event->id,
                    type: 'order_lifecycle',
                    sourceType: 'order_event',
                    sourceId: $event->id,
                    userId: $order->user_id,
                    variantId: null,
                    facts: [
                        'order_id' => $order->public_id,
                        'from_status' => $event->from_status,
                        'to_status' => $event->to_status,
                        'event_id' => $event->id,
                    ],
                );
                if ($signal->wasRecentlyCreated) {
                    $created++;
                }
            });

        return $created;
    }

    /** @param array<string, mixed> $facts */
    private function firstOrCreateSignal(
        string $idempotencyKey,
        string $type,
        string $sourceType,
        int $sourceId,
        ?int $userId,
        ?int $variantId,
        array $facts,
    ): NotificationSignal {
        return NotificationSignal::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'public_id' => (string) Str::uuid(),
                'user_id' => $userId,
                'product_variant_id' => $variantId,
                'type' => $type,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'facts' => $facts,
                'status' => 'pending',
                'eligible_at' => now(),
            ],
        );
    }

    private function dispatchSignal(NotificationSignal $signal): void
    {
        DB::transaction(function () use ($signal): void {
            $locked = NotificationSignal::query()->whereKey($signal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending' || ($locked->eligible_at !== null && $locked->eligible_at->isFuture())) {
                return;
            }

            if ($locked->user_id === null) {
                $this->dispatchGuestBackInStock($locked);

                return;
            }

            $preferences = NotificationPreference::query()
                ->where('user_id', $locked->user_id)
                ->where('enabled', true)
                ->orderBy('channel')
                ->get();

            if ($preferences->isEmpty()) {
                $this->audit($locked, 'email', 'preference_missing', 'blocked', 'preference-missing');
                $locked->forceFill(['status' => 'blocked'])->save();

                return;
            }

            $deferredUntil = null;
            $delivered = false;
            $terminalBlock = false;

            foreach ($preferences as $preference) {
                $now = CarbonImmutable::now($preference->timezone);
                if ($this->insideQuietHours($preference, $now)) {
                    $this->audit(
                        $locked,
                        $preference->channel,
                        'quiet_hours',
                        'blocked',
                        'quiet:'.$now->format('Y-m-d-H'),
                    );
                    $candidate = $now->addHour()->utc();
                    $deferredUntil = $deferredUntil === null || $candidate->lessThan($deferredUntil) ? $candidate : $deferredUntil;

                    continue;
                }

                if ($this->dailyCapReached($locked, $preference, $now)) {
                    $this->audit(
                        $locked,
                        $preference->channel,
                        'frequency_cap',
                        'blocked',
                        'cap:'.$now->format('Y-m-d'),
                    );
                    $candidate = $now->addDay()->startOfDay()->utc();
                    $deferredUntil = $deferredUntil === null || $candidate->lessThan($deferredUntil) ? $candidate : $deferredUntil;

                    continue;
                }

                $result = $this->adapter->deliver($locked, $preference->channel);
                $this->audit(
                    $locked,
                    $preference->channel,
                    $result['reason'],
                    $result['delivered'] ? 'sent' : 'blocked',
                    'adapter:'.$result['reason'],
                    $result['provider'],
                    $result['response_hash'],
                );
                $delivered = $delivered || $result['delivered'];
                $terminalBlock = $terminalBlock || ! $result['delivered'];
            }

            if ($delivered) {
                $locked->forceFill(['status' => 'sent'])->save();
            } elseif ($terminalBlock) {
                $locked->forceFill(['status' => 'blocked'])->save();
            } elseif ($deferredUntil !== null) {
                $locked->forceFill(['eligible_at' => $deferredUntil])->save();
            }
        }, 3);
    }

    private function dispatchGuestBackInStock(NotificationSignal $signal): void
    {
        if ($signal->type !== 'back_in_stock' || $signal->source_type !== 'back_in_stock_intent') {
            $this->audit($signal, 'email', 'owner_missing', 'blocked', 'owner-missing');
            $signal->forceFill(['status' => 'blocked'])->save();

            return;
        }

        $intent = BackInStockIntent::query()->find($signal->source_id);
        if ($intent === null || $intent->consent_granted_at === null || $intent->unsubscribed_at !== null) {
            $this->audit($signal, 'email', 'consent_revoked', 'blocked', 'consent-revoked');
            $signal->forceFill(['status' => 'blocked'])->save();

            return;
        }

        $result = $this->adapter->deliver($signal, 'email');
        $this->audit(
            $signal,
            'email',
            $result['reason'],
            $result['delivered'] ? 'sent' : 'blocked',
            'adapter:'.$result['reason'],
            $result['provider'],
            $result['response_hash'],
        );
        $signal->forceFill(['status' => $result['delivered'] ? 'sent' : 'blocked'])->save();
    }

    private function insideQuietHours(NotificationPreference $preference, CarbonImmutable $now): bool
    {
        if ($preference->quiet_start === null || $preference->quiet_end === null) {
            return false;
        }

        $start = CarbonImmutable::parse($now->format('Y-m-d').' '.$preference->quiet_start, $preference->timezone);
        $end = CarbonImmutable::parse($now->format('Y-m-d').' '.$preference->quiet_end, $preference->timezone);
        if ($start->equalTo($end)) {
            return true;
        }
        if ($end->greaterThan($start)) {
            return $now->betweenIncluded($start, $end);
        }

        return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
    }

    private function dailyCapReached(NotificationSignal $signal, NotificationPreference $preference, CarbonImmutable $now): bool
    {
        $start = $now->startOfDay()->utc();
        $end = $now->endOfDay()->utc();

        return NotificationDeliveryAttempt::query()
            ->where('channel', $preference->channel)
            ->where('status', 'sent')
            ->whereBetween('attempted_at', [$start, $end])
            ->whereHas('signal', fn ($query) => $query->where('user_id', $signal->user_id))
            ->count() >= $preference->daily_cap;
    }

    private function audit(
        NotificationSignal $signal,
        string $channel,
        string $reason,
        string $status,
        string $suffix,
        ?string $provider = null,
        ?string $responseHash = null,
    ): NotificationDeliveryAttempt {
        return NotificationDeliveryAttempt::query()->firstOrCreate(
            ['attempt_key' => "signal:{$signal->id}:{$channel}:{$suffix}"],
            [
                'notification_signal_id' => $signal->id,
                'channel' => $channel,
                'provider' => $provider,
                'status' => $status,
                'reason' => $reason,
                'response_hash' => $responseHash,
                'attempted_at' => now(),
            ],
        );
    }
}
