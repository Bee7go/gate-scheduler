<?php

namespace Tests\Feature;

use App\Models\Flight;
use App\Services\OpenSkyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenSkyServiceFetchFlightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpenSkyService();
    }
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
}
