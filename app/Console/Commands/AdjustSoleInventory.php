<?php

namespace App\Console\Commands;

use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Services\InventoryLedger;
use Illuminate\Console\Command;

class AdjustSoleInventory extends Command
{
    protected $signature = 'sole:inventory:adjust
        {sku : Product variant SKU}
        {location : Inventory location code}
        {delta : Signed inventory delta}
        {reason : Required adjustment reason}
        {--request-id= : Optional UUID idempotency key}';

    protected $description = 'Adjust SOLE inventory through the append-only transactional ledger';

    public function handle(InventoryLedger $ledger): int
    {
        $variant = ProductVariant::query()->where('sku', $this->argument('sku'))->first();
        $location = InventoryLocation::query()->where('code', $this->argument('location'))->first();

        if ($variant === null || $location === null) {
            $this->error('Variant or inventory location was not found.');

            return self::FAILURE;
        }

        $movement = $ledger->adjust(
            $variant,
            $location,
            (int) $this->argument('delta'),
            (string) $this->argument('reason'),
            $this->option('request-id') ?: null,
        );

        $this->info("Inventory movement {$movement->getKey()} recorded.");

        return self::SUCCESS;
    }
}
