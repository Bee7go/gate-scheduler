<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateBearerToken;
use App\Http\Middleware\EnforceIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;

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

        $middleware->api(prepend: [AssignRequestId::class]);

        // The framework sorts throttling high in the pipeline; our key auth
        // must run before it so the per-key limiter can see the resolved key.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: AuthenticateApiKey::class,
        );

        $middleware->alias([
            'auth.apikey' => AuthenticateApiKey::class,
            'auth.bearer' => AuthenticateBearerToken::class,
            'idempotency' => EnforceIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API must never answer with a browser redirect: a client that
        // forgets the Accept header still gets JSON errors.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
