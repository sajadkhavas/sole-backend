<?php

namespace App\Models;

use Closure;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected static bool $stateMutationAllowed = false;

    protected $fillable = ['public_id', 'user_id', 'customer_address_id', 'status', 'currency', 'subtotal_minor', 'discount_minor', 'shipping_minor', 'total_minor', 'shipping_address_snapshot', 'reservation_expires_at'];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'shipping_minor' => 'integer',
            'total_minor' => 'integer',
            'shipping_address_snapshot' => 'array',
            'reservation_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            if ($order->isDirty('status') && ! static::$stateMutationAllowed) {
                throw new LogicException('Order status may only change through the order state service.');
            }
        });
        static::deleting(fn () => throw new LogicException('Orders are durable and may not be deleted directly.'));
    }

    public static function withinStateTransition(Closure $callback): mixed
    {
        $previous = static::$stateMutationAllowed;
        static::$stateMutationAllowed = true;
        try {
            return $callback();
        } finally {
            static::$stateMutationAllowed = $previous;
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }
}
