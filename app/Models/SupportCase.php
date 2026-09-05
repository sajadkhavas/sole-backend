<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SupportCase extends Model
{
    protected static bool $stateMutationAllowed = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sla_due_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $case): void {
            if ($case->isDirty(['status', 'priority', 'resolved_at']) && ! static::$stateMutationAllowed) {
                throw new RuntimeException('Support case state may only change through the operations service.');
            }
        });
        static::deleting(fn (): never => throw new RuntimeException('Support cases are durable records.'));
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportCaseEvent::class);
    }
}
