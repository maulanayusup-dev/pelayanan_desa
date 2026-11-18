<?php

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
        // Nonaktifkan kepercayaan rentang RFC1918 secara luas; aktifkan proxy eksplisit via config('app.trusted_proxies')
        // dan validasi Host header di seluruh environment (termasuk local & testing).
        $middleware->remove(\Illuminate\Http\Middleware\TrustProxies::class);
        $middleware->prepend([
            \App\Http\Middleware\TrustConfiguredProxies::class,
            \App\Http\Middleware\EnsureTrustedHost::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'verified.citizen' => \App\Http\Middleware\EnsureCitizenEmailIsVerified::class,
            'active.user' => \App\Http\Middleware\EnsureActiveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
