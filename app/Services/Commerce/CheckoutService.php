<?php

namespace App\Services\Commerce;

use App\Models\BusinessSetting;
use App\Models\Cart;
use App\Models\CheckoutAttempt;
use App\Models\CustomerAddress;
use App\Models\InventoryBalance;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\ShippingQuote;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CheckoutService
{
    /** @return array<string, mixed> */
    public function create(User $user, Cart $cart, CustomerAddress $address, string $idempotencyKey, string $shippingQuoteId): array
    {
        $fingerprint = hash('sha256', implode(':', [$user->id, $cart->id, $address->id, $shippingQuoteId]));

        try {
            return DB::transaction(function () use ($user, $cart, $address, $idempotencyKey, $shippingQuoteId, $fingerprint): array {
                $existing = CheckoutAttempt::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ($existing->user_id !== $user->id || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                        throw new RuntimeException('Idempotency key was already used for a different checkout request.');
                    }
                    if ($existing->response_payload === null) {
                        throw new RuntimeException('The original checkout request is still processing.');
                    }

                    return $existing->response_payload;
                }

                $lockedCart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
                if ($lockedCart->status !== 'active' || ($lockedCart->user_id !== null && $lockedCart->user_id !== $user->id)) {
                    throw new RuntimeException('Cart is not available for checkout.');
                }
                if ($lockedCart->user_id === null) {
                    $lockedCart->forceFill(['user_id' => $user->id])->save();
                }
                if ($address->user_id !== $user->id) {
                    throw new RuntimeException('Shipping address does not belong to this customer.');
                }

                $quote = ShippingQuote::query()->where('public_id', $shippingQuoteId)->lockForUpdate()->firstOrFail();
                if ($quote->user_id !== $user->id || $quote->cart_id !== $lockedCart->id || $quote->customer_address_id !== $address->id) {
                    throw new RuntimeException('Shipping quote does not belong to this checkout.');
                }
                if ($quote->selected_at !== null || $quote->expires_at->isPast()) {
                    throw new RuntimeException('Shipping quote is no longer valid.');
                }

                $attempt = CheckoutAttempt::query()->create([
                    'idempotency_key' => $idempotencyKey,
                    'user_id' => $user->id,
                    'cart_id' => $lockedCart->id,
                    'request_fingerprint' => $fingerprint,
                    'status' => 'processing',
                ]);

                $items = $lockedCart->items()->with(['variant.product'])->orderBy('product_variant_id')->lockForUpdate()->get();
                if ($items->isEmpty()) {
                    throw new RuntimeException('Cart is empty.');
                }

                $currencies = $items->pluck('variant.currency')->filter()->unique();
                if ($currencies->count() !== 1 || strtoupper((string) $currencies->first()) !== strtoupper($quote->currency)) {
                    throw new RuntimeException('Cart and shipping quote currency do not match.');
                }

                $policy = BusinessSetting::query()->where('key', 'checkout_policy')->first()?->value;
                if (! is_array($policy)
                    || ! isset($policy['allowed_country_codes'], $policy['reservation_minutes'])
                    || ! is_array($policy['allowed_country_codes'])) {
                    throw new RuntimeException('Authoritative checkout policy is not configured.');
                }
                if (! in_array($address->country_code, $policy['allowed_country_codes'], true)) {
                    throw new RuntimeException('Shipping is not eligible for this address.');
                }

                $reservationMinutes = filter_var($policy['reservation_minutes'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 60]]);
                if ($reservationMinutes === false) {
                    throw new RuntimeException('Authoritative checkout policy is invalid.');
                }

                $subtotal = 0;
                foreach ($items as $item) {
                    $variant = $item->variant;
                    if ($variant === null || ! $variant->is_active || ! $variant->product?->published_at?->isPast() || $variant->product?->status !== 'published') {
                        throw new RuntimeException('Cart contains an unavailable variant.');
                    }
                    $subtotal += (int) $variant->price_minor * (int) $item->quantity;
                }

                $shippingMinor = (int) $quote->amount_minor;
                $expiresAt = now()->addMinutes($reservationMinutes);
                $order = Order::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'customer_address_id' => $address->id,
                    'status' => 'awaiting_payment',
                    'currency' => (string) $currencies->first(),
                    'subtotal_minor' => $subtotal,
                    'discount_minor' => 0,
                    'shipping_minor' => $shippingMinor,
                    'shipping_provider' => $quote->provider,
                    'shipping_service_code' => $quote->service_code,
                    'total_minor' => $subtotal + $shippingMinor,
                    'shipping_address_snapshot' => $address->only([
                        'recipient_name', 'phone_e164', 'country_code', 'province', 'city', 'postal_code', 'address_line1', 'address_line2',
                    ]),
                    'reservation_expires_at' => $expiresAt,
                ]);

                foreach ($items as $item) {
                    $variant = $item->variant;
                    $remaining = (int) $item->quantity;
                    $balances = InventoryBalance::query()
                        ->where('product_variant_id', $variant->id)
                        ->orderBy('inventory_location_id')
                        ->lockForUpdate()
                        ->get();

                    foreach ($balances as $balance) {
                        $available = max(0, (int) $balance->on_hand - (int) $balance->reserved);
                        $allocated = min($available, $remaining);
                        if ($allocated === 0) {
                            continue;
                        }

                        InventoryBalance::withinLedgerMutation(function () use ($balance, $allocated): void {
                            $balance->forceFill(['reserved' => (int) $balance->reserved + $allocated])->save();
                        });

                        InventoryReservation::query()->create([
                            'order_id' => $order->id,
                            'product_variant_id' => $variant->id,
                            'inventory_location_id' => $balance->inventory_location_id,
                            'quantity' => $allocated,
                            'status' => 'active',
                            'expires_at' => $expiresAt,
                        ]);
                        $remaining -= $allocated;
                    }

                    if ($remaining !== 0) {
                        throw new RuntimeException('Inventory changed before checkout could reserve it.');
                    }

                    $order->items()->create([
                        'product_variant_id' => $variant->id,
                        'sku' => $variant->sku,
                        'product_name' => $variant->product->name,
                        'variant_title' => $variant->title,
                        'size' => $variant->size,
                        'quantity' => $item->quantity,
                        'unit_price_minor' => $variant->price_minor,
                        'line_total_minor' => (int) $variant->price_minor * (int) $item->quantity,
                    ]);
                }

                OrderEvent::query()->create([
                    'order_id' => $order->id,
                    'actor_id' => $user->id,
                    'from_status' => null,
                    'to_status' => 'awaiting_payment',
                    'reason' => 'checkout_created',
                    'metadata' => [
                        'shipping_quote_id' => $quote->public_id,
                        'shipping_provider' => $quote->provider,
                        'shipping_service_code' => $quote->service_code,
                    ],
                    'created_at' => now(),
                ]);

                $quote->forceFill(['selected_at' => now()])->save();
                $lockedCart->forceFill(['status' => 'converted'])->save();
                $payload = $this->orderPayload($order->load('items'));
                $attempt->forceFill(['order_id' => $order->id, 'status' => 'completed', 'response_payload' => $payload])->save();

                return $payload;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $existing = CheckoutAttempt::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null
                && $existing->user_id === $user->id
                && hash_equals($existing->request_fingerprint, $fingerprint)
                && $existing->response_payload !== null) {
                return $existing->response_payload;
            }

            throw new RuntimeException('Idempotency key is already in use.');
        }
    }

    /** @return array<string, mixed> */
    public function orderPayload(Order $order): array
    {
        $order->loadMissing(['items', 'paymentAttempts', 'shipment', 'returnRequest', 'refunds']);
        $latestPayment = $order->paymentAttempts->sortByDesc('id')->first();

        return [
            'id' => $order->public_id,
            'status' => $order->status,
            'currency' => $order->currency,
            'subtotal_minor' => $order->subtotal_minor,
            'discount_minor' => $order->discount_minor,
            'shipping_minor' => $order->shipping_minor,
            'shipping_provider' => $order->shipping_provider,
            'shipping_service_code' => $order->shipping_service_code,
            'total_minor' => $order->total_minor,
            'reservation_expires_at' => $order->reservation_expires_at?->toISOString(),
            'created_at' => $order->created_at?->toISOString(),
            'payment' => $latestPayment === null ? null : [
                'id' => $latestPayment->public_id,
                'provider' => $latestPayment->provider,
                'status' => $latestPayment->status,
                'reference_id' => $latestPayment->status === 'paid' ? $latestPayment->reference_id : null,
            ],
            'shipment' => $order->shipment === null ? null : [
                'id' => $order->shipment->public_id,
                'status' => $order->shipment->status,
                'tracking_number' => $order->shipment->tracking_number,
            ],
            'return' => $order->returnRequest === null ? null : [
                'id' => $order->returnRequest->public_id,
                'status' => $order->returnRequest->status,
            ],
            'refunds' => $order->refunds->map(fn ($refund): array => [
                'id' => $refund->public_id,
                'status' => $refund->status,
                'amount_minor' => $refund->amount_minor,
            ])->values()->all(),
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'sku' => $item->sku,
                'product_name' => $item->product_name,
                'variant_title' => $item->variant_title,
                'size' => $item->size,
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->unit_price_minor,
                'line_total_minor' => $item->line_total_minor,
            ])->values()->all(),
        ];
    }
}
