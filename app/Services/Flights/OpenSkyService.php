<?php

namespace App\Services\Flights;

use App\Models\Flight;
use DateTime;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Coordinates OpenSky flight retrieval, response validation, fallback handling,
 * and persistence mapping for flight records.
 *
 * This service is responsible for:
 * - fetching arrivals and departures from the OpenSky API
 * - applying retry and circuit-breaker protections around API access
 * - validating API payload structure before use
 * - caching successful payloads for fallback during outages
 * - mapping raw flight payloads into local database rows
 */
class OpenSkyService
{
    private const FALLBACK_CACHE_KEY_PREFIX = 'opensky.flights';

    /**
     * Create a new OpenSky service instance.
     *
     * Dependencies are optional so the service can still be instantiated
     * directly in tests or simple call sites without resolving it through the
     * container.
     *
     * @param OpenSkyAuthService|null $authService Service used to fetch and cache OpenSky auth tokens.
     * @param OpenSkyCircuitBreaker|null $circuitBreaker Service used to guard live API calls during repeated failures.
     */
    public function __construct(
        private readonly ?OpenSkyAuthService $authService = null,
        private readonly ?OpenSkyCircuitBreaker $circuitBreaker = null
    ) {
    }

    private const DIRECTIONS = ['arrival', 'departure'];

