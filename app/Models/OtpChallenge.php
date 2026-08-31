<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpChallenge extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'phone_e164',
        'purpose',
        'code_digest',
        'attempt_count',
        'max_attempts',
        'expires_at',
        'resend_available_at',
        'consumed_at',
        'request_ip',
    ];

    protected $hidden = ['code_digest'];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
