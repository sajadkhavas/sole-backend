<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class NotificationDeliveryAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new RuntimeException('Delivery attempts are append-only.'));
        static::deleting(fn (): never => throw new RuntimeException('Delivery attempts are append-only.'));
    }
}
