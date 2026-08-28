<?php

namespace App\Services\Flights;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenSkyAuthService
{
    private const CACHE_KEY = 'opensky_token';

    private const CACHE_TTL_SECONDS = 3300;

    /**
     * Retrieve and cache the OpenSky access token.
     *
     * @throws RuntimeException
     */
    public function getAccessToken(): string
    {
        $tokenUrl = config('services.opensky.token_url');
        $clientId = config('services.opensky.client_id');
        $clientSecret = config('services.opensky.client_secret');

        if (empty($tokenUrl) || empty($clientId) || empty($clientSecret)) {
            throw new RuntimeException(
                'OpenSky credentials are not configured. '.
                'Please set OPENSKY_TOKEN_URL, OPENSKY_CLIENT_ID, and OPENSKY_CLIENT_SECRET in your .env file.'
            );
        }

        // Cache token for 55 minutes to avoid spamming the auth API.
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () use ($tokenUrl, $clientId, $clientSecret) {
            $response = Http::asForm()
                ->withOptions(['verify' => config('services.opensky.verify_ssl')])
                ->timeout(10)
                ->retry(2, 200)
                ->post($tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if ($response->failed() || ! isset($response['access_token'])) {
                Log::error('OpenSky Auth Failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new RuntimeException('Failed to retrieve OpenSky access token');
            }

            return $response['access_token'];
        });
    }
}
