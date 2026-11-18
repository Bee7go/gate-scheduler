<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give every request an id that appears on the response (X-Request-Id) and in
 * every log line written while handling it, so a client-reported id can be
 * traced through the logs.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = $request->header('X-Request-Id');

        // Honor a client-supplied id so it can correlate with its own tracing,
        // but only a well-formed one: anything else could pollute the logs.
        if (is_string($incoming) && preg_match('/^[A-Za-z0-9\-_]{1,64}$/', $incoming)) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
