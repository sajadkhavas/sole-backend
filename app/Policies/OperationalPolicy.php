<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class OperationalPolicy
{
    protected const VIEW_PERMISSION = '';

    protected const MANAGE_PERMISSION = null;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(static::VIEW_PERMISSION);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return static::MANAGE_PERMISSION !== null && $user->hasPermission(static::MANAGE_PERMISSION);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
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
