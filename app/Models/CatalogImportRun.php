<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogImportRun extends Model
{
    protected $fillable = ['uuid', 'manifest_sha256', 'manifest_version', 'source', 'status', 'report', 'applied_at'];

    protected function casts(): array
    {
        return ['report' => 'array', 'applied_at' => 'datetime'];
    }
}
