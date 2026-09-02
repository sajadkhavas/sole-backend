<?php

namespace App\Policies;

use App\Models\SeoRoutePolicy;
use App\Models\User;

class SeoRoutePolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('content.view');
    }

    public function view(User $user, SeoRoutePolicy $policy): bool
    {
        return $user->hasPermission('content.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('content.create');
    }

    public function update(User $user, SeoRoutePolicy $policy): bool
    {
        return $user->hasPermission('content.update');
    }

    public function delete(User $user, SeoRoutePolicy $policy): bool
    {
        return false;
    }
}
