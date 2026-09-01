<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FitFeedback extends Model
{
    protected $table = 'fit_feedback';

    protected $fillable = ['user_id', 'product_id', 'product_variant_id', 'purchased_size', 'overall_fit', 'width_fit', 'source'];
}
