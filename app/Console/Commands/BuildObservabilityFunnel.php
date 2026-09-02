<?php

namespace App\Console\Commands;

use App\Services\Observability\FunnelSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BuildObservabilityFunnel extends Command
{
    protected $signature = 'sole:observability:funnel {date? : YYYY-MM-DD, defaults to today}';
    protected $description = 'Rebuild the first-party consented analytics funnel snapshot.';

    public function handle(FunnelSnapshotService $funnels): int
    {
        $raw = $this->argument('date');
        try {
            $day = $raw === null ? CarbonImmutable::today() : CarbonImmutable::createFromFormat('!Y-m-d', (string) $raw);
        } catch (\Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');
            return self::FAILURE;
        }
        if (! $day instanceof CarbonImmutable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');
            return self::FAILURE;
        }
        $snapshot = $funnels->rebuild($day);
        $this->info("Funnel rebuilt for {$snapshot->snapshot_date->toDateString()}.");
        return self::SUCCESS;
    }
}
