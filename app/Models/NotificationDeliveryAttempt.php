<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class NotificationDeliveryAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new RuntimeException('Notification delivery audit is append-only.'));
        static::deleting(fn (): never => throw new RuntimeException('Notification delivery audit is append-only.'));
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(NotificationSignal::class, 'notification_signal_id');
    }
}
