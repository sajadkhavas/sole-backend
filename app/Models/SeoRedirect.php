<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoRedirect extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status_code' => 'integer', 'is_active' => 'boolean'];
    }
}
