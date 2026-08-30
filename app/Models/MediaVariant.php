<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaVariant extends Model
{
    protected $fillable = [
        'media_asset_id', 'recipe', 'recipe_version', 'format', 'width', 'height', 'bytes', 'sha256', 'disk', 'path',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
