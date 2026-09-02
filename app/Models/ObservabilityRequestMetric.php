<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObservabilityRequestMetric extends Model
{
    protected $fillable = [
        'bucket_started_at', 'route_name', 'method', 'status_class', 'request_count', 'error_count',
        'duration_sum_ms', 'duration_max_ms', 'duration_le_100_ms', 'duration_le_250_ms',
        'duration_le_500_ms', 'duration_le_1000_ms', 'duration_le_2500_ms', 'duration_le_5000_ms',
        'duration_gt_5000_ms',
    ];

    protected function casts(): array
    {
        return [
            'bucket_started_at' => 'datetime',
            'duration_sum_ms' => 'float',
            'duration_max_ms' => 'float',
        ];
    }
}
