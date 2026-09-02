<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ProductReview extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['rating' => 'integer', 'moderated_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime']; }
    protected static function booted(): void { static::deleting(fn (): never => throw new RuntimeException('Reviews are durable moderation records.')); }
}
