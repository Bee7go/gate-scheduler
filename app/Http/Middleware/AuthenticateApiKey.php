<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key');

        if (!$key) {
            return response()->json(['message' => 'API key required.'], 401);
        }

        $apiKey = ApiKey::where('key', hash('sha256', $key))->first();

        if (!$apiKey) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        if ($apiKey->expires_at && $apiKey->expires_at->isPast()) {
            return response()->json(['message' => 'API key expired.'], 401);
        }

        $apiKey->update(['last_used_at' => now()]);

        return $next($request);
    }
}
