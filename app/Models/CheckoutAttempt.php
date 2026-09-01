<?php

namespace App\Models;

use Database\Factories\CheckoutAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutAttempt extends Model
{
    /** @use HasFactory<CheckoutAttemptFactory> */
    use HasFactory;

    protected $fillable = ['idempotency_key', 'user_id', 'cart_id', 'order_id', 'request_fingerprint', 'status', 'response_payload'];

    protected function casts(): array
    {
        return ['response_payload' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
