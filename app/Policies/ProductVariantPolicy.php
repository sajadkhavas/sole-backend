<?php

namespace App\Policies;

use App\Models\ProductVariant;
use App\Models\User;

class ProductVariantPolicy extends CatalogPolicy
{
    public function adjustInventory(User $user, ProductVariant $variant): bool
    {
        return $user->hasPermission('inventory.adjust');
    }
}
