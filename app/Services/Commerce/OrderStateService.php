<?php

namespace App\Services\Commerce;

use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderStateService
{
    public function transition(Order $order, string $toStatus, string $reason): Order
    {
        $allowed = [
            'awaiting_payment' => ['cancelled', 'expired', 'paid'],
            'paid' => ['processing', 'cancelled'],
            'processing' => ['fulfilled', 'cancelled'],
            'fulfilled' => [],
            'cancelled' => [],
            'expired' => [],
        ];

        return DB::transaction(function () use ($order, $toStatus, $reason, $allowed): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $fromStatus = $locked->status;

            if (! in_array($toStatus, $allowed[$fromStatus] ?? [], true)) {
                throw new RuntimeException("Invalid order transition from {$fromStatus} to {$toStatus}.");
            }

            if (in_array($toStatus, ['cancelled', 'expired'], true)) {
                foreach ($locked->reservations()->where('status', 'active')->orderBy('id')->lockForUpdate()->get() as $reservation) {
                    $balance = InventoryBalance::query()
                        ->where('product_variant_id', $reservation->product_variant_id)
                        ->where('inventory_location_id', $reservation->inventory_location_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ((int) $balance->reserved < (int) $reservation->quantity) {
                        throw new RuntimeException('Inventory reservation balance is inconsistent.');
                    }

                    InventoryBalance::withinLedgerMutation(function () use ($balance, $reservation): void {
                        $balance->forceFill(['reserved' => (int) $balance->reserved - (int) $reservation->quantity])->save();
                    });
                    $reservation->forceFill(['status' => 'released', 'released_at' => now()])->save();
                }
            }

            Order::withinStateTransition(function () use ($locked, $toStatus): void {
                $locked->forceFill(['status' => $toStatus])->save();
            });
            OrderEvent::query()->create([
                'order_id' => $locked->id,
                'actor_id' => auth()->id(),
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }
}
