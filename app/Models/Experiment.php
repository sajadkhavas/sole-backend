<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Experiment extends Model
{
    protected $fillable = [
        'key', 'version', 'status', 'surface', 'hypothesis', 'primary_metric', 'guardrail_metrics',
        'variants', 'allocation_basis_points', 'minimum_sample_size', 'rollback_plan', 'created_by',
        'activated_by', 'starts_at', 'stops_at',
    ];

    protected function casts(): array
    {
        return [
            'guardrail_metrics' => 'array',
            'variants' => 'array',
            'allocation_basis_points' => 'array',
            'starts_at' => 'datetime',
            'stops_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
