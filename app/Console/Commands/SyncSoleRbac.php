<?php

namespace App\Console\Commands;

use App\Services\RbacProvisioner;
use Illuminate\Console\Command;

class SyncSoleRbac extends Command
{
    protected $signature = 'sole:rbac:sync';

    protected $description = 'Synchronize SOLE-owned roles and permissions without granting access to any user';

    public function handle(RbacProvisioner $provisioner): int
    {
        $provisioner->sync();
        $this->info('SOLE RBAC synchronized. No user access was granted.');

        return self::SUCCESS;
    }
}
