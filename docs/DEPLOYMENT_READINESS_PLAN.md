# Deployment Readiness Plan

## Goal

Prepare Airport Gate Scheduler for a reliable public deployment that demonstrates production-minded backend and API-integration practices: persistent data, asynchronous processing, scheduled synchronization, observable failures, secure configuration, and repeatable operations.

## Current State

- CI runs formatting and the full test suite on pushes and pull requests using PHP 8.4.
- The application uses SQLite, a database cache, and a database queue by default, which is appropriate for local development but not a durable multi-process production setup.
- Migrations already create the `jobs`, `failed_jobs`, `cache`, `cache_locks`, and application data tables.
- The scheduler dispatches flight syncs every two minutes and reports every three minutes, with overlap protection.
- Sync outcome, fallback usage, and circuit-breaker state are available through protected system endpoints.
- No production deployment guide, host configuration, worker definition, scheduler definition, backup plan, or log-retention guidance exists yet.

## Recommended Production Shape

Use the following baseline unless the selected hosting provider makes an equivalent managed service more appropriate:

| Concern | Recommendation | Why |
| --- | --- | --- |
| Application | One web process | Serves the REST API. |
| Database | Managed PostgreSQL | Durable application, queue, and audit data with backups. |
| Cache and queue | Managed Redis | Fast shared cache, distributed locks, and queue coordination. |
| Worker | One continuously running queue worker | Processes scheduled sync and report jobs. |
| Scheduler | One scheduler process or one cron entry | Dispatches due jobs without duplicate schedules. |
| Logs | Platform stdout or centralized log service | Keeps logs available across restarts and deployments. |

PostgreSQL and Redis are recommended for the portfolio deployment. The existing database-backed queue and cache remain a valid simpler fallback for one small instance, but Redis better demonstrates production-ready coordination and scaling.

## Phase 1: Select the Target Platform

### Decision

**Selected platform: Fly.io.**

Fly.io is a good fit because its Laravel deployment tooling supports Docker-based PHP deployments, managed PostgreSQL and Redis connections, release commands for migrations, and independently scalable web, worker, and scheduler process groups.

The planned Fly.io topology is:

| Fly.io process group | Application command | Count |
| --- | --- | --- |
| `web` | Laravel web server container entrypoint | 1 |
| `worker` | `php artisan queue:work redis --sleep=3 --tries=3 --timeout=120 --max-time=3600` | 1 |
| `scheduler` | Cron process that invokes `php artisan schedule:run` every minute | 1 |

PostgreSQL and Redis will be provisioned as managed services in the same Fly.io region as the application. Application secrets will be stored with Fly.io secrets rather than in `fly.toml` or Git.

Choose a host that provides, or can connect to:

- A PHP 8.4 runtime and web service.
- A managed PostgreSQL database.
- A managed Redis instance.
- Separate long-running worker and scheduler processes, or a reliable cron facility.
- Environment-secret management, deployment logs, and custom-domain TLS.

Record the selected platform and its process model in this document before adding provider-specific files. Avoid committing credentials, connection strings, API keys, or generated application keys.

## Phase 2: Production Configuration

Create a production environment-variable checklist in `docs/DEPLOYMENT.md`. It must include variable names, safe example values, and whether each value is required.

Required configuration:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_KEY=base64:generated-production-key

DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=...
REDIS_PASSWORD=...
REDIS_PORT=6379

LOG_CHANNEL=stderr
LOG_LEVEL=info

