<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class InventoryLedger
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function adjust(
        ProductVariant $variant,
        InventoryLocation $location,
        int $delta,
        string $reason,
        ?string $requestId = null,
        array $metadata = [],
    ): InventoryMovement {
        if ($delta === 0) {
            throw new InvalidArgumentException('Inventory adjustment delta must not be zero.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Inventory adjustment reason is required.');
        }

        return DB::transaction(function () use ($variant, $location, $delta, $reason, $requestId, $metadata): InventoryMovement {
            if ($requestId !== null) {
                $existing = InventoryMovement::query()->where('request_id', $requestId)->first();

                if ($existing !== null) {
                    if ((int) $existing->product_variant_id !== (int) $variant->getKey()
                        || (int) $existing->inventory_location_id !== (int) $location->getKey()
                        || (int) $existing->delta !== $delta) {
                        throw new RuntimeException('Inventory idempotency key was already used for a different mutation.');
                    }

                    return $existing;
                }
            }

            DB::table('inventory_balances')->insertOrIgnore([
                'product_variant_id' => $variant->getKey(),
                'inventory_location_id' => $location->getKey(),
                'on_hand' => 0,
                'reserved' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $balance = InventoryBalance::query()
                ->where('product_variant_id', $variant->getKey())
                ->where('inventory_location_id', $location->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $nextOnHand = (int) $balance->on_hand + $delta;

            if ($nextOnHand < 0 || $nextOnHand < (int) $balance->reserved) {
                throw new RuntimeException('Inventory adjustment would make available stock negative.');
            }

            InventoryBalance::withinLedgerMutation(function () use ($balance, $nextOnHand): void {
                $balance->forceFill(['on_hand' => $nextOnHand])->save();
            });

            $movement = InventoryMovement::query()->create([
                'product_variant_id' => $variant->getKey(),
                'inventory_location_id' => $location->getKey(),
                'actor_id' => auth()->id(),
                'delta' => $delta,
                'reason' => $reason,
                'request_id' => $requestId,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);

            $this->auditLogger->record('inventory.adjusted', $movement, null, [
                'delta' => $delta,
                'reason' => $reason,
                'on_hand' => $nextOnHand,
            ]);

            return $movement;
        }, 3);
    }
}
