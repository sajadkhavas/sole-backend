<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevokeSoleAdmin extends Command
{
    protected $signature = 'sole:admin:revoke {email : Existing administrator email}';

    protected $description = 'Explicitly revoke SOLE super-admin access and deactivate the administrator';

    public function handle(AuditLogger $auditLogger): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $auditLogger): void {
            $role = Role::query()->where('slug', 'super-admin')->first();
            $hadRole = $role !== null && $user->roles()->whereKey($role->getKey())->exists();
            $wasActive = $user->is_active;

            if ($role !== null) {
                $user->roles()->detach($role->getKey());
            }

            if ($user->is_active) {
                $user->forceFill(['is_active' => false])->save();
            }

            if ($hadRole || $wasActive) {
                $auditLogger->record('admin.access.revoked', $user, [
                    'is_active' => $wasActive,
                    'role' => $hadRole ? 'super-admin' : null,
                ], [
                    'is_active' => false,
                    'role' => null,
                ]);
            }
        });

        $this->info('SOLE super-admin access revoked.');

        return self::SUCCESS;
    }
}
