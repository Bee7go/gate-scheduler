<?php

use App\Http\ApiErrorCode;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateBearerToken;
use App\Http\Middleware\EnforceIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // One stable summary for every validation failure; the per-field
        // detail lives in `errors`. Keeps the message independent of which
        // field happened to fail first.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiErrorCode::ValidationFailed->response(
                    'The request failed validation.',
                    $e->status,
                    ['errors' => $e->errors()],
                );
            }
        });

        // The framework-generated errors carry a code too, with their
        // original message text preserved. The throttle response must keep
        // the exception's headers (Retry-After and the X-RateLimit pair).
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiErrorCode::RateLimited
                    ->response($e->getMessage() ?: 'Too Many Attempts.', 429)
                    ->withHeaders($e->getHeaders());
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiErrorCode::NotFound->response($e->getMessage() ?: 'The requested resource was not found.', 404);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiErrorCode::MethodNotAllowed
                    ->response($e->getMessage() ?: 'Method not allowed.', 405)
                    ->withHeaders($e->getHeaders()); // Keeps the Allow header.
            }
        });
    })->create();
