<?php

namespace Tests\Feature;

use App\Models\Flight;
use App\Services\Flights\OpenSkyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for OpenSkyService flight fetching, persistence, retries,
 * fallback behavior, and circuit-breaker integration.
 */
class OpenSkyServiceFetchFlightsTest extends TestCase
{
    use RefreshDatabase;

    private const FALLBACK_KEY = 'opensky.flights.EHAM.arrival.last_success';

    /**
     * Prepare service dependencies and ensure test cache state is isolated.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpenSkyService();
        Cache::flush();
    }

    /**
     * Ensure fetching flights returns expected payload and issues both auth and
     * flights API requests.
     *
     * @return void
     */
    public function test_fetch_flights_returns_json_and_sends_token_and_flights_requests_with_expected_params(): void
    {
        Http::fake([
            'https://auth.opensky.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://opensky-network.org/api/flights/arrival*' => Http::response([
                ['icao24' => 'abc123', 'firstSeen' => 1736510400, 'lastSeen' => 1736512200],
            ], 200),
        ]);

        $data = $this->service->fetchFlights('EHAM', 'arrival');

        // verify returns expected JSON shape
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('abc123', $data[0]['icao24']);

        // exactly 2 requests were sent (token + flights)
        Http::assertSentCount(2);

        $recordedUrls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url());

        $this->assertTrue(
            $recordedUrls->contains(fn ($u) => str_contains($u, 'opensky-network.org/api/flights/arrival')),
            'Expected an arrival flights request to be sent. Recorded: ' . $recordedUrls->implode(', ')
        );
    }

    /**
     * Ensure storeFlights performs upsert semantics and does not create duplicate
     * rows for existing aircraft-airport-direction combinations.
     *
     * @return void
     */
    public function test_store_flights_does_not_insert_duplicates_for_existing_flights(): void
    {
        // create a flight
        $f1 = Flight::factory()->create();

        $payload = [
            [
                'icao24' => $f1->icao24,
                'firstSeen' => now()->subHour()->timestamp,
                'lastSeen' => now()->timestamp,
            ],
            [
                'icao24' => 'new123',
                'firstSeen' => now()->subHour()->timestamp,
                'lastSeen' => now()->timestamp,
            ],
        ];

        // try to store the new flights
        $this->service->storeFlights($payload, $f1->airport_icao, $f1->direction);

        // in db we should have two flights (the old one and the new one)
        $this->assertSame(2, Flight::query()->count());

        $this->assertDatabaseHas('flights', [
            'icao24' => $f1->icao24,
            'airport_icao' => $f1->airport_icao,
            'direction' => $f1->direction,
        ]);
    }

    /**
     * Ensure rate-limited responses are retried and a later successful response
     * is returned to the caller.
     *
     * @return void
     */
    public function test_fetch_flights_retries_after_rate_limit_and_succeeds(): void
    {
        config()->set('services.opensky.fetch_max_attempts', 2);
        config()->set('services.opensky.fetch_retry_base_delay_ms', 1);

        Http::fake([
            'https://auth.opensky.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://opensky-network.org/api/flights/arrival*' => Http::sequence()
                ->push(['error' => 'too many requests'], 429)
                ->push([
                    ['icao24' => 'retry-ok', 'firstSeen' => 1736510400, 'lastSeen' => 1736512200],
                ], 200),
        ]);

        $data = $this->service->fetchFlights('EHAM', 'arrival');

        $this->assertIsArray($data);
        $this->assertSame('retry-ok', $data[0]['icao24']);
        Http::assertSentCount(3);
    }

    /**
     * Ensure cached fallback data is returned when retryable API failures persist
     * after all configured retry attempts.
     *
     * @return void
     */
    public function test_fetch_flights_uses_cached_fallback_when_api_fails_after_retries(): void
    {
        config()->set('services.opensky.fetch_max_attempts', 2);
        config()->set('services.opensky.fetch_retry_base_delay_ms', 1);

        $cachedPayload = [
            ['icao24' => 'cached123', 'firstSeen' => 1736510400, 'lastSeen' => 1736512200],
        ];
        Cache::put(self::FALLBACK_KEY, $cachedPayload, now()->addMinutes(5));

        Http::fake([
            'https://auth.opensky.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://opensky-network.org/api/flights/arrival*' => Http::response(['error' => 'service unavailable'], 503),
        ]);

        $data = $this->service->fetchFlights('EHAM', 'arrival');

        $this->assertSame($cachedPayload, $data);
        Http::assertSentCount(3);
    }

    /**
     * Ensure malformed successful responses are rejected and replaced with cached
     * fallback payloads.
     *
     * @return void
     */
    public function test_fetch_flights_uses_cached_fallback_when_payload_is_malformed(): void
    {
        $cachedPayload = [
            ['icao24' => 'cached-malformed', 'firstSeen' => 1736510400, 'lastSeen' => 1736512200],
        ];
        Cache::put(self::FALLBACK_KEY, $cachedPayload, now()->addMinutes(5));

        Http::fake([
            'https://auth.opensky.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://opensky-network.org/api/flights/arrival*' => Http::response(['not' => 'a-list'], 200),
        ]);

        $data = $this->service->fetchFlights('EHAM', 'arrival');

        $this->assertSame($cachedPayload, $data);
        Http::assertSentCount(2);
    }

    /**
     * Ensure the circuit breaker opens after threshold failures and prevents
     * subsequent live API flight requests while returning cached fallback data.
     *
     * @return void
     */
    public function test_circuit_breaker_opens_after_threshold_failures(): void
    {
        config()->set('services.opensky.breaker_failure_threshold', 1);
        config()->set('services.opensky.breaker_failure_window_seconds', 120);
        config()->set('services.opensky.breaker_cooldown_seconds', 60);
        config()->set('services.opensky.fetch_max_attempts', 1);

        $cachedPayload = [
            ['icao24' => 'cached-breaker', 'firstSeen' => 1736510400, 'lastSeen' => 1736512200],
        ];
        Cache::put(self::FALLBACK_KEY, $cachedPayload, now()->addMinutes(5));

        Http::fake([
            'https://auth.opensky.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://opensky-network.org/api/flights/arrival*' => Http::response(['error' => 'unavailable'], 500),
        ]);

        // Trigger circuit opening
        $data = $this->service->fetchFlights('EHAM', 'arrival');
        $this->assertSame($cachedPayload, $data);
        Http::assertSentCount(2);

        // Verify circuit is open by checking that next call uses cached fallback without API call
        Http::spy();
        $data = $this->service->fetchFlights('EHAM', 'arrival');
        $this->assertSame($cachedPayload, $data);

        // No flights endpoint request should be made (breaker is open)
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/flights/arrival'));
    }
}
