<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsFunnelSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date', 'taxonomy_version', 'catalog_sessions', 'product_sessions', 'cart_sessions',
        'checkout_sessions', 'order_sessions', 'paid_sessions', 'rebuilt_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'rebuilt_at' => 'datetime',
        ];
    }
}
