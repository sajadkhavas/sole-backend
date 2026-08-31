<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy extends CatalogPolicy
{
    public function review(User $user, Product $product): bool
    {
        return $user->hasPermission('catalog.update');
    }

    public function publish(User $user, Product $product): bool
    {
        return $user->hasPermission('catalog.publish');
    }

    public function rollbackPublication(User $user, Product $product): bool
    {
        return $user->hasPermission('catalog.publish');
    }

    public function archive(User $user, Product $product): bool
    {
        return $user->hasPermission('catalog.archive');
    }
}
