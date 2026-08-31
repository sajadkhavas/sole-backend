<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ConsentRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'granted',
        'policy_version',
        'source',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent history is append-only.'));
        static::deleting(fn () => throw new LogicException('Consent history is append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
