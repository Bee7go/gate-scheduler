<?php

namespace App\Http\Middleware;

use App\Http\ApiErrorCode;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $key = $request->header('X-Api-Key');

        if (! $key) {
            return ApiErrorCode::AuthenticationFailed->response('API key required.', 401);
        }

        try {
            $apiKey = ApiKey::where('key', hash('sha256', $key))->first();
        } catch (\Throwable $exception) {
            report($exception);

            return ApiErrorCode::ServiceUnavailable->response('Service unavailable.', 503);
        }

        if (! $apiKey) {
            return ApiErrorCode::AuthenticationFailed->response('Invalid API key.', 401);
        }

        if ($apiKey->expires_at && $apiKey->expires_at->isPast()) {
            return ApiErrorCode::AuthenticationFailed->response('API key expired.', 401);
        }

        if ($scope !== null && ! $apiKey->allows($scope)) {
            return ApiErrorCode::InsufficientScope->response("This API key is missing the required scope [{$scope}].", 403);
        }

        // Minute resolution is plenty for "last used" — and skipping redundant
        // writes stops an over-limit flood from hammering this row before the
        // rate limiter (which runs after auth) starts rejecting.
        if ($apiKey->last_used_at === null || $apiKey->last_used_at->lt(now()->subMinute())) {
            $apiKey->update(['last_used_at' => now()]);
        }

        // Downstream middleware (rate limiting, idempotency) keys off the model.
        $request->attributes->set('apiKey', $apiKey);

        return $next($request);
    }
}
