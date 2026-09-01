<?php

namespace App\Services\Commerce;

use App\Contracts\ShippingProvider;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShippingQuote;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ShippingService
{
    public function __construct(
        private readonly ShippingProvider $provider,
        private readonly OrderStateService $orders,
    ) {}

    /** @return list<array<string, mixed>> */
    public function quotes(User $user, Cart $cart, CustomerAddress $address): array
    {
        if ($address->user_id !== $user->id) {
            throw new RuntimeException('Shipping address does not belong to this customer.');
        }
        if ($cart->status !== 'active' || ($cart->user_id !== null && $cart->user_id !== $user->id)) {
            throw new RuntimeException('Cart is not available for shipping quotes.');
        }

        $items = $cart->items()->with(['variant.product'])->get();
        if ($items->isEmpty()) {
            throw new RuntimeException('Cart is empty.');
        }

        $currencies = $items->pluck('variant.currency')->filter()->unique();
        if ($currencies->count() !== 1) {
            throw new RuntimeException('Cart currency is invalid.');
        }

        $subtotal = 0;
        foreach ($items as $item) {
            $variant = $item->variant;
            if ($variant === null || ! $variant->is_active || ! $variant->product?->published_at?->isPast() || $variant->product?->status !== 'published') {
                throw new RuntimeException('Cart contains an unavailable variant.');
            }
            $subtotal += (int) $variant->price_minor * (int) $item->quantity;
        }

        $currency = (string) $currencies->first();
        $provided = $this->provider->quotes($user, $cart, $address, $subtotal, $currency);
        $persisted = [];

        DB::transaction(function () use ($provided, $user, $cart, $address, $currency, &$persisted): void {
            ShippingQuote::query()
                ->where('user_id', $user->id)
                ->where('cart_id', $cart->id)
                ->whereNull('selected_at')
                ->where('expires_at', '<=', now())
                ->delete();

            foreach ($provided as $quote) {
                if ($quote['currency'] !== $currency || $quote['expires_at'] <= now()) {
                    throw new RuntimeException('Shipping provider returned an invalid quote.');
                }

                $model = ShippingQuote::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'cart_id' => $cart->id,
                    'customer_address_id' => $address->id,
                    'provider' => $this->provider->provider(),
                    'service_code' => $quote['service_code'],
                    'label' => $quote['label'],
                    'currency' => $quote['currency'],
                    'amount_minor' => $quote['amount_minor'],
                    'eta_min_days' => $quote['eta_min_days'],
                    'eta_max_days' => $quote['eta_max_days'],
                    'expires_at' => $quote['expires_at'],
                ]);
                $persisted[] = $this->quotePayload($model);
            }
        }, 3);

        return $persisted;
    }

    public function selectForCheckout(User $user, Cart $cart, CustomerAddress $address, string $publicId): ShippingQuote
    {
        return DB::transaction(function () use ($user, $cart, $address, $publicId): ShippingQuote {
            $quote = ShippingQuote::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            if ($quote->user_id !== $user->id || $quote->cart_id !== $cart->id || $quote->customer_address_id !== $address->id) {
                throw new RuntimeException('Shipping quote does not belong to this checkout.');
            }
            if ($quote->selected_at !== null) {
                throw new RuntimeException('Shipping quote has already been consumed.');
            }
            if ($quote->expires_at->isPast()) {
                throw new RuntimeException('Shipping quote has expired.');
            }

            $quote->forceFill(['selected_at' => now()])->save();

            return $quote->refresh();
        }, 3);
    }

    public function ensureShipment(Order $order): Shipment
    {
        if (! in_array($order->status, ['paid', 'processing', 'fulfilled'], true)) {
            throw new RuntimeException('Only paid orders may enter fulfillment.');
        }
        if (! is_string($order->shipping_provider) || $order->shipping_provider === '' || ! is_string($order->shipping_service_code) || $order->shipping_service_code === '') {
            throw new RuntimeException('Order has no authoritative shipping service snapshot.');
        }

        try {
            $shipment = Shipment::query()->firstOrCreate(
                ['order_id' => $order->id],
                [
                    'public_id' => (string) Str::uuid(),
                    'provider' => $order->shipping_provider,
                    'service_code' => $order->shipping_service_code,
                    'status' => 'pending',
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
        }

        if ($shipment->wasRecentlyCreated) {
            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'event_key' => 'created',
                'from_status' => null,
                'to_status' => 'pending',
                'reason' => 'payment_verified',
                'created_at' => now(),
            ]);
            $this->provider->createFulfillment($order, $shipment);
        }

        return $shipment->refresh();
    }

    public function transition(Shipment $shipment, string $eventKey, string $toStatus, string $reason, ?string $trackingNumber = null, array $metadata = []): Shipment
    {
        $allowed = [
            'pending' => ['ready', 'cancelled'],
            'ready' => ['shipped', 'cancelled'],
            'shipped' => ['delivered', 'exception'],
            'exception' => ['shipped', 'cancelled'],
            'delivered' => [],
            'cancelled' => [],
        ];

        $result = DB::transaction(function () use ($shipment, $eventKey, $toStatus, $reason, $trackingNumber, $metadata, $allowed): Shipment {
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $existing = ShipmentEvent::query()->where('shipment_id', $locked->id)->where('event_key', $eventKey)->first();
            if ($existing !== null) {
                return $locked;
            }

            $from = $locked->status;
            if (! in_array($toStatus, $allowed[$from] ?? [], true)) {
                throw new RuntimeException("Invalid shipment transition from {$from} to {$toStatus}.");
            }

            if ($toStatus === 'shipped' && $locked->shipped_at === null) {
                $this->commitReservations($locked->order()->lockForUpdate()->firstOrFail());
            }

            Shipment::withinStateTransition(function () use ($locked, $toStatus, $trackingNumber): void {
                $attributes = ['status' => $toStatus];
                if ($trackingNumber !== null && trim($trackingNumber) !== '') {
                    $attributes['tracking_number'] = trim($trackingNumber);
                }
                if ($toStatus === 'shipped') {
                    $attributes['shipped_at'] = $locked->shipped_at ?? now();
                }
                if ($toStatus === 'delivered') {
                    $attributes['delivered_at'] = $locked->delivered_at ?? now();
                }
                $locked->forceFill($attributes)->save();
            });

            ShipmentEvent::query()->create([
                'shipment_id' => $locked->id,
                'event_key' => $eventKey,
                'from_status' => $from,
                'to_status' => $toStatus,
                'reason' => $reason,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);

        $order = $result->order()->firstOrFail();
        if ($toStatus === 'shipped' && $order->status === 'paid') {
            $this->orders->transition($order, 'processing', 'shipment_dispatched');
        }
        if ($toStatus === 'delivered' && $order->status === 'processing') {
            $this->orders->transition($order, 'fulfilled', 'shipment_delivered');
        }

        return $result->refresh();
    }

    private function commitReservations(Order $order): void
    {
        foreach ($order->reservations()->where('status', 'active')->orderBy('id')->lockForUpdate()->get() as $reservation) {
            $balance = InventoryBalance::query()
                ->where('product_variant_id', $reservation->product_variant_id)
                ->where('inventory_location_id', $reservation->inventory_location_id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantity = (int) $reservation->quantity;
            if ((int) $balance->reserved < $quantity || (int) $balance->on_hand < $quantity) {
                throw new RuntimeException('Inventory reservation cannot be committed safely.');
            }

            InventoryBalance::withinLedgerMutation(function () use ($balance, $quantity): void {
                $balance->forceFill([
                    'on_hand' => (int) $balance->on_hand - $quantity,
                    'reserved' => (int) $balance->reserved - $quantity,
                ])->save();
            });
            $reservation->forceFill(['status' => 'committed', 'released_at' => now()])->save();
        }
    }

    /** @return array<string, mixed> */
    public function quotePayload(ShippingQuote $quote): array
    {
        return [
            'id' => $quote->public_id,
            'provider' => $quote->provider,
            'service_code' => $quote->service_code,
            'label' => $quote->label,
            'currency' => $quote->currency,
            'amount_minor' => $quote->amount_minor,
            'eta_min_days' => $quote->eta_min_days,
            'eta_max_days' => $quote->eta_max_days,
            'expires_at' => $quote->expires_at->toISOString(),
        ];
    }
}
