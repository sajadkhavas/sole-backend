<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('catalog.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('catalog.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('catalog.create');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('catalog.update');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }
}
