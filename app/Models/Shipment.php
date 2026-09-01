<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Shipment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'shipped_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];

    private static bool $transitioning = false;

    protected static function booted(): void
    {
        static::updating(function (self $shipment): void {
            if ($shipment->isDirty('status') && ! self::$transitioning) {
                throw new RuntimeException('Shipment status must change through ShipmentService.');
            }
            if ($shipment->isDirty(['order_id', 'provider', 'service_code'])) {
                throw new RuntimeException('Shipment identity is immutable.');
            }
        });
        static::deleting(fn (): never => throw new RuntimeException('Shipments are durable records.'));
    }

    public static function withinStateTransition(callable $callback): mixed
    {
        self::$transitioning = true;
        try {
            return $callback();
        } finally {
            self::$transitioning = false;
        }
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }
}
