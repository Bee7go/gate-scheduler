<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * The closed catalog of machine-readable error codes the API emits.
 *
 * Codes are part of the v1 contract: a published code never changes meaning
 * or disappears; new codes may be added in minor releases. Clients branch on
 * `code` — the human-readable `message` wording is not a contract.
 */
enum ApiErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case AuthenticationFailed = 'authentication_failed';
    case InsufficientScope = 'insufficient_scope';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case IdempotencyConflict = 'idempotency_conflict';
    case IdempotencyInProgress = 'idempotency_in_progress';
    case SyncInProgress = 'sync_in_progress';
    case RateLimited = 'rate_limited';
    case ServiceUnavailable = 'service_unavailable';

    /**
     * The standard error body: code and message, plus optional extras such as
     * the per-field `errors` map on validation failures.
     */
    public function response(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(
            ['code' => $this->value, 'message' => $message] + $extra,
            $status
        );
    }
}
