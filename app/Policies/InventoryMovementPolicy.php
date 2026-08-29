<?php

namespace App\Policies;

use App\Models\InventoryMovement;
use App\Models\User;

class InventoryMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory.view');
    }

    public function view(User $user, InventoryMovement $movement): bool
    {
        return $user->hasPermission('inventory.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, InventoryMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, InventoryMovement $movement): bool
    {
        return false;
    }
}
