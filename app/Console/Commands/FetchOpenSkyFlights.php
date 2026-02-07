<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchOpenSkyFlights extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-open-sky-flights';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws ConnectionException
     */
    public function handle()
    {
        $startedAt = microtime(true); // use for logging duration

        $airport = config('services.opensky.airport_icao');
        $accessToken = $this->getAccessToken();
        $arrivals = $this->fetchOpenSkyFlighs($accessToken, $airport,'arrival');
        $departures = $this->fetchOpenSkyFlighs($accessToken, $airport, 'departure');
        $storedArrivals = $this->storeFlights($arrivals, 'arrival');
        $storedDepartures = $this->storeFlights($departures, 'departure');

        $this->logSyncInfo($airport, $storedArrivals, $storedDepartures, $startedAt);

    }

    private function storeFlights(array $flights, string $direction): int {
        $airport = config('services.opensky.airport_icao');

        $count = 0;
        // @todo nu exista oare un upsertMany ca sa nu mai facem foreach?
        foreach ($flights as $flight) {
            $icao24 = $flight['icao24'] ?? null;
            if (!$icao24) {
                continue;
            }

            $firstSeenAt = isset($flight['firstSeen'])
                ? Carbon::createFromTimestamp((int) $flight['firstSeen'])->toDateTimeString()
                : null;

            $lastSeenAt = isset($flight['lastSeen'])
                ? Carbon::createFromTimestamp((int) $flight['lastSeen'])->toDateTimeString()
                : null;

            DB::table('flights')->updateOrInsert(
                [
                    'icao24' => $icao24,
                    'airport_icao' => $airport,
                    'direction' => $direction,
                ],
                [
                    'first_seen_at' => $firstSeenAt,
                    'last_seen_at' => $lastSeenAt,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $count++;
        }

        return $count;
    }
    private function fetchOpenSkyFlighs(string $accessToken, string $airport, string $endpoint): array {
        $lookbackSeconds = config('services.opensky.lookback_seconds');
        $response = Http::withToken($accessToken)->withOptions(['verify' => false])
            ->get("https://opensky-network.org/api/flights/{$endpoint}", [
                'airport' => $airport,
                'begin' => now()->subSeconds($lookbackSeconds)->timestamp,
                'end' => now()->timestamp,
            ]);

        $data = $response->json();
        return $data;
    }

    // @todo poate ar putea fi mutat intr-un openSkyService?

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
            if (!$response->successful()) {
                throw new \RuntimeException(
                    'OpenSky token request failed: ' . $response->status() . ' - ' . $response->body()
                );
            }

            $token = $response->json('access_token');
            if (!$token) {
                throw new \RuntimeException('OpenSky token missing in response: ' . $response->body());
            }

            return $token;
        } catch (\Throwable $e) {
            report($e); // logs to Laravel log system
            throw new \RuntimeException('Failed to obtain OpenSky access token');
        }
    }

    private function logSyncInfo(string $airport, int $storedArrivals, int $storedDepartures, int $startedAt): void {
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $message = "OpenSky sync finished for airport {$airport}: \n"
            . "storedArrivals={$storedArrivals}, "
            . "storedDepartures={$storedDepartures}, "
            . "totalStored=" . ($storedArrivals + $storedDepartures) . ", "
            . "duration={$durationMs}ms";

        $this->info($message);
        Log::info($message);
    }
}
