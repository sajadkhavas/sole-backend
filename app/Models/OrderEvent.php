<?php

namespace App\Models;

use Database\Factories\OrderEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OrderEvent extends Model
{
    /** @use HasFactory<OrderEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['order_id', 'actor_id', 'from_status', 'to_status', 'reason', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order events are append-only.'));
        static::deleting(fn () => throw new LogicException('Order events are append-only.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
