<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CheckProductionReadiness extends Command
{
    protected $signature = 'sole:production:check {--json : Emit machine-readable JSON} {--connections : Verify database, cache and Redis connectivity}';

    protected $description = 'Verify secret-safe SOLE production runtime invariants without mutating commerce data.';

    public function handle(): int
    {
        $connection = (string) config('queue.default');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 0);
        $workerTimeout = (int) config('sole.production.queue_worker_timeout', 60);

        $checks = [
            'environment_is_production' => app()->environment('production'),
            'debug_is_disabled' => config('app.debug') === false,
            'app_url_is_https' => $this->isHttpsUrl(config('app.url')),
            'public_site_url_is_https' => $this->isHttpsUrl(config('sole.public_site_url')),
            'session_cookie_is_secure' => config('session.secure') === true,
            'session_cookie_is_http_only' => config('session.http_only') === true,
            'session_same_site_is_bounded' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'queue_connection_is_durable' => in_array($connection, ['database', 'redis'], true),
            'queue_timeout_precedes_retry_after' => $retryAfter > $workerTimeout,
            'queue_failed_jobs_are_persisted' => config('queue.failed.driver') === 'database-uuids',
            'cache_store_is_durable' => ! in_array(config('cache.default'), ['array', 'null'], true),
            'prototype_mode_is_disabled' => config('app.prototype_mode', false) === false,
        ];

        if ($this->option('connections')) {
            $checks += $this->connectionChecks();
        }

        $failed = array_keys(array_filter($checks, fn (bool $pass): bool => ! $pass));
        $payload = [
            'schema_version' => 1,
            'suite' => 'sole-production-readiness',
            'pass' => $failed === [],
            'checks' => $checks,
            'failed' => $failed,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($checks as $name => $pass) {
                $this->line(sprintf('%s=%s', strtoupper($name), $pass ? 'PASS' : 'FAIL'));
            }
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, bool> */
    private function connectionChecks(): array
    {
        $checks = [
            'database_connectivity' => false,
            'cache_round_trip' => false,
            'redis_connectivity' => false,
        ];

        try {
            $checks['database_connectivity'] = (int) (DB::selectOne('SELECT 1 AS ready')->ready ?? 0) === 1;
        } catch (Throwable) {
            // Failure is intentionally reduced to a boolean so credentials and connection strings never enter evidence.
        }

        try {
            $key = 'sole:p12:readiness:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 30);
            $checks['cache_round_trip'] = Cache::get($key) === 'ok';
            Cache::forget($key);
        } catch (Throwable) {
            // Secret-safe fail closed.
        }

        try {
            $pong = Redis::connection()->command('ping');
            $checks['redis_connectivity'] = in_array($pong, [true, 'PONG', '+PONG'], true);
        } catch (Throwable) {
            // Secret-safe fail closed.
        }

        return $checks;
    }

    private function isHttpsUrl(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && isset($parts['host']);
    }
}
