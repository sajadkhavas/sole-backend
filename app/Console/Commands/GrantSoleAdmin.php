<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RbacProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GrantSoleAdmin extends Command
{
    protected $signature = 'sole:admin:grant {email : Existing administrator email}';

    protected $description = 'Explicitly activate an existing user and grant the SOLE super-admin role';

    public function handle(RbacProvisioner $provisioner, AuditLogger $auditLogger): int
    {
        $provisioner->sync();

        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('User not found. Create the user through an approved operator workflow first.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $auditLogger): void {
            $role = Role::query()->where('slug', 'super-admin')->firstOrFail();
            $alreadyGranted = $user->roles()->whereKey($role->getKey())->exists();
            $wasActive = $user->is_active;

            $user->roles()->syncWithoutDetaching([$role->getKey()]);

            if (! $user->is_active) {
                $user->forceFill(['is_active' => true])->save();
            }

            if (! $alreadyGranted || ! $wasActive) {
                $auditLogger->record('admin.access.granted', $user, [
                    'is_active' => $wasActive,
                    'role' => $alreadyGranted ? 'super-admin' : null,
                ], [
                    'is_active' => true,
                    'role' => 'super-admin',
                ]);
            }
        });

        $this->info('SOLE super-admin access granted to the existing user.');

        return self::SUCCESS;
    }
}
