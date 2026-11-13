<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateBearerToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app is only reachable through its own reverse proxy (Caddy/nginx),
        // so trust it — otherwise $request->ip() is the proxy IP and the
        // per-IP auth rate limit would be shared by every client.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'auth.apikey' => AuthenticateApiKey::class,
            'auth.bearer' => AuthenticateBearerToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
