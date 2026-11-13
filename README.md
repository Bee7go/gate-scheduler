# ✈️ Airport Gate Scheduler

**Automatically assign flights to airport gates — and manage it all through a clean REST API.**

Airport Gate Scheduler pulls live flight data from OpenSky, intelligently assigns each flight to an available gate, and gives you a full API to monitor gate status, manage unavailabilities, and view allocations in real time.

---

## Features

- Pulls live flight data from the OpenSky Network (arrivals & departures)
- Automatically assigns flights to available gates using configurable strategies
- Tracks gate status in real time — free, occupied, or under maintenance
- Manages gate unavailabilities (maintenance, cleaning, repairs)
- Generates periodic gate usage health reports
- Exposes a statistics endpoint with period-scoped KPIs: gate utilization, flight allocation rates, peak hours, top gates, and unavailability summaries
- Full REST API with token-based authentication
- Includes a feature test suite covering authentication, allocations, gate status, unavailability management, and statistics

---

## Tech Stack

- **PHP 8.4+** / **Laravel 12**
- **SQLite** + database queue (default for local & testing)
- **PostgreSQL** + **Redis** (production)
- **PHPUnit 11**

## Architecture

In production, the scheduler dispatches sync jobs to a Redis queue. Workers retrieve OpenSky flight data with cached fallback and circuit-breaker protection, store flights in PostgreSQL, then allocate available gates. Locally the same flow runs on SQLite with the database queue driver.

![Airport Gate Scheduler architecture](docs/diagrams/airport-gate-scheduler-architecture.svg)

---

## Quick Start

1. Install dependencies:

```bash
composer install
```

2. Set up environment:

```bash
cp .env.example .env
php artisan key:generate
```

3. Configure OpenSky credentials and allocation strategy in `.env`:

```dotenv
AIRPORT_ICAO=EHAM
OPENSKY_CLIENT_ID=your-client-id
OPENSKY_CLIENT_SECRET=your-client-secret
GATE_OCCUPATION_TIME=90
GATE_ALLOCATION_STRATEGY=greedy
```

4. Run migrations:

```bash
php artisan migrate
```

Or use the bundled bootstrap script: `composer run setup`

---

## Running the App

The app is three processes: the API server, a queue worker (sync and report jobs are queued), and the scheduler. Run each in its own terminal:

```bash
composer run dev
```

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

Without the queue worker, scheduled flight syncs and reports are dispatched but never executed.

---

## API Overview

All endpoints live under `/api/v1`. Authentication uses two layers:

- **Bearer token** — obtained via login, used for account actions (e.g., creating API keys)
- **API key** — created with a Bearer token, used for all data endpoints

Full API documentation with examples: **[docs/API.md](docs/API.md)**

Deployment instructions: **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**

Quick example — check which gates are free right now:

```bash
curl "https://your-app.test/api/v1/gates/status" \
  -H "X-Api-Key: your-api-key"
```

### Available Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `POST` | `/register` | — | Create a new account |
| `POST` | `/login` | — | Log in, get a Bearer token |
| `GET` | `/api-keys` | Bearer | List your API keys |
| `POST` | `/api-keys` | Bearer | Generate an API key |
| `DELETE` | `/api-keys/{apiKey}` | Bearer | Revoke one of your API keys |
| `GET` | `/allocations` | API key | List flight-to-gate allocations |
| `GET` | `/gates/status` | API key | Gate statuses (free/occupied/maintenance) |
| `GET` | `/gates/unavailabilities` | API key | List gate unavailability windows |
| `POST` | `/gates/unavailabilities` | API key | Create a gate unavailability |
| `GET` | `/statistics` | API key | Period-scoped statistics for dashboards |
| `POST` | `/system/sync-now` | API key | Trigger a flight sync on demand (rate limited) |
| `GET` | `/system/health` | API key | Lightweight health check for the scheduler |
| `GET` | `/system/status` | API key | Sync and OpenSky operational status |

---

## How It Works

1. The scheduler dispatches `SyncFlightsJob` to the queue, which fetches arrivals and departures from OpenSky
2. Flights are stored with deduplication
3. The Gate Allocator assigns each unallocated flight to an available gate
4. `GenerateGateAllocationReportJob` generates health reports (gate usage, blocked gates, unassigned flights)

Both jobs run automatically on a schedule — flight sync every **2 minutes**, reports every **3 minutes**. A cache lock guarantees only one sync runs at a time, whichever way it is triggered.

---

## Gate Allocation Strategies

Set `GATE_ALLOCATION_STRATEGY` in `.env`:

| Strategy | Description |
|----------|-------------|
| `greedy` | Picks the first available gate |
| `least_used` | Prefers the gate with the fewest allocations |
| `round_robin` | Rotates evenly through all gates |
| `earliest_available` | Picks the gate that becomes free the soonest |

---

## Commands

| Command | What it does |
|---------|-------------|
| `php artisan app:sync-flights` | Dispatch a flight sync job to the queue (`--now` runs it in-process) |
| `php artisan app:gate-allocation-report` | Generate a gate usage health report |

---

## Database

Key tables: `flights`, `gates`, `gate_allocations`, `gate_unavailabilities`, `users`, `api_keys`, `access_tokens`

Allocations enforce one active record per flight via a unique index on `flight_id`. A schema diagram lives at [schema/db_diagram.png](schema/db_diagram.png).

Seed 20 gates plus a sample unavailability window:

```bash
php artisan db:seed
```

---

## Testing

```bash
composer run test
```

Run a specific test file:

```bash
php artisan test tests/Feature/Api/GateUnavailabilityApiTest.php
```

Tests use in-memory SQLite for fast execution.
