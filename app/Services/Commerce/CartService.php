<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CartService
{
    public function resolve(?string $publicId, ?User $user): Cart
    {
        return DB::transaction(function () use ($publicId, $user): Cart {
            $cart = $publicId === null
                ? null
                : Cart::query()->where('public_id', $publicId)->lockForUpdate()->first();

            if ($cart !== null && $cart->user_id !== null && $cart->user_id !== $user?->id) {
                throw new RuntimeException('This cart belongs to another customer.');
            }

            if ($cart !== null && $cart->status !== 'active') {
                $cart = null;
            }

            if ($cart === null) {
                return Cart::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user?->id,
                    'status' => 'active',
                    'expires_at' => now()->addDays(30),
                ]);
            }

            if ($user !== null && $cart->user_id === null) {
                $cart->forceFill(['user_id' => $user->id])->save();
            }

            $cart->forceFill(['expires_at' => now()->addDays(30)])->save();

            return $cart;
        }, 3);
    }

    public function setQuantity(Cart $cart, ProductVariant $variant, int $quantity): Cart
    {
        if ($quantity < 1 || $quantity > 99) {
            throw new RuntimeException('Cart quantity must be between 1 and 99.');
        }

        if (! $variant->is_active || ! $variant->product()->published()->exists()) {
            throw new RuntimeException('This variant is not available for purchase.');
        }

        $available = (int) $variant->inventoryBalances()->sum(DB::raw('on_hand - reserved'));

        if ($available < $quantity) {
            throw new RuntimeException('Requested quantity exceeds authoritative available inventory.');
        }

        $cart->items()->updateOrCreate(
            ['product_variant_id' => $variant->id],
            ['quantity' => $quantity],
        );

        return $cart->refresh();
    }

    /** @return array<string, mixed> */
    public function payload(Cart $cart): array
    {
        $cart->load(['items.variant.product', 'items.variant.inventoryBalances']);
        $subtotal = 0;
        $ready = true;

        $items = $cart->items->map(function ($item) use (&$subtotal, &$ready): array {
            $variant = $item->variant;
            $available = $variant === null ? 0 : $variant->inventoryBalances->sum(fn ($balance): int => max(0, (int) $balance->on_hand - (int) $balance->reserved));
            $isReady = $variant !== null
                && $variant->is_active
                && $variant->product?->status === 'published'
                && $variant->product?->published_at?->isPast()
                && $available >= $item->quantity;
            $lineTotal = $variant === null ? 0 : (int) $variant->price_minor * (int) $item->quantity;

            if ($isReady) {
                $subtotal += $lineTotal;
            } else {
                $ready = false;
            }

            return [
                'variant_id' => $variant?->id,
                'product_slug' => $variant?->product?->slug,
                'product_name' => $variant?->product?->name,
                'sku' => $variant?->sku,
                'variant_title' => $variant?->title,
                'size' => $variant?->size,
                'quantity' => (int) $item->quantity,
                'available_quantity' => $available,
                'unit_price_minor' => $variant === null ? null : (int) $variant->price_minor,
                'line_total_minor' => $variant === null ? null : $lineTotal,
                'currency' => $variant?->currency,
                'status' => $isReady ? 'ready' : 'unavailable',
            ];
        })->values()->all();

        return [
            'id' => $cart->public_id,
            'status' => $cart->status,
            'items' => $items,
            'summary' => [
                'subtotal_minor' => $subtotal,
                'currency' => $cart->items->first()?->variant?->currency ?? 'IRR',
                'checkout_ready' => $ready && $cart->items->isNotEmpty(),
            ],
            'expires_at' => $cart->expires_at?->toISOString(),
        ];
    }
}
