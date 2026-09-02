<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class SupportCaseEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new RuntimeException('Support case events are append-only.'));
        static::deleting(fn (): never => throw new RuntimeException('Support case events are append-only.'));
    }
}
