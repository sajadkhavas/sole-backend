<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationSignal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'eligible_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(NotificationDeliveryAttempt::class);
    }
}
