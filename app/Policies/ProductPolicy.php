<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy extends CatalogPolicy
{
    public function publish(User $user, Product $product): bool
    {
        return $user->hasPermission('catalog.publish');
    }

    public function archive(User $user, Product $product): bool
    {
        return $user->hasPermission('catalog.archive');
    }
}
