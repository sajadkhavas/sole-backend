<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'published_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentPageRevision::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
