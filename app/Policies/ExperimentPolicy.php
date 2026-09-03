<?php

namespace App\Policies;

use App\Models\Experiment;
use App\Models\User;

class ExperimentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('observability.view');
    }

    public function view(User $user, Experiment $experiment): bool
    {
        return $user->hasPermission('observability.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('experiments.manage');
    }

    public function update(User $user, Experiment $experiment): bool
    {
        return $user->hasPermission('experiments.manage') && $experiment->status !== 'running';
    }

    public function delete(User $user, Experiment $experiment): bool
    {
        return false;
    }
}
