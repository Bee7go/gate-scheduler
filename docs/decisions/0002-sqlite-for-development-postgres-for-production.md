# ADR-0002: SQLite for development and tests, PostgreSQL for production

- **Status:** Accepted
- **Date:** 2026-11-26 (recorded retrospectively; decided during v1.0)

## Context

The test suite runs in CI on every push to master and on every pull request,
and locally dozens of times a day; its speed
and zero-setup matter more there than engine fidelity. Production needs a
server-grade database with real concurrency, and the deployment runs
PostgreSQL 17. Running different engines in different environments risks
shipping code that passes every test and fails on the engine that matters.

## Decision

Development and the test suite run on SQLite (in-memory for tests), production
runs PostgreSQL — and CI runs the full suite on both: the primary job on
SQLite for speed, a `test-postgres` job against a PostgreSQL 17 service
container to catch engine drift before it ships. The schema and queries stay
on the portable subset both engines share.

## Alternatives considered

- **PostgreSQL everywhere, including tests.** Highest fidelity, but every
  contributor and every local run pays the setup and the per-test latency, and
  in-memory SQLite's instant, isolated databases are lost. The two-engine CI
  buys most of the fidelity at none of the local cost.
- **SQLite everywhere, including production.** Fine for a single-writer
  workload until it isn't; the sync pipeline plus concurrent API writes is
  exactly the contention profile where SQLite's single-writer model becomes
  the bottleneck.
- **One CI job on PostgreSQL only.** Catches drift but makes every CI run as
  slow as the slowest engine, and leaves local runs (SQLite) unverified
  against it anyway. Running both keeps the fast feedback and the fidelity.

## Consequences

- The schema is confined to what both engines support: no partial indexes with
  engine-specific syntax, no PostgreSQL exclusion constraints, no `jsonb`
  operators in queries. The gate-overlap invariant is currently enforced in
  application code; promoting it to a PostgreSQL exclusion constraint would
  mean either dropping SQLite or maintaining engine-conditional migrations.
  This is the cost most likely to force a revisit.
- Engine-coupled test code is a standing hazard. One test hardcoded the
  `sqlite` connection name and broke the moment the suite ran on PostgreSQL;
  it had to be rewritten driver-agnostically. The `test-postgres` job exists
  precisely to surface this class of bug.
- Every CI run pays for two full suite executions. Accepted: the second job
  runs in parallel and the wall-clock cost is minutes.
