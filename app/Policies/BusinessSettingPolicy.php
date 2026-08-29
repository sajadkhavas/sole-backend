<?php

namespace App\Policies;

use App\Models\BusinessSetting;
use App\Models\User;

class BusinessSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('settings.view');
    }

    public function view(User $user, BusinessSetting $setting): bool
    {
        return $user->hasPermission('settings.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('settings.update');
    }

    public function update(User $user, BusinessSetting $setting): bool
    {
        return $user->hasPermission('settings.update');
    }

    public function delete(User $user, BusinessSetting $setting): bool
    {
        return false;
    }
}
