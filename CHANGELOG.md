# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
See the [versioning policy](docs/API.md#versioning) for what a version promises API clients.

## [Unreleased]

### Added

- A machine-readable `code` on every error the API defines (for example
  `validation_failed`, `insufficient_scope`, `rate_limited`,
  `method_not_allowed`), drawn from a documented catalog so clients can
  branch on codes instead of message wording. Additive: existing fields,
  status codes, and message texts are unchanged; only plain `500` and
  maintenance `503` responses carry no code.

### Changed

- Passwords are hashed with Argon2id instead of bcrypt. Existing bcrypt
  hashes keep verifying and are upgraded transparently on the next
  successful login.
- Validation errors (`422`) now carry a stable summary message
  (`The request failed validation.`); the per-field detail remains in
  `errors`. The malformed `Idempotency-Key` response uses the same shape.

## [1.1.0] - 2026-11-18

### Added

- API-key scopes (`read`, `write`, `sync`), chosen at key creation. Keys
  without scopes remain unrestricted; a request outside a key's scopes
  returns `403`.
- Per-key rate limiting on all data endpoints (default 60 requests/minute,
  adjustable per key by the operator), with
  `X-RateLimit-Limit`/`X-RateLimit-Remaining` headers and `Retry-After` on
  `429`.
- Optional `Idempotency-Key` header on `POST /api-keys` and
  `POST /gates/unavailabilities`: retries within 24 hours replay the recorded
  response; reusing a key with a different request returns `409`. Recorded
  responses are encrypted at rest and pruned after the replay window.
- `X-Request-Id` on every response, also attached to all log entries written
  for that request. Well-formed client-supplied ids are echoed back.
- OpenAPI linting (Redocly) in CI alongside the spec drift check.
- The OpenAPI document now declares the project's MIT license.
- Errors under `/api` always render as JSON, even without an
  `Accept: application/json` header — the API never answers with a browser
  redirect.

### Fixed

- Sync runs abandoned by a killed worker no longer stay `running` forever:
  the next sync marks them `failed` (`abandoned_run`).
- Date parameter examples in the OpenAPI document now conform to their declared
  `date-time` format.

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
- Interactive API documentation served at `/docs/api`, backed by an OpenAPI 3.1
  document (`docs/openapi.json`) generated from the controllers and validation
  rules, with a CI check that fails when the committed spec drifts from the
  code.
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

[Unreleased]: https://github.com/Bee7go/gate-scheduler/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/Bee7go/gate-scheduler/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Bee7go/gate-scheduler/releases/tag/v1.0.0
