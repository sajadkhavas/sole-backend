<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AnalyticsEvent extends Model
{
    use HasUuids;

    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'session_id', 'taxonomy_version', 'event_name', 'route_name', 'properties',
        'trace_id', 'occurred_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Analytics event evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Analytics event evidence is append-only.'));
    }
}
