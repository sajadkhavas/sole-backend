<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InventoryBalance extends Model
{
    protected static bool $ledgerMutationAllowed = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (): void {
            if (! static::$ledgerMutationAllowed) {
                throw new LogicException('Inventory balances may only be changed through the inventory ledger.');
            }
        });

        static::deleting(fn () => throw new LogicException('Inventory balances may not be deleted directly.'));
    }

    public static function withinLedgerMutation(Closure $callback): mixed
    {
        $previous = static::$ledgerMutationAllowed;
        static::$ledgerMutationAllowed = true;

        try {
            return $callback();
        } finally {
            static::$ledgerMutationAllowed = $previous;
        }
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }
}
