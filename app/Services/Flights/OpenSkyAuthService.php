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
     * @return string
     * @throws RuntimeException
     */
    public function getAccessToken(): string
    {
        // Cache token for 55 minutes to avoid spamming the auth API.
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $response = Http::asForm()
                ->withOptions(['verify' => config('services.opensky.verify_ssl')])
                ->timeout(10)
                ->retry(2, 200)
                ->post(config('services.opensky.token_url'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('services.opensky.client_id'),
                    'client_secret' => config('services.opensky.client_secret'),
                ]);

            if ($response->failed() || !isset($response['access_token'])) {
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
