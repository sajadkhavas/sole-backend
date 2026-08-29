<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    protected $fillable = ['name', 'slug', 'status'];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
