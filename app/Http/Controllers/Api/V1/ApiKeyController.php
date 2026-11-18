<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateApiKeyRequest;
use App\Http\Requests\Api\V1\ListApiKeysRequest;
use App\Http\Resources\Api\V1\ApiKeyResource;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyController extends Controller
{
    use RespondsWithPagination;

    /**
     * List API keys
     *
     * Lists metadata for the authenticated user's API keys. Key values are
     * never returned after creation.
     */
    public function index(ListApiKeysRequest $request): JsonResponse
    {
        $apiKeys = $request->user()
            ->apiKeys()
            ->select(['id', 'name', 'description', 'scopes', 'rate_limit_per_minute', 'last_used_at', 'expires_at', 'created_at'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => ApiKeyResource::collection($apiKeys->items()),
            'meta' => $this->paginationMeta($apiKeys),
        ]);
    }

    /**
     * Create an API key
     *
     * The plain-text key is returned once, at creation — store it safely.
     * Keys expire after 1 year.
     */
    public function store(CreateApiKeyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $plainTextApiKey = Str::random(64);
        $expiresAt = now()->addDays((int) config('services.auth.api_key_ttl_days'));

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'key' => hash('sha256', $plainTextApiKey),
            'scopes' => $validated['scopes'] ?? null,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'data' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'token_type' => 'ApiKey',
                'api_key' => $plainTextApiKey,
                'scopes' => $apiKey->scopes,
                'created_at' => $apiKey->created_at?->toJSON(),
                'expires_at' => $apiKey->expires_at?->toJSON(),
            ],
        ], 201);
    }

    /**
     * Revoke an API key
     *
     * Permanently deletes one of the authenticated user's API keys.
     */
    public function destroy(Request $request, int $apiKey): Response
    {
        $apiKey = $request->user()
            ->apiKeys()
            ->find($apiKey);

        if (! $apiKey) {
            return response()->json(['message' => 'API key not found.'], 404);
        }

        $apiKey->delete();

        return response()->noContent();
    }
}
