<?php

use App\Models\User;
use App\Services\Engagement\LoyaltyLedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || DB::getDriverName() !== 'mysql') {
    fwrite(STDERR, "Loyalty worker must run only in testing against MySQL.\n");
    exit(2);
}

$user = User::query()->findOrFail((int) ($argv[1] ?? 0));
$key = (string) ($argv[2] ?? '');

try {
    app(LoyaltyLedgerService::class)->redeem($user, 80, $key, 'concurrency_qa');
    exit(0);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Insufficient loyalty balance.') {
        exit(3);
    }

    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
