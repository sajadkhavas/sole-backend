<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('users.view'); }
    public function view(User $user, User $model): bool { return $user->hasPermission('users.view'); }
    public function create(User $user): bool { return $user->hasPermission('users.manage'); }
    public function update(User $user, User $model): bool { return $user->hasPermission('users.manage'); }
    public function delete(User $user, User $model): bool { return false; }
}
