# Airport Gate Scheduler

Airport Gate Scheduler is a Laravel application that ingests recent flight events from OpenSky and assigns flights to airport gates while respecting gate unavailability windows.

## Features

- Fetches arrivals and departures from OpenSky for one airport ICAO code
- Stores flights with upsert semantics (no duplicates by aircraft-airport-direction)
- Allocates unassigned flights to available gates
- Supports multiple gate selection strategies
- Generates periodic gate allocation health reports
- Includes feature tests for flight sync and gate allocation behavior

## Tech Stack

- PHP 8.2+
- Laravel 12
- SQLite (default local/testing)
- PHPUnit 11
- Vite + Tailwind CSS

## Architecture Summary

Main flow:

1. Command `app:sync-flights` runs `FlightSyncService`
2. `OpenSkyService` fetches arrivals and departures
3. Flights are upserted in `flights`
4. `GateAllocatorService` assigns unallocated flights to gates

Reporting flow:

1. Command `app:gate-allocation-report` runs `GateAllocationReportService`
2. The report calculates gate usage, blocked gates, unallocated flights, and detected allocation anomalies

## Gate Allocation Strategies

Set `GATE_ALLOCATION_STRATEGY` in `.env`:

- `greedy`
- `least_used`
- `round_robin`
- `earliest_available`

## Environment Variables

Add these keys to your `.env`:

```dotenv
# OpenSky
AIRPORT_ICAO=EHAM
OPENSKY_LOOKBACK_SECONDS=7200
OPENSKY_TOKEN_URL=https://auth.opensky-network.org/auth/realms/opensky-network/protocol/openid-connect/token
OPENSKY_CLIENT_ID=your-client-id
OPENSKY_CLIENT_SECRET=your-client-secret
OPENSKY_VERIFY_SSL=true

# Gate allocation
GATE_OCCUPATION_TIME=90
GATE_ALLOCATION_STRATEGY=greedy
```

## Local Setup

1. Install dependencies:

```bash
composer install
npm install
```

2. Create environment file and app key:

```bash
cp .env.example .env
php artisan key:generate
```

On Windows PowerShell, use:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

3. Create database and run migrations:

```bash
php artisan migrate
```

4. Build frontend assets:

```bash
npm run build
```

You can also use the bundled Composer bootstrap script:

```bash
composer run setup
```

## Running the App

Start all local dev processes in one command:

```bash
composer run dev
```

This starts:

- Laravel HTTP server
- Queue listener
- Log tailing
- Vite dev server

## Commands

Sync flights and allocate gates:

```bash
php artisan app:sync-flights
```

Generate gate allocation report:

```bash
php artisan app:gate-allocation-report
```

Scheduler behavior in `routes/console.php`:

- `app:sync-flights` every 2 minutes
- `app:gate-allocation-report` every 3 minutes

Run the scheduler loop locally:

```bash
php artisan schedule:work
```

## Database Notes

Key tables:

- `flights`
- `gates`
- `gate_allocations`
- `gate_unavailabilities`

Allocations enforce one active allocation record per flight via a unique index on `flight_id`.

## Seeding

Project seeders exist for gates and gate unavailability data:

- `Database\\Seeders\\GateSeeder`
- `Database\\Seeders\\GateUnavailabilitiesSeeder`

They are currently not invoked from `DatabaseSeeder`, so run them explicitly if needed.

## Testing

Run all tests:

```bash
composer run test
```

Run a specific test file:

```bash
php artisan test tests/Feature/GateAllocatorServiceTest.php
```

Test configuration uses in-memory SQLite for fast execution.

## Troubleshooting

- No flights fetched: verify OpenSky credentials and token URL.
- No allocations created: verify gates exist and `first_seen_at` is present on flights.
- Unexpected unassigned flights: check gate unavailability windows and current allocation strategy.

## License

This project is licensed under the MIT License.
