# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
See the [versioning policy](docs/API.md#versioning) for what a version promises API clients.

## [Unreleased]

## [1.0.0] - 2026-11-14

First stable release. This version defines the `v1` API contract.

### Added

- Flight ingestion from the OpenSky Network (arrivals and departures), with retry,
  circuit breaker, and cached fallback when the upstream API is unavailable.
- Automatic gate allocation with four configurable strategies: `greedy`,
  `least_used`, `round_robin`, and `earliest_available`.
- REST API under `/api/v1` with two-layer authentication: Bearer tokens for
  account actions and API keys (`X-Api-Key`) for data endpoints. Both are stored
  as SHA-256 hashes and shown only once at creation.
- Endpoints for allocations, gate status, gate unavailabilities, period-scoped
  statistics, on-demand sync, and system health/status.
- Unified list responses: paginated endpoints (allocations, gate
  unavailabilities, API keys) share a `{data, meta}` envelope with validated
  `per_page` (1–100) and `page` parameters.
- Scheduled background jobs (flight sync every 2 minutes, allocation reports
  every 3 minutes) with bounded retries and failed-job recording. A shared cache
  lock guarantees a single sync at a time across the API, queue, and CLI.
- Fail-closed authentication: if the database is unreachable, protected
  endpoints return `503` instead of bypassing authentication, on both the
  API-key and Bearer-token layers.
- Rate limiting on authentication endpoints (10 requests/minute per IP) and on
  on-demand sync.
- Sync observability: sync runs are recorded with their outcome (completed,
  degraded, or failed) and exposed via `GET /system/status`.
- CI pipeline running code style checks and the full test suite on both SQLite
  and PostgreSQL 17.
- Deployment guide for a single-VM Docker Compose topology (API, worker,
  scheduler, PostgreSQL, Redis, Caddy).

[Unreleased]: https://github.com/Bee7go/gate-scheduler/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Bee7go/gate-scheduler/releases/tag/v1.0.0
