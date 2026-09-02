<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class TransactionalMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new RuntimeException('Transactional messages are durable records.'));
    }
}