    /**
     * Persist a batch of raw OpenSky flights using upsert semantics.
     *
     * Each payload entry is first mapped into the local schema. Invalid rows are
     * skipped. Valid rows are then upserted using the unique combination of
     * aircraft, airport, and direction.
     *
     * @param array $flights List of raw OpenSky flight payloads.
     * @param string $airport Airport ICAO code associated with the request.
     * @param string $direction Flight direction key, typically arrival or departure.
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
     * Map a raw OpenSky payload entry to the local flight storage format.
     *
     * The OpenSky payload is reduced to the fields needed by this application.
     * If the required aircraft identifier is missing, the row is rejected.
     *
     * @param array $flightData Raw flight item returned by OpenSky.
     * @param string $airport Airport ICAO code associated with the request.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return array|null Normalized database row or null when required data is missing.
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
     * Fetch flights from OpenSky with retry, validation, and cached fallback support.
     *
     * This method validates inputs, obtains an access token, checks the circuit
     * breaker, performs retryable HTTP requests, validates the response shape,
     * and falls back to the most recent cached successful payload when the live
     * API cannot be trusted or reached.
     *
     * @param string $airport ICAO airport code, for example EHAM or EDDF.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return array|null Valid flight payload array or null when neither live nor fallback data is available.
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
            $accessToken = ($this->authService ?? new OpenSkyAuthService())->getAccessToken();
            $lookbackSeconds = config('services.opensky.lookback_seconds');
            $maxAttempts = max(1, (int) config('services.opensky.fetch_max_attempts', 3));
            $baseDelayMs = max(0, (int) config('services.opensky.fetch_retry_base_delay_ms', 500));
            $timeoutSeconds = max(1, (int) config('services.opensky.fetch_timeout_seconds', 10));
            $begin = now()->subSeconds($lookbackSeconds)->timestamp;
            $end = now()->timestamp;

            $breaker = $this->circuitBreaker ?? new OpenSkyCircuitBreaker();

            if (!$breaker->allows($airport, $direction)) {
                Log::notice('OpenSky circuit breaker is open, skipping live call', [
                    'airport' => $airport,
                    'direction' => $direction,
                ]);

                return $this->getCachedFallback($airport, $direction, 'circuit_breaker_open');
            }

            Log::debug("Fetching {$direction} flights for {$airport}", [
                'begin' => $begin,
                'end' => $end
            ]);

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $response = Http::withToken($accessToken)
                        ->withOptions(['verify' => config('services.opensky.verify_ssl')])
                        ->timeout($timeoutSeconds)
                        ->get("https://opensky-network.org/api/flights/{$direction}", [
                            'airport' => $airport,
                            'begin' => $begin,
                            'end' => $end,
                        ]);

                    if ($response->successful()) {
                        $payload = $response->json();

                        if (!$this->isValidFlightsPayload($payload)) {
                            Log::error('OpenSky API returned malformed flights payload', [
                                'airport' => $airport,
                                'direction' => $direction,
                                'payload_type' => gettype($payload),
                            ]);

                            return $this->getCachedFallback($airport, $direction, 'malformed_response');
                        }

                        $this->cacheFlightsPayload($airport, $direction, $payload);

                        $breaker->recordSuccess($airport, $direction);

                        return $payload;
                    }

                    $status = $response->status();
                    $isRetryableStatus = $status === 429 || $status >= 500;

                    if (!$isRetryableStatus) {
                        Log::error('OpenSky API returned non-retryable response', [
                            'status' => $status,
                            'body' => $response->body(),
                            'airport' => $airport,
                            'direction' => $direction,
                            'attempt' => $attempt,
                        ]);

                        $breaker->recordFailure($airport, $direction);

                        return $this->getCachedFallback($airport, $direction, 'non_retryable_error');
                    }

                    Log::warning('OpenSky API temporary failure, retrying', [
                        'status' => $status,
                        'airport' => $airport,
                        'direction' => $direction,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                    ]);

                    if ($attempt < $maxAttempts) {
                        usleep($this->computeBackoffDelayMicros($attempt, $baseDelayMs));
                    }

                    if ($attempt === $maxAttempts) {
                        $breaker->recordFailure($airport, $direction);
                    }
                } catch (Exception $e) {
                    Log::warning('OpenSky request threw exception', [
                        'airport' => $airport,
                        'direction' => $direction,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'message' => $e->getMessage(),
                    ]);

                    if ($attempt < $maxAttempts) {
                        usleep($this->computeBackoffDelayMicros($attempt, $baseDelayMs));
                    } else {
                        $breaker->recordFailure($airport, $direction);
                    }
                }
            }

            return $this->getCachedFallback($airport, $direction, 'request_failed_after_retries');

        } catch (Exception $e) {
            Log::critical("Unexpected error in fetchFlights", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->getCachedFallback($airport, $direction, 'unexpected_exception');
        }
    }

    /**
     * Validate that a decoded OpenSky response matches the expected payload shape.
     *
     * The service expects a top-level array where each item is itself an array.
     * This intentionally rejects malformed or partially decoded responses before
     * they can be cached or persisted.
     *
     * @param mixed $payload Decoded HTTP response payload.
     * @return bool True when the payload matches the expected list-of-arrays structure.
     */
    private function isValidFlightsPayload(mixed $payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        foreach ($payload as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cache a successful flights payload for use as a fallback.
     *
     * The cache entry is scoped by airport and direction and kept for a
     * configurable TTL so the application can continue operating during short
     * external outages.
     *
     * @param string $airport ICAO airport code associated with the payload.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @param array $payload Valid flight payload to cache.
     * @return void
     */
    private function cacheFlightsPayload(string $airport, string $direction, array $payload): void
    {
        $ttlSeconds = max(60, (int) config('services.opensky.fallback_cache_ttl_seconds', 900));

        Cache::put($this->getFallbackCacheKey($airport, $direction), $payload, now()->addSeconds($ttlSeconds));
    }

    /**
     * Retrieve the last successful cached payload for a request scope.
     *
     * When fallback data exists, it is returned and a notice is logged with the
     * failure reason that triggered fallback mode.
     *
     * @param string $airport ICAO airport code associated with the request.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @param string $reason Reason why live data could not be used.
     * @return array|null Cached payload or null if no fallback exists.
     */
    private function getCachedFallback(string $airport, string $direction, string $reason): ?array
    {
        $cacheKey = $this->getFallbackCacheKey($airport, $direction);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            Log::notice('Using cached OpenSky fallback flights payload', [
                'airport' => $airport,
                'direction' => $direction,
                'reason' => $reason,
            ]);

            return $cached;
        }

        Log::error('No cached OpenSky fallback payload available', [
            'airport' => $airport,
            'direction' => $direction,
            'reason' => $reason,
        ]);

        return null;
    }

    /**
     * Build the cache key used for the last successful flights payload.
     *
     * @param string $airport ICAO airport code associated with the payload.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return string Cache key for the fallback payload.
     */
    private function getFallbackCacheKey(string $airport, string $direction): string
    {
        return sprintf('%s.%s.%s.last_success', self::FALLBACK_CACHE_KEY_PREFIX, $airport, $direction);
    }

    /**
     * Compute an exponential backoff delay in microseconds for retry sleeps.
     *
     * The delay doubles on each attempt and is capped to avoid unbounded wait
     * times during long outages.
     *
     * @param int $attempt Current retry attempt number, starting at 1.
     * @param int $baseDelayMs Base retry delay in milliseconds.
     * @return int Delay in microseconds suitable for use with usleep().
     */
    private function computeBackoffDelayMicros(int $attempt, int $baseDelayMs): int
    {
        $multiplier = 2 ** max(0, $attempt - 1);
        $delayMs = $baseDelayMs * $multiplier;

        return min($delayMs, 5000) * 1000;
    }
}
