<?php

namespace App\Services\Flights;

use App\Models\Flight;
use DateTime;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenSkyService
{

    private const DIRECTIONS = ['arrival', 'departure'];

    /**
     * Store or update flight data in the database.
     *
     * @param array $flights List of raw flight data arrays
     * @param string $airport Airport ICAO code
     * @param string $direction Flight direction
     * @return void
     */
    public function storeFlights(array $flights, string $airport, string $direction): void {

        $rows = [];
        foreach ($flights as $flightData) {
            $mapped = $this->mapFlightDataToModel($flightData, $airport, $direction);
            if ($mapped) {
                $rows[] = $mapped;
            }
        }

        if (empty($rows)) {
            Log::info("No valid flight data found to store for {$airport} ({$direction})");
        }

        $ids = array_column($rows, 'icao24');
        $existingCount = Flight::whereIn('icao24', $ids)
            ->where('airport_icao', $airport)
            ->where('direction', $direction)
            ->count();

        // @todo could use bulk to insert data
        Flight::upsert(
            $rows,
            ['icao24', 'airport_icao', 'direction'],
            ['last_seen_at']
        );

        $totalRows = count($rows);

        $afterInsertionCount = Flight::whereIn('icao24', $ids)
            ->where('airport_icao', $airport)
            ->where('direction', $direction)
            ->count();

        Log::info("Flight Sync Complete for {$airport} ({$direction})", [
            'total_processed' => $totalRows,
            'newly_inserted'  => $afterInsertionCount - $existingCount,
            'updated'         => $existingCount
        ]);
    }

    /**
     * Map raw API flight data to internal model structure.
     *
     * @param array $flightData Raw flight data from OpenSky
     * @param string $airport Airport ICAO code
     * @param string $direction Flight direction ('arrival' or 'departure')
     * @return array|null Returns mapped array or null if critical data is missing
     */
    private function mapFlightDataToModel(array $flightData, string $airport, string $direction): ?array {
        $icao24 = $flightData['icao24'] ?? null;
        if (empty($icao24)) {
            Log::warning('Skipping flight data: Missing icao24 identifier', [
                'data_snippet' => $flightData
            ]);
            return null;
        }

        return [
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

    /**
     * Fetch flights from OpenSky Network.
     *
     * @param string $airport ICAO code of the airport (e.g., 'EDDF')
     * @param string $direction Flight direction ('arrival' or 'departure')
     * @return array|null Returns array of flights or null on failure
     */
    public function fetchFlights(string $airport, string $direction): ?array {

        // validate parameters
        if (!in_array($direction, self::DIRECTIONS)) {
            Log::error("Invalid flight direction provided: {$direction}");
            return null;
        }

        if (!preg_match('/^[A-Z]{4}$/', $airport)) {
            Log::warning("Invalid airport ICAO code provided: {$airport}");
            return null;
        }

        try {
            $accessToken = $this->getAccessToken();
            $lookbackSeconds = config('services.opensky.lookback_seconds');
            $begin = now()->subSeconds($lookbackSeconds)->timestamp;
            $end = now()->timestamp;

            Log::debug("Fetching {$direction} flights for {$airport}", [
                'begin' => $begin,
                'end' => $end
            ]);

            $response = Http::withToken($accessToken)
                ->withOptions(['verify' => config('services.opensky.verify_ssl')])
                ->get("https://opensky-network.org/api/flights/{$direction}", [
                    'airport' => $airport,
                    'begin' => $begin,
                    'end' => $end,
                ]);

            if ($response->failed()) {
                Log::error("OpenSky API failed to fetch flights", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'airport' => $airport,
                    'direction' => $direction,
                ]);
                return null;
            }

            return $response->json();

        } catch (Exception $e) {
            Log::critical("Unexpected error in fetchFlights", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Retrieve and cache the OpenSky access token.
     *
     * @return string
     * @throws RuntimeException
     */
    private function getAccessToken(): string
    {
        // Cache token for 55 minutes (3300s) to avoid spamming the auth API
        return Cache::remember('opensky_token', 3300, function () {
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
                    'body' => $response->body()
                ]);
                throw new RuntimeException('Failed to retrieve OpenSky access token');
            }

            return $response['access_token'];
        });
    }
}
