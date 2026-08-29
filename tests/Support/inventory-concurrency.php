<?php

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || DB::getDriverName() !== 'mysql') {
    fwrite(STDERR, "Concurrency QA must run only in testing against MySQL.\n");
    exit(2);
}

$suffix = strtolower(Str::random(10));
$product = Product::query()->create([
    'name' => 'Concurrency QA',
    'slug' => 'concurrency-qa-'.$suffix,
    'status' => 'draft',
]);
$variant = ProductVariant::query()->create([
    'product_id' => $product->getKey(),
    'sku' => 'QA-'.strtoupper($suffix),
    'title' => 'Concurrency QA',
    'price_minor' => 100,
    'currency' => 'IRR',
    'is_active' => true,
]);
$location = InventoryLocation::query()->create([
    'code' => 'QA-LOC-'.strtoupper($suffix),
    'name' => 'Concurrency QA',
    'is_active' => true,
]);

$processes = [];
foreach ([1, 2] as $index) {
    $process = new Process([
        PHP_BINARY,
        'artisan',
        'sole:inventory:adjust',
        $variant->sku,
        $location->code,
        '1',
        'concurrency-qa',
        '--request-id='.(string) Str::uuid(),
    ], dirname(__DIR__, 2));
    $process->setTimeout(30);
    $process->start();
    $processes[$index] = $process;
}

$failed = false;
foreach ($processes as $process) {
    $process->wait();
    if (! $process->isSuccessful()) {
        $failed = true;
        fwrite(STDERR, $process->getErrorOutput().$process->getOutput());
    }
}

$balance = InventoryBalance::query()
    ->where('product_variant_id', $variant->getKey())
    ->where('inventory_location_id', $location->getKey())
    ->first();

if ($failed || $balance === null || (int) $balance->on_hand !== 2) {
    fwrite(STDERR, 'Inventory concurrency QA failed; expected on_hand=2.'.PHP_EOL);
    exit(1);
}

echo "Inventory concurrency QA passed with on_hand=2.\n";
