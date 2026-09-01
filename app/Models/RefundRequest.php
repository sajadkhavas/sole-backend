<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class RefundRequest extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'amount_minor' => 'integer',
        'requested_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    private static bool $transitioning = false;

    protected static function booted(): void
    {
        static::updating(function (self $refund): void {
            if ($refund->isDirty('status') && ! self::$transitioning) {
                throw new RuntimeException('Refund status must change through RefundService.');
            }
            if ($refund->isDirty(['order_id', 'payment_attempt_id', 'user_id', 'idempotency_key', 'amount_minor', 'reason', 'requested_at'])) {
                throw new RuntimeException('Refund request identity and amount are immutable.');
            }
        });
        static::deleting(fn (): never => throw new RuntimeException('Refund requests are durable records.'));
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

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
