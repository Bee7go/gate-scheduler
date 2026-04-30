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
- Full REST API with token-based authentication
- Includes feature and unit tests covering authentication, allocations, gate status, and unavailability management

---

## Tech Stack

- **PHP 8.2+** / **Laravel 12**
- **SQLite** (default for local & testing)
- **PHPUnit 11**
- **Vite + Tailwind CSS**

---

## Quick Start

1. Install dependencies:

```bash
composer install && npm install
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

4. Run migrations and build assets:

```bash
php artisan migrate
npm run build
```

Or use the bundled bootstrap script: `composer run setup`

---

## Running the App

Start everything in one command:

```bash
composer run dev
```

This starts the Laravel server, queue listener, log tailing, and Vite dev server.

To run the scheduler loop locally:

```bash
php artisan schedule:work
```

---

## API Overview

All endpoints live under `/api/v1`. Authentication uses two layers:

- **Bearer token** — obtained via login, used for account actions (e.g., creating API keys)
- **API key** — created with a Bearer token, used for all data endpoints

Full API documentation with examples: **[docs/API.md](docs/API.md)**

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
| `POST` | `/api-keys` | Bearer | Generate an API key |
| `GET` | `/allocations` | API key | List flight-to-gate allocations |
| `GET` | `/gates/status` | API key | Gate statuses (free/occupied/maintenance) |
| `GET` | `/gates/unavailabilities` | API key | List gate unavailability windows |
| `POST` | `/gates/unavailabilities` | API key | Create a gate unavailability |

---

## How It Works

1. The `app:sync-flights` command fetches arrivals and departures from OpenSky
2. Flights are stored with deduplication
3. The Gate Allocator assigns each unallocated flight to an available gate
4. The `app:gate-allocation-report` command generates health reports (gate usage, blocked gates, unassigned flights)

Both commands run automatically on a schedule — flight sync every **2 minutes**, reports every **3 minutes**.

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
| `php artisan app:sync-flights` | Fetch flights from OpenSky and allocate them to gates |
| `php artisan app:gate-allocation-report` | Generate a gate usage health report |

---

## Database

Key tables: `flights`, `gates`, `gate_allocations`, `gate_unavailabilities`, `users`, `api_keys`, `access_tokens`

Allocations enforce one active record per flight via a unique index on `flight_id`.

Seeders for gates and unavailabilities exist but are not auto-run:

```bash
php artisan db:seed --class=GateSeeder
php artisan db:seed --class=GateUnavailabilitiesSeeder
```

---

## Testing

```bash
composer run test
```

Run a specific test file:

```bash
php artisan test tests/Feature/GateUnavailabilityApiTest.php
```

Tests use in-memory SQLite for fast execution.
