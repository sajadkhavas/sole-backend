<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class NotificationSignal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['facts' => 'array', 'eligible_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new RuntimeException('Notification signals are durable records.'));
    }
}
