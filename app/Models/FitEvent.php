<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FitEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'event_name', 'confidence_bucket', 'recommended_size', 'request_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
