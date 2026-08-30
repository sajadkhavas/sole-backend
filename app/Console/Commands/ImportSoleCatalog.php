<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportSoleCatalog extends Command
{
    protected $signature = 'sole:catalog:import {manifest} {--apply}';

    protected $description = 'Dry-run or idempotently apply a versioned SOLE catalog/media manifest';

    public function handle(CatalogImportService $imports): int
    {
        try {
            $result = $imports->fromFile((string) $this->argument('manifest'), (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
