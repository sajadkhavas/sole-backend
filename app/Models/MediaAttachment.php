<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAttachment extends Model
{
    protected $fillable = ['media_asset_id', 'subject_type', 'subject_id', 'role', 'sort_order', 'alt_text'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
