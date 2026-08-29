<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        if ((bool) config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }

        if ((bool) config('sole.prototype_mode')) {
            throw new RuntimeException('Prototype, mock, and test modes are forbidden in production.');
        }
    }
}
