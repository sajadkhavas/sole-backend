<?php

namespace App\Services\Observability;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsFunnelSnapshot;
use Carbon\CarbonImmutable;

class FunnelSnapshotService
{
    public function rebuild(CarbonImmutable $day): AnalyticsFunnelSnapshot
    {
        $start = $day->startOfDay();
        $end = $start->addDay();
        $count = function (string $eventName) use ($start, $end): int {
            return AnalyticsEvent::query()
                ->where('taxonomy_version', AnalyticsTaxonomy::VERSION)
                ->where('event_name', $eventName)
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<', $end)
                ->distinct()
                ->count('session_id');
        };

        return AnalyticsFunnelSnapshot::query()->updateOrCreate(
            ['snapshot_date' => $start->toDateString(), 'taxonomy_version' => AnalyticsTaxonomy::VERSION],
            [
                'catalog_sessions' => $count('catalog_view'),
                'product_sessions' => $count('product_view'),
                'cart_sessions' => $count('cart_engaged'),
                'checkout_sessions' => $count('checkout_view'),
                'order_sessions' => $count('order_created'),
                'paid_sessions' => $count('payment_paid'),
                'rebuilt_at' => now(),
            ],
        );
    }
}
