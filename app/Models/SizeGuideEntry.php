<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SizeGuideEntry extends Model
{
    protected $fillable = ['size_guide_id', 'eu_size', 'foot_length_min_mm', 'foot_length_max_mm', 'label'];

    protected function casts(): array
    {
        return ['eu_size' => 'decimal:1', 'foot_length_min_mm' => 'integer', 'foot_length_max_mm' => 'integer'];
    }

    public function sizeGuide(): BelongsTo
    {
        return $this->belongsTo(SizeGuide::class);
    }
}
