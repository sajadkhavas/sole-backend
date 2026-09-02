<?php

namespace App\Console\Commands;

use App\Services\Engagement\LoyaltyLedgerService;
use App\Services\Engagement\NotificationOrchestrator;
use Illuminate\Console\Command;

class ProcessSoleEngagement extends Command
{
    protected $signature = 'sole:engagement:process {--limit=100}';

    protected $description = 'Materialize authoritative engagement signals, enforce delivery policy, and expire due loyalty points.';

    public function handle(NotificationOrchestrator $orchestrator, LoyaltyLedgerService $ledger): int
    {
        $scan = $orchestrator->scan();
        $dispatched = $orchestrator->dispatchPending((int) $this->option('limit'));
        $expired = $ledger->expireDue();

        $this->components->info(sprintf(
            'Signals: back-in-stock=%d price-drop=%d lifecycle=%d; processed=%d; loyalty-expiry=%d.',
            $scan['back_in_stock'],
            $scan['price_drop'],
            $scan['order_lifecycle'],
            $dispatched,
            $expired,
        ));

        return self::SUCCESS;
    }
}
