<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrustContent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'approved_at' => 'immutable_datetime'];
    }
}
