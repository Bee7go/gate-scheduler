<?php

namespace App\Services;

use App\Models\Flight;
use DateTime;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenSkyService
{

    public function storeFlights(array $flights, string $airport, string $direction): int
    {
        $rows = [];

        foreach ($flights as $flightData) {

            $icao24 = $flightData['icao24'] ?? null;
            if (!$icao24) {
                continue;
            }

            $rows[] = [
                'icao24' => $icao24,
                'airport_icao' => $airport,
                'direction' => $direction,
                'first_seen_at' => isset($flightData['firstSeen'])
                    ? (new DateTime())->setTimestamp((int)$flightData['firstSeen'])
                    : null,
                'last_seen_at' => isset($flightData['lastSeen'])
                    ? (new DateTime())->setTimestamp((int)$flightData['lastSeen'])
                    : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        return Flight::insertOrIgnore($rows);
//        Flight::upsert(
//            $rows,
//            ['icao24', 'airport_icao', 'direction'],
//            ['last_seen_at']
//        );
    }

    public function fetchFlights(string $airport, string $direction): ?array {
        $accessToken = $this->getAccessToken();

        $lookbackSeconds = config('services.opensky.lookback_seconds');
        $response = Http::withToken($accessToken)->withOptions(['verify' => false])
            ->get("https://opensky-network.org/api/flights/{$direction}", [
                'airport' => $airport,
                'begin' => now()->subSeconds($lookbackSeconds)->timestamp,
                'end' => now()->timestamp,
            ]);

        return $response->json();
    }

    private function getAccessToken(): string
    {
        try {
            $response = Http::asForm()->withOptions(['verify' => false])
                ->timeout(10)
                ->retry(2, 200)
                ->post(config('services.opensky.token_url'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('services.opensky.client_id'),
                    'client_secret' => config('services.opensky.client_secret'),
                ]);
            if (!$response->successful() || !$response->json('access_token')) {
                throw new RuntimeException('OpenSky token request failed');
            }

            return $response->json('access_token');
        } catch (\Throwable $e) {
            report($e);
            throw new RuntimeException('Failed to obtain OpenSky access token');
        }
    }
}
