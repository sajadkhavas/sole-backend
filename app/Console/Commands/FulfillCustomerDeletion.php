<?php

namespace App\Console\Commands;

use App\Services\Auth\CustomerAccountLifecycleService;
use Illuminate\Console\Command;

class FulfillCustomerDeletion extends Command
{
    protected $signature = 'sole:account:fulfill-deletion {request}';

    protected $description = 'Pseudonymize a customer after an accepted deletion request.';

    public function handle(CustomerAccountLifecycleService $service): int
    {
        $service->fulfillDeletion((string) $this->argument('request'));
        $this->components->info('Customer deletion fulfilled.');

        return self::SUCCESS;
    }
}
