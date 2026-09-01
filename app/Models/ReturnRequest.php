<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ReturnRequest extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'requested_at' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
    ];

    private static bool $transitioning = false;

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            if ($request->isDirty('status') && ! self::$transitioning) {
                throw new RuntimeException('Return status must change through ReturnService.');
            }
            if ($request->isDirty(['order_id', 'user_id', 'reason', 'reason_text', 'requested_at'])) {
                throw new RuntimeException('Return request identity is immutable.');
            }
        });
        static::deleting(fn (): never => throw new RuntimeException('Return requests are durable records.'));
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
