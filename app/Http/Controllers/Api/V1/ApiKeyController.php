<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateApiKeyRequest;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $apiKeys = $request->user()
            ->apiKeys()
            ->select(['id', 'name', 'description', 'last_used_at', 'expires_at', 'created_at'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'current_page' => $apiKeys->currentPage(),
            'data' => $apiKeys->items(),
            'last_page' => $apiKeys->lastPage(),
            'total' => $apiKeys->total(),
        ]);
    }

    public function store(CreateApiKeyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $plainTextApiKey = Str::random(64);
        $expiresAt = now()->addYear();

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'key' => hash('sha256', $plainTextApiKey),
            'last_used_at' => now(),
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
            abort(404);
        }

        $apiKey->delete();

        return response()->noContent();
    }
}
