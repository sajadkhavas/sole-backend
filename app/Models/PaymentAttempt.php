<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'amount_minor' => 'integer',
        'started_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            if ($attempt->isDirty(['order_id', 'provider', 'idempotency_key', 'request_fingerprint', 'currency', 'amount_minor'])) {
                throw new RuntimeException('Payment attempt identity and amount are immutable.');
            }
        });

        static::deleting(fn (): never => throw new RuntimeException('Payment attempts are durable records.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }
}
