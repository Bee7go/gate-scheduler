# ADR-0001: Serialize the flight sync with a cache lock in the service

- **Status:** Accepted
- **Date:** 2026-11-26 (recorded retrospectively; decided during v1.0)

## Context

The flight sync — fetch arrivals and departures from OpenSky, store them,
allocate gates — can be started four ways: the scheduler queues it every two
minutes, an operator can queue it or run it in-process from the console, and
any API client holding the `sync` scope can trigger it over HTTP. Two syncs
running at once would fetch the same flights twice, race each other through
gate allocation, and burn double the quota of a rate-limited upstream.
Whatever serializes them must survive the holder being killed mid-run.

## Decision

A single atomic cache lock (`Cache::lock`), acquired inside
`FlightSyncService::sync()` — the one method every entry point must call.
The lock's TTL (300 s) is sized above the queued job's timeout (120 s) so it
cannot expire under a legally running sync, and acts as the dead-man's switch
when a holder crashes without releasing. Contention surfaces as one exception,
and each entry point translates it for its own audience (HTTP 409, a logged
queue skip, a console warning). A test pins the ordering of the three
durations so none can be resized in isolation.

## Alternatives considered

- **A lock in each entry point (controller, job, command).** Three copies of
  the same guard, and the next entry point added can forget its copy. Guarding
  the service method makes an unguarded sync unrepresentable.
- **`ShouldBeUnique` / unique queued jobs.** Only covers the queue door; the
  in-process console path and the synchronous API path never enter the queue.
- **A database advisory lock (`pg_advisory_lock`).** Ties serialization to
  PostgreSQL semantics while development runs on SQLite (ADR-0002), and
  session-scoped advisory locks interact badly with connection pooling. The
  cache abstraction gives the same atomicity on every store that supports
  locks.
- **A `running` flag in the `sync_runs` table.** Not atomic without extra
  locking, and a crashed process leaves the flag set forever — the exact
  wedge the TTL exists to prevent.

## Consequences

- The lock is only as reliable as the cache store backing it. Production uses
  Redis; development uses the database store, whose `cache_locks` table
  supports atomic locks. A deployment that switched to a store without lock
  support would silently lose serialization — the store choice is now a
  correctness decision, not just a performance one.
- Lock TTL and job timeout are coupled: raising the job timeout past 300 s
  without raising the TTL would let the lock expire mid-run. The relationship
  is written on the constants and enforced by a test.
- The API-triggered in-process sync has no hard timeout, so a run can in
  principle outlive its lock. Abandoned-run reconciliation (closing `running`
  rows older than 600 s) exists to clean up after that case rather than
  pretending it cannot happen.
