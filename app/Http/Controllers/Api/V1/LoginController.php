<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginUserRequest;
use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function store(LoginUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()
            ->where('email', strtolower($validated['email']))
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $expiresInSeconds = 3600;
        $expiresAt = now()->addSeconds($expiresInSeconds);
        $plainTextAccessToken = Str::random(80);

        AccessToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plainTextAccessToken),
            'expires_at' => $expiresAt,
            'last_used_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $plainTextAccessToken,
                'expires_in' => $expiresInSeconds,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }
}