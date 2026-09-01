<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SizeGuide extends Model
{
    protected $fillable = ['product_id', 'status', 'source_label', 'source_url', 'measurement_unit', 'width_profile', 'notes', 'verified_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function entries(): HasMany { return $this->hasMany(SizeGuideEntry::class)->orderBy('eu_size'); }
}
