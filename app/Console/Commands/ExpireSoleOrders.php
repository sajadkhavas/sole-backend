<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Commerce\OrderStateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sole:orders:expire')]
#[Description('Release expired checkout inventory reservations and expire their orders')]
class ExpireSoleOrders extends Command
{
    public function handle(OrderStateService $states): int
    {
        $count = 0;
        Order::query()
            ->where('status', 'awaiting_payment')
            ->where('reservation_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($states, &$count): void {
                foreach ($orders as $order) {
                    $states->transition($order, 'expired', 'reservation_expired');
                    $count++;
                }
            });

        $this->info("Expired {$count} order(s).");

        return self::SUCCESS;
    }
}
