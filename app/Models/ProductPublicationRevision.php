<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProductPublicationRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid', 'product_id', 'actor_id', 'action', 'before', 'after', 'rollback_of_uuid', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Publication revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Publication revisions are append-only.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
