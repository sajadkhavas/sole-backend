<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ProductReview extends Model
{
    protected static bool $stateMutationAllowed = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'moderated_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $review): void {
            if ($review->isDirty(['status', 'moderated_by', 'moderated_at', 'published_at']) && ! static::$stateMutationAllowed) {
                throw new RuntimeException('Review status may only change through the moderation service.');
            }
            if ($review->isDirty(['user_id', 'order_id', 'order_item_id', 'product_variant_id', 'rating', 'title', 'body'])) {
                throw new RuntimeException('Submitted review evidence is immutable.');
            }
        });
        static::deleting(fn (): never => throw new RuntimeException('Reviews are durable moderation records.'));
    }

    public static function withinStateTransition(Closure $callback): mixed
    {
        $previous = static::$stateMutationAllowed;
        static::$stateMutationAllowed = true;
        try {
            return $callback();
        } finally {
            static::$stateMutationAllowed = $previous;
        }
    }
}
