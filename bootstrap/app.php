<?php

use App\Http\Middleware\RedirectToCanonicalHost;
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
        // SPA живёт на том же домене, поэтому авторизуемся через куки сессии, а не через токены.
        $middleware->statefulApi();

        $middleware->prepend(RedirectToCanonicalHost::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
