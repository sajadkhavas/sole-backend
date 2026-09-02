<?php

use App\Models\User;
use App\Services\Engagement\LoyaltyLedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || DB::getDriverName() !== 'mysql') {
    fwrite(STDERR, "Loyalty concurrency QA must run only in testing against MySQL.\n");
    exit(2);
}

$user = User::factory()->create();
$ledger = app(LoyaltyLedgerService::class);
$ledger->earn($user, 100, 'concurrency-earn-'.Str::uuid(), 'concurrency_qa');

$processes = [];
foreach ([1, 2] as $index) {
    $process = new Process([
        PHP_BINARY,
        'tests/Support/loyalty-redeem-worker.php',
        (string) $user->id,
        'concurrency-redeem-'.$index.'-'.Str::uuid(),
    ], dirname(__DIR__, 2));
    $process->setTimeout(30);
    $process->start();
    $processes[] = $process;
}

$successes = 0;
$insufficient = 0;
foreach ($processes as $process) {
    $process->wait();
    if ($process->getExitCode() === 0) {
        $successes++;
    } elseif ($process->getExitCode() === 3) {
        $insufficient++;
    } else {
        fwrite(STDERR, $process->getErrorOutput().$process->getOutput());
        exit(1);
    }
}

$user->refresh();
$balance = $ledger->balance($user);
if ($successes !== 1 || $insufficient !== 1 || $balance !== 20) {
    fwrite(STDERR, "Loyalty concurrency QA failed; expected one redemption and balance=20.\n");
    exit(1);
}

echo "Loyalty concurrency QA passed with one winner and balance=20.\n";
