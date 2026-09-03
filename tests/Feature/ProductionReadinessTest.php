<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    public function test_production_readiness_command_accepts_secure_runtime_contract(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config()->set([
            'app.debug' => false,
            'app.url' => 'https://api.sole.example',
            'app.prototype_mode' => false,
            'sole.public_site_url' => 'https://sole.example',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 90,
            'queue.failed.driver' => 'database-uuids',
            'cache.default' => 'database',
        ]);

        $this->assertSame(0, Artisan::call('sole:production:check', ['--json' => true]));
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['pass']);
        $this->assertSame([], $payload['failed']);
    }

    public function test_production_readiness_fails_closed_when_debug_or_queue_timing_is_unsafe(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config()->set([
            'app.debug' => true,
            'app.url' => 'https://api.sole.example',
            'app.prototype_mode' => false,
            'sole.public_site_url' => 'https://sole.example',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 60,
            'queue.failed.driver' => 'database-uuids',
            'cache.default' => 'database',
        ]);

        $this->assertSame(1, Artisan::call('sole:production:check', ['--json' => true]));
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('debug_is_disabled', $payload['failed']);
        $this->assertContains('queue_timeout_precedes_retry_after', $payload['failed']);
    }

    public function test_release_and_recovery_contracts_are_fail_closed_and_secret_safe(): void
    {
        $root = base_path();
        $queue = file_get_contents($root.'/deploy/systemd/sole-backend-queue.service.example');
        $backup = file_get_contents($root.'/scripts/production/mysql-backup.sh');
        $restore = file_get_contents($root.'/scripts/production/mysql-restore-drill.sh');
        $activate = file_get_contents($root.'/scripts/production/activate-release.sh');

        $this->assertStringContainsString('--timeout=60', $queue);
        $this->assertStringContainsString('KillMode=control-group', $queue);
        $this->assertStringContainsString('ProtectSystem=strict', $queue);
        $this->assertStringContainsString('--single-transaction', $backup);
        $this->assertStringContainsString('MYSQL_DEFAULTS_FILE', $backup);
        $this->assertStringNotContainsString('DB_PASSWORD=', $backup);
        $this->assertStringContainsString('sole_restore_', $restore);
        $this->assertStringContainsString('P13_OR_P14_APPROVED', $activate);
        $this->assertStringContainsString('BACKWARD_COMPATIBLE_REVIEWED', $activate);
    }
}
