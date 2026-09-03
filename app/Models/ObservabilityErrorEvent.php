<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ObservabilityErrorEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'request_id', 'trace_id', 'span_id', 'route_name', 'method', 'status_code',
        'exception_class', 'fingerprint', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Observability error evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Observability error evidence is append-only.'));
    }
}
