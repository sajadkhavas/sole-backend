<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PaymentReconciliation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'reconciled_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new RuntimeException('Payment reconciliations are append-only.'));
        static::deleting(fn (): never => throw new RuntimeException('Payment reconciliations are append-only.'));
    }
}
