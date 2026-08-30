<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    public const STATUS_PENDING = 'pending_upload';
    public const STATUS_READY = 'ready';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'uuid', 'created_by', 'status', 'original_filename', 'declared_mime', 'detected_mime',
        'bytes', 'width', 'height', 'frame_count', 'sha256', 'quarantine_disk', 'quarantine_path',
        'source_disk', 'source_path', 'focal_x', 'focal_y', 'alt_text', 'rejection_code', 'metadata', 'ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'ingested_at' => 'datetime',
            'focal_x' => 'float',
            'focal_y' => 'float',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }
}
