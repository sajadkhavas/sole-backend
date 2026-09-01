<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackInStockIntent extends Model
{
    protected $fillable = [
        'product_variant_id',
        'email_hash',
        'contact_email',
        'consent_version',
        'consent_granted_at',
        'source',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'contact_email' => 'encrypted',
            'consent_granted_at' => 'datetime',
        ];
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
