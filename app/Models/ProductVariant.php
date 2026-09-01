<?php

namespace App\Models;

use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'sku', 'title', 'size', 'color', 'price_minor', 'compare_at_price_minor', 'currency', 'is_active'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'compare_at_price_minor' => 'integer', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function mediaAttachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class, 'subject_id')->where('subject_type', 'variant');
    }

    public function backInStockIntents(): HasMany
    {
        return $this->hasMany(BackInStockIntent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSellable(Builder $query): Builder
    {
        return $query->active()->whereHas('inventoryBalances', fn (Builder $balance) => $balance->whereColumn('on_hand', '>', 'reserved'));
    }
}
