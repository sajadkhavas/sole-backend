<?php

namespace App\Policies;

use App\Models\InventoryLocation;
use App\Models\User;

class InventoryLocationPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('inventory.view'); }
    public function view(User $user, InventoryLocation $location): bool { return $user->hasPermission('inventory.view'); }
    public function create(User $user): bool { return $user->hasPermission('inventory.adjust'); }
    public function update(User $user, InventoryLocation $location): bool { return $user->hasPermission('inventory.adjust'); }
    public function delete(User $user, InventoryLocation $location): bool { return false; }
}
