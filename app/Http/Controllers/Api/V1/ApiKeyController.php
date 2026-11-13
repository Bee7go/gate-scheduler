<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateApiKeyRequest;
use App\Http\Requests\Api\V1\ListApiKeysRequest;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyController extends Controller
{
    use RespondsWithPagination;

    public function index(ListApiKeysRequest $request): JsonResponse
    {
        $apiKeys = $request->user()
            ->apiKeys()
            ->select(['id', 'name', 'description', 'last_used_at', 'expires_at', 'created_at'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->paginatedResponse($apiKeys);
    }

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
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'data' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'token_type' => 'ApiKey',
                'api_key' => $plainTextApiKey,
                'created_at' => $apiKey->created_at?->toJSON(),
                'expires_at' => $apiKey->expires_at?->toJSON(),
            ],
        ], 201);
    }

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
