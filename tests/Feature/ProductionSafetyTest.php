<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionSafetyTest extends TestCase
{
    public function test_prototype_mode_is_rejected_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('app.debug', false);
        config()->set('sole.prototype_mode', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prototype, mock, and test modes are forbidden in production.');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_debug_mode_is_rejected_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('app.debug', true);
        config()->set('sole.prototype_mode', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must be false in production.');

        (new AppServiceProvider($this->app))->boot();
    }
}
