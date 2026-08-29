<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $attributes = [
        'version' => 1,
    ];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (BusinessSetting $setting): void {
            if ($setting->isDirty('value')) {
                $setting->version = $setting->getOriginal('version') + 1;
            }
        });
    }
}
