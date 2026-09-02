<?php

namespace App\Policies;

use App\Models\ContentPage;
use App\Models\User;

class ContentPagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('content.view');
    }

    public function view(User $user, ContentPage $page): bool
    {
        return $user->hasPermission('content.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('content.create');
    }

    public function update(User $user, ContentPage $page): bool
    {
        return $user->hasPermission('content.update');
    }

    public function delete(User $user, ContentPage $page): bool
    {
        return false;
    }

    public function review(User $user, ContentPage $page): bool
    {
        return $user->hasPermission('content.review');
    }

    public function publish(User $user, ContentPage $page): bool
    {
        return $user->hasPermission('content.publish');
    }
}
