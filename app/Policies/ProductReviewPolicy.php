<?php

namespace App\Policies;

class ProductReviewPolicy extends OperationalPolicy
{
    protected const VIEW_PERMISSION = 'reviews.view';
    protected const MANAGE_PERMISSION = 'reviews.moderate';
}
