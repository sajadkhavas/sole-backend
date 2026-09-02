<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ObservabilityReadPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('observability.view'); }
    public function view(User $user, Model $model): bool { return $user->hasPermission('observability.view'); }
    public function create(User $user): bool { return false; }
    public function update(User $user, Model $model): bool { return false; }
    public function delete(User $user, Model $model): bool { return false; }
}
