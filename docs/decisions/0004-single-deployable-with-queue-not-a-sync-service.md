# ADR-0004: One deployable with a queue, not a separate sync service

- **Status:** Accepted
- **Date:** 2026-11-26 (recorded retrospectively; decided during v1.0)

## Context

The system has two workloads with different shapes: a request/response API
(latency-sensitive, stateless) and the flight-sync pipeline (periodic,
long-running, upstream-bound). The textbook instinct is to split them into
services. The project has one developer, one upstream, one airport, and a
deployment target of a single VM.

## Decision

One codebase, one Dockerfile, three processes: the API server, a queue worker
(`queue:work`), and the scheduler (`schedule:work`) run as separate containers
built from the same Dockerfile and context. The sync runs as a queued job, so
it already has the operational affordances a separate service would be built
for — retries with backoff, a timeout, a failed-jobs table, and room for burst
work to scale by adding workers (the sync itself stays serialized per
ADR-0001) — without a second repository, a second deploy pipeline, or a
network boundary
between the sync and the models it writes.

## Alternatives considered

- **A separate sync microservice.** Buys independent scaling and deployment —
  neither of which is a current constraint — at the price of duplicated domain
  models (flights, gates, allocations), a coordination protocol between
  services, and distributed-failure modes replacing in-process ones. The
  serialization problem (ADR-0001) would also span a network boundary instead
  of a cache lock.
- **Running the sync in the web process (no queue).** Ties sync latency and
  upstream failures to API worker availability, and loses retries, timeout
  enforcement, and the failed-jobs audit trail. The synchronous `sync-now`
  endpoint deliberately remains an exception: the caller asked for the result
  and is rate-limited to one request per two minutes.
- **A cron-only design (no queue at all).** Simpler still, but a crashed sync
  would wait for the next cron tick with no retry policy, and burst work
  (future webhooks, and the report-generation job that already runs on the
  queue) would have nowhere to go.

## Consequences

- The process split is the seam along which a real split would happen later:
  the job is already the unit of work, so extracting a sync service means
  moving the job, not untangling the web process.
- All three processes share one database and one Redis; a saturating sync can
  degrade API latency through shared resources. Acceptable at this scale, and
  the first symptom would show in the per-endpoint metrics rather than as a
  mystery.
- Deploying any change redeploys everything. With one image and three
  containers this is a feature (no version skew between API and worker), but
  it does mean the queue worker must be restarted on deploy to pick up new
  code — the deployment guide bakes that in.
