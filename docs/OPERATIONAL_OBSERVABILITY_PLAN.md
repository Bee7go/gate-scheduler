# Operational Observability Plan

## Goal

Make flight-sync health observable after external-provider failures, cache fallback usage, queue failures, and application restarts. Operators should be able to distinguish a healthy live sync from a completed sync that used stale fallback data.

## Current Gaps

- Sync completion and failure details exist only in logs.
- `Flight::max('updated_at')` shows when flight data changed, not when a sync run completed successfully.
- `OpenSkyService` returns only flights or `null`, so callers cannot tell whether data came from OpenSky, fallback cache, or neither source.
- Circuit-breaker state is internal and cannot be returned safely to operators.
- A database outage fails API-key middleware before `HealthController` runs, so the documented detailed health payload is not guaranteed in that scenario.

## Scope

Implement durable sync-run tracking and a protected system-status endpoint. Keep `/system/health` lightweight and focused on dependency availability.

| Method | Endpoint | Auth | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/v1/system/health` | API key | Lightweight database and scheduler health check. |
| `GET` | `/api/v1/system/status` | API key | Operational sync, fallback, and circuit-breaker status. |

## Data Model

Create a `sync_runs` table and `SyncRun` model.

| Field | Type | Notes |
| --- | --- | --- |
| `id` | bigint | Primary key. |
| `trigger` | string | `scheduled`, `manual`, or `command`. |
| `status` | string | `running`, `completed`, `degraded`, or `failed`. |
| `arrivals_source` | string nullable | `live`, `fallback`, or `unavailable`. |
| `departures_source` | string nullable | `live`, `fallback`, or `unavailable`. |
| `arrivals_fetched` | unsigned integer nullable | Number of received arrival records. |
| `departures_fetched` | unsigned integer nullable | Number of received departure records. |
| `allocation_summary` | JSON nullable | Allocated and unallocated counts. |
| `failure_reason` | string nullable | Sanitized, operator-safe reason only. |
| `started_at` | timestamp | When processing began. |
| `finished_at` | timestamp nullable | When processing ended. |
| timestamps | timestamps | Standard audit fields. |

Add indexes for `status`, `started_at`, and `finished_at` to keep recent-run status queries inexpensive.

## Fetch Result Contract

Introduce a small `FlightFetchResult` value object or equivalent structure returned to `FlightSyncService`:

```php
[
    'flights' => [...],
    'source' => 'live|fallback|unavailable',
    'reason' => 'circuit_breaker_open|request_failed_after_retries|...',
]
```

`OpenSkyService::fetchFlights()` may remain as a compatibility wrapper that returns only the flights array. Add a richer method for sync orchestration so existing callers do not break unnecessarily.

Never expose tokens, request URLs with credentials, raw provider responses, stack traces, or exception traces in the result or status API.

## Sync Lifecycle

1. `FlightSyncService` creates a `SyncRun` with `status = running` before retrieving flights.
2. Fetch arrivals and departures using the richer result contract.
3. Store available data and run allocation as today.
4. Mark the run `completed` when both directions used live data.
5. Mark the run `degraded` when either direction used fallback data.
6. Mark the run `failed` when a direction is unavailable or an unexpected exception stops the run.
7. Store counts, source modes, allocation summary, safe failure reason, and `finished_at`.
8. Re-throw unexpected failures after recording the run so queued jobs continue to use Laravel failure handling.

Pass the trigger explicitly:

- Scheduled job: `scheduled`
- `/system/sync-now`: `manual`
- `app:sync-flights --now`: `command`

## Circuit Breaker Status

Add a public read-only `state(string $airport, string $direction): string` method to `OpenSkyCircuitBreaker`. It should return only `closed`, `open`, or `half_open` using existing cache state.

Do not expose failure counters or cache key names through the API.

## System Status Contract

`GET /api/v1/system/status` should return the latest sync-run information and current breaker states for arrivals and departures.

```json
{
  "data": {
    "sync": {
      "last_successful_at": "2026-08-31T10:00:00.000000Z",
      "last_failed_at": null,
      "last_run": {
        "status": "degraded",
        "trigger": "scheduled",
        "started_at": "2026-08-31T10:00:00.000000Z",
        "finished_at": "2026-08-31T10:00:04.000000Z",
        "arrivals_source": "fallback",
        "departures_source": "live",
        "failure_reason": null
      }
    },
    "opensky": {
      "arrivals": { "breaker_state": "open" },
      "departures": { "breaker_state": "closed" }
    }
  }
}
```

`last_successful_at` is the newest `completed` run. A `degraded` run must not advance it because it used stale provider data.

## Health Endpoint Contract

Keep `/system/health` small. On a normal authenticated request, it reports database availability and basic counts.

Document this database-outage behavior accurately:

- If API-key lookup can access the database but a later health query fails, the controller returns the detailed degraded `503` payload.
- If the database is unavailable during API-key authentication, middleware fails closed with `503` and returns `{"message":"Service unavailable."}` before the controller runs.

## Tests

Add feature and service coverage for:

- A fully live sync persists a `completed` run and updates `last_successful_at`.
- A fallback-backed sync persists a `degraded` run with the correct source and safe reason.
- An unavailable provider persists a `failed` run and the job still follows Laravel failure handling for unexpected exceptions.
- Manual, scheduled, and command triggers are recorded correctly.
- `/system/status` is API-key protected and returns only safe fields.
- Circuit breaker states are accurately represented as `closed`, `open`, and `half_open`.
- `last_successful_at` ignores degraded and failed runs.
- Health documentation and tests cover the middleware-level fail-closed `503` response.

## Documentation Updates

- Add `/system/status` to `README.md` and `docs/API.md`.
- Update the `/system/health` `503` section with the middleware-level behavior above.
- Explain the difference between `completed`, `degraded`, and `failed` sync runs.

## Acceptance Criteria

- Recent sync outcome is queryable after cache expiry and application restart.
- Operators can identify live, fallback, unavailable, and breaker-open states without receiving sensitive implementation details.
- A fallback-backed sync is visible as degraded rather than healthy.
- Failed syncs are recorded before unexpected exceptions are propagated to the queue system.
- The system-status endpoint is protected by API-key authentication.
- All new behavior is documented and covered by automated tests.
