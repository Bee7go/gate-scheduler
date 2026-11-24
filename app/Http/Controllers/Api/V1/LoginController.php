<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginUserRequest;
use App\Models\AccessToken;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Group('Authentication')]
class LoginController extends Controller
{
    /**
     * Log in
     *
     * Exchange email and password for a Bearer token used on the API-key
     * management endpoints. The token is shown once and expires after 1 hour.
     */
    public function store(LoginUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()
            ->where('email', strtolower($validated['email']))
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            // Literal array (not the enum helper) so Scramble can infer the
            // response schema for the spec.
            return response()->json([
                'code' => ApiErrorCode::AuthenticationFailed->value,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        // Transparent hash upgrades: a password stored under an older driver
        // or cost (e.g. bcrypt from before the argon2id switch) is rehashed
        // the first time it verifies.
        if (Hash::needsRehash($user->password)) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        $expiresInSeconds = (int) config('services.auth.token_ttl_seconds');
        $expiresAt = now()->addSeconds($expiresInSeconds);
        $plainTextAccessToken = Str::random(80);

        AccessToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plainTextAccessToken),
            'expires_at' => $expiresAt,
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
