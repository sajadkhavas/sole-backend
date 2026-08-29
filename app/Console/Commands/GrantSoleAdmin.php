<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\RbacProvisioner;
use Illuminate\Console\Command;

class GrantSoleAdmin extends Command
{
    protected $signature = 'sole:admin:grant {email : Existing administrator email}';

    protected $description = 'Explicitly activate an existing user and grant the SOLE super-admin role';

    public function handle(RbacProvisioner $provisioner): int
    {
        $provisioner->sync();

        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('User not found. Create the user through an approved operator workflow first.');

            return self::FAILURE;
        }

        $role = Role::query()->where('slug', 'super-admin')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->getKey()]);
        $user->forceFill(['is_active' => true])->save();

        $this->info('SOLE super-admin access granted to the existing user.');

        return self::SUCCESS;
    }
}
