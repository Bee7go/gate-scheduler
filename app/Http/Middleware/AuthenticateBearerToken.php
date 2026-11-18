<?php

namespace App\Http\Middleware;

use App\Http\ApiErrorCode;
use App\Models\AccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenValue = $request->bearerToken();

        if (! $tokenValue) {
            return ApiErrorCode::AuthenticationFailed->response('Bearer token required.', 401);
        }

        try {
            $accessToken = AccessToken::query()
                ->with('user')
                ->where('token', hash('sha256', $tokenValue))
                ->where('expires_at', '>', now())
                ->first();
        } catch (\Throwable $exception) {
            report($exception);

            return ApiErrorCode::ServiceUnavailable->response('Service unavailable.', 503);
        }

        if (! $accessToken || ! $accessToken->user) {
            return ApiErrorCode::AuthenticationFailed->response('Invalid or expired bearer token.', 401);
        }

        $accessToken->update(['last_used_at' => now()]);

        $request->setUserResolver(static fn () => $accessToken->user);
        Auth::setUser($accessToken->user);

        return $next($request);
    }
}