AIRPORT_ICAO=EHAM
OPENSKY_CLIENT_ID=...
OPENSKY_CLIENT_SECRET=...
OPENSKY_VERIFY_SSL=true
```

Also document the OpenSky timeout, fallback, and circuit-breaker settings that are safe to tune in production. Keep `APP_DEBUG=false` and TLS verification enabled at all times outside local development.

Decide and document the production session driver. Since the public API authenticates through bearer tokens and API keys, sessions may not be central to the deployed API, but the configured driver must still be durable if web session features are enabled.

## Phase 3: Queue and Scheduler Operations

Configure exactly one scheduler and at least one worker:

```bash
php artisan schedule:work
php artisan queue:work redis --sleep=3 --tries=3 --timeout=120 --max-time=3600
```

If the provider only supports cron, use one cron entry instead:

```cron
* * * * * cd /path/to/application && php artisan schedule:run >> /dev/null 2>&1
```

The final platform guide must specify the exact process declarations or supervisor configuration, including restart behavior after deployments and crashes. Do not run more than one scheduler unless the locking strategy is explicitly reviewed for that topology.

Review the job retry policy before deployment:

- `SyncFlightsJob` and `GenerateGateAllocationReportJob` use three attempts with 30- and 120-second backoff delays for unexpected application failures.
- OpenSky provider failures use the service-level retry, cache fallback, and circuit-breaker behavior before a queue retry is considered.
- Both jobs have a 120-second execution timeout. Configure `REDIS_QUEUE_RETRY_AFTER=180` so Redis does not release a running job before that timeout expires.
- Automated tests preserve the retry, timeout, and failed-job behavior.

## Phase 4: Database, Migrations, and Data

On every production deployment:

1. Build dependencies with the committed `composer.lock` using `composer install --no-dev --optimize-autoloader`.
2. Run `php artisan migrate --force`.
3. Cache production configuration only after all environment variables are available: `php artisan config:cache`.
4. Restart workers so they load the new release: `php artisan queue:restart`.

Before first launch:

- Seed the standard `G1` through `G20` gate inventory using `php artisan db:seed --force`; the seeder is idempotent and does not include sample unavailability records.
- Confirm gate identifiers remain strings, matching the public API contract and the `gates.code` schema.
- Confirm PostgreSQL backups, retention, and restore procedures are enabled by the provider.
- Verify SQLite is not used for any production data, cache, queue, or session state.

## Phase 5: Logs and Failed Jobs

Use platform-managed stdout logging where available. Otherwise configure rotated daily logs and documented retention.

The selected Fly.io deployment uses `LOG_CHANNEL=stderr`, making logs available through `fly logs`. Schedule `queue:prune-failed --hours=336` daily to retain failed jobs for 14 days.

Document the failed-job workflow:

```bash
php artisan queue:failed
php artisan queue:retry <failed-job-uuid>
php artisan queue:forget <failed-job-uuid>
```

Define what requires attention:

- Any failed queue job.
- A recent sync run with `status = failed`.
- A stale `last_successful_at` timestamp in `/api/v1/system/status`.
- A circuit breaker that remains open unexpectedly.

Do not expose logs, exception traces, API keys, tokens, or database credentials through public endpoints.

## Phase 6: Deployment Guide

Create `docs/DEPLOYMENT.md` with:

- Prerequisites and required managed services.
- Production environment-variable checklist.
- Provider-specific build and release commands.
- Web, worker, and scheduler process definitions.
- First-deployment steps, including migrations and gate seeding.
- Safe rollout and rollback procedure.
- Database backup and restore responsibility.
- Log access, failed-job handling, and worker restart procedure.
- Post-deployment verification commands.

Link the guide from `README.md` after it is implemented.

Implementation notes:

- `fly.toml` defines a `web` process served by Nginx/PHP-FPM, a `worker` process for Redis jobs, and a `scheduler` process using `php artisan schedule:work`.
- The release command runs `php artisan migrate --force` before new Machines receive traffic.
- Fly.io checks Laravel's built-in unauthenticated `/up` route. Protected system endpoints remain the operational checks for authenticated clients.

## Tests and Verification

Before merging deployment changes:

- Run `vendor/bin/pint --test`.
- Run `php artisan test`.
- Run `composer validate` and resolve any lock-file consistency warning before the first deployment.
- Verify the selected platform uses PHP 8.4.
- Verify a production-like environment can connect to PostgreSQL and Redis.
- Confirm a queued sync is dispatched, processed by the worker, and recorded by `/api/v1/system/status`.
- Confirm scheduled jobs run once at their expected intervals.
- Confirm an intentionally failed job appears in `failed_jobs` and can be inspected safely.
- Confirm `/api/v1/system/health` and `/api/v1/system/status` are API-key protected.

## Acceptance Criteria

- Production uses a persistent managed database rather than SQLite.
- Cache and queue state survive application restarts and are shared by web, worker, and scheduler processes.
- One scheduler and at least one supervised worker run continuously with documented restart behavior.
- Production secrets are configured outside Git, with debug mode disabled and TLS verification enabled.
- Failed jobs, stale syncs, and circuit-breaker problems have a documented operator response.
- Deployments run migrations safely, restart workers, and have a defined rollback path.
- A concise deployment guide enables another developer to deploy and verify the application without relying on local knowledge.
