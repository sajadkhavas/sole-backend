<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingQuote extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'amount_minor' => 'integer',
        'eta_min_days' => 'integer',
        'eta_max_days' => 'integer',
        'expires_at' => 'immutable_datetime',
        'selected_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'customer_address_id');
    }
}
