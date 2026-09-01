<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = ['order_id', 'product_variant_id', 'sku', 'product_name', 'variant_title', 'size', 'quantity', 'unit_price_minor', 'line_total_minor'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
