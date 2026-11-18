<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honor an optional Idempotency-Key header on unsafe endpoints: retrying the
 * same request with the same key replays the recorded response instead of
 * performing the action twice.
 *
 * The key is reserved (unique insert) BEFORE the action runs, so concurrent
 * retries cannot both execute it; recorded bodies are encrypted at rest
 * because some responses carry secrets shown only once.
 */
class EnforceIdempotency
{
    /**
     * How long a recorded response is replayed before the key may be reused.
     */
    public const RETENTION_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return $next($request);
        }

        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9\-_]{1,255}$/', $key)) {
            return response()->json(['message' => 'The Idempotency-Key header must be 1-255 characters (letters, digits, dashes, underscores).'], 422);
        }

        $credential = $this->resolveCredential($request);

        if ($credential === null) {
            return $next($request); // Unauthenticated requests never reach the action anyway.
        }

        $requestHash = hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            $request->getQueryString() ?? '',
            // Multipart bodies have no raw content; fall back to parsed input.
            $request->getContent() ?: json_encode($request->input()),
        ]));

        // Reserve the key before running the action: of two concurrent
        // retries, exactly one wins the unique index and executes.
        try {
            $reservation = IdempotencyKey::create([
                'credential' => $credential,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->respondFromExistingRecord($request, $next, $credential, $key, $requestHash);
        }

        return $this->runAndRecord($request, $next, $reservation);
    }

    private function respondFromExistingRecord(Request $request, Closure $next, string $credential, string $key, string $requestHash): Response
    {
        $recorded = IdempotencyKey::where('credential', $credential)
            ->where('idempotency_key', $key)
            ->first();

        if ($recorded === null) {
            // The earlier holder gave up (5xx) between our insert and lookup.
            return $next($request);
        }

        if ($recorded->created_at->lte(now()->subHours(self::RETENTION_HOURS))) {
            $recorded->delete();

            return $this->handle($request, $next);
        }

        if ($recorded->request_hash !== $requestHash) {
            return response()->json(['message' => 'This Idempotency-Key was already used with a different request.'], 409);
        }

        if ($recorded->response_status === null) {
            return response()->json(['message' => 'A request with this Idempotency-Key is still in progress. Retry shortly.'], 409);
        }

        return response(Crypt::decryptString($recorded->response_body), $recorded->response_status)
            ->header('Content-Type', 'application/json')
            ->header('Idempotency-Replayed', 'true');
    }

    private function runAndRecord(Request $request, Closure $next, IdempotencyKey $reservation): Response
    {
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $reservation->delete(); // Let the client retry a crashed attempt.

            throw $exception;
        }

        if ($response->getStatusCode() >= 500) {
            // A server error should be genuinely retryable.
            $reservation->delete();

            return $response;
        }

        $reservation->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => Crypt::encryptString($response->getContent() ?: ''),
        ]);

        return $response;
    }

    private function resolveCredential(Request $request): ?string
    {
        $apiKey = $request->attributes->get('apiKey');

        if ($apiKey instanceof ApiKey) {
            return 'api-key:'.$apiKey->id;
        }

        if ($user = $request->user()) {
            return 'user:'.$user->id;
        }

        return null;
    }
}
