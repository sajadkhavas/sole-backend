<?php

use App\Http\Middleware\ObserveRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->appendToGroup('api', [ObserveRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // P11 request middleware records sanitized exception class/fingerprint and rethrows.
    })->create();
