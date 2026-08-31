# Deployment Guide

## Status

This guide is being completed in phases. Phases 2 through 6 define the production configuration, queue policy, database bootstrap, operational response, and Fly.io deployment contract.

## Production Configuration

Store every sensitive value in Fly.io secrets. Do not add a production `.env` file to the repository, commit credentials, or place secret values in `fly.toml`.

The production deployment uses:

- Fly.io for the web application, worker, and scheduler processes.
- Managed PostgreSQL for application data and failed jobs.
- Managed Redis for cache locks, queues, and sessions.
- Fly.io log output through `stderr`.

### Required Settings

| Variable | Production value | Store as Fly.io secret | Notes |
| --- | --- | --- | --- |
| `APP_NAME` | `"Airport Gate Scheduler"` | No | Human-readable application name. |
| `APP_ENV` | `production` | No | Enables Laravel production behavior. |
| `APP_KEY` | Generated unique key | Yes | Generate once with `php artisan key:generate --show`; never rotate casually because existing encrypted values become unreadable. |
| `APP_DEBUG` | `false` | No | Must remain disabled so exception details are not returned to clients. |
| `APP_URL` | `https://<your-fly-domain>` | No | Replace with the final Fly.io or custom HTTPS domain. |
| `LOG_CHANNEL` | `stderr` | No | Sends structured application logs to `fly logs`. |
| `LOG_LEVEL` | `info` | No | Use `debug` only for a temporary, controlled investigation. |
| `DB_CONNECTION` | `pgsql` | No | Selects the PostgreSQL connection. |
| `DB_URL` | Managed PostgreSQL connection URL | Yes | Preferred single connection value supplied by the database provider. |
| `DB_SSLMODE` | Provider-required value | No | Use `require` only when the selected database connection requires TLS; do not guess. |
| `CACHE_STORE` | `redis` | No | Required for shared cache state and scheduler locks. |
| `QUEUE_CONNECTION` | `redis` | No | Required for the Fly.io queue worker. |
| `REDIS_QUEUE_RETRY_AFTER` | `180` | No | Must exceed the jobs' 120-second execution timeout. |
| `REDIS_URL` | Managed Redis connection URL | Yes | Used by both Laravel Redis connections. |
| `REDIS_CLIENT` | `phpredis` | No | The production image must include the PHP Redis extension. |
| `SESSION_DRIVER` | `redis` | No | Avoids the absent `sessions` table and shares session data across processes. |
| `SESSION_STORE` | `redis` | No | Uses Laravel's configured Redis cache store. |
| `SESSION_SECURE_COOKIE` | `true` | No | Requires HTTPS for browser session cookies. |
| `AIRPORT_ICAO` | Selected airport, for example `EHAM` | No | Must be a valid ICAO airport identifier. |
| `OPENSKY_CLIENT_ID` | OpenSky OAuth client ID | Yes | Required for provider authentication. |
| `OPENSKY_CLIENT_SECRET` | OpenSky OAuth client secret | Yes | Required for provider authentication. |
| `OPENSKY_VERIFY_SSL` | `true` | No | Must remain enabled in production. |
| `GATE_OCCUPATION_TIME` | Chosen duration in minutes | No | Example: `90`. |
| `GATE_ALLOCATION_STRATEGY` | Chosen strategy | No | One of the documented allocation strategies, initially `greedy`. |

### Recommended OpenSky Settings

These defaults are already in `.env.example` and are appropriate as a starting point. Tune them only after observing production behavior.

```dotenv
OPENSKY_LOOKBACK_SECONDS=7200
OPENSKY_FETCH_MAX_ATTEMPTS=3
OPENSKY_FETCH_RETRY_BASE_DELAY_MS=500
OPENSKY_FETCH_TIMEOUT_SECONDS=10
OPENSKY_FALLBACK_CACHE_TTL_SECONDS=900
OPENSKY_BREAKER_FAILURE_THRESHOLD=3
OPENSKY_BREAKER_FAILURE_WINDOW_SECONDS=300
OPENSKY_BREAKER_COOLDOWN_SECONDS=600
```

## Fly.io Secret Handling

Generate `APP_KEY` locally without saving it to a file:

```bash
php artisan key:generate --show
```

When the Fly.io application has been created, set sensitive values in its secret store. Replace every placeholder before running this command:

```bash
fly secrets set \
  APP_KEY="base64:..." \
  DB_URL="postgresql://..." \
  REDIS_URL="redis://..." \
  OPENSKY_CLIENT_ID="..." \
  OPENSKY_CLIENT_SECRET="..."
```

Secrets are injected into all Fly.io process groups. Never echo secret values in terminals, screenshots, logs, GitHub Actions output, or documentation.

## Non-Secret Fly.io Settings

The future `fly.toml` will contain the safe non-secret settings below:

```dotenv
APP_NAME="Airport Gate Scheduler"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<your-fly-domain>
LOG_CHANNEL=stderr
LOG_LEVEL=info
DB_CONNECTION=pgsql
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
SESSION_DRIVER=redis
SESSION_STORE=redis
SESSION_SECURE_COOKIE=true
AIRPORT_ICAO=EHAM
OPENSKY_VERIFY_SSL=true
GATE_OCCUPATION_TIME=90
GATE_ALLOCATION_STRATEGY=greedy
REDIS_QUEUE_RETRY_AFTER=180
```

## Queue and Scheduler Operations

Run exactly one scheduler process and one worker process initially:

```bash
php artisan queue:work redis --sleep=3 --tries=3 --timeout=120 --max-time=3600
php artisan schedule:work
```

The worker retries only unexpected job failures. OpenSky request failures are handled inside the sync service through provider-level retries, fallback cache data, and the circuit breaker.

The worker's 120-second timeout is intentionally lower than `REDIS_QUEUE_RETRY_AFTER=180`. This prevents Redis from making a still-running job available to another worker before Laravel has stopped it.

The Fly.io process definitions and restart procedure will be added in the next deployment phase. Do not start multiple scheduler instances; scheduled jobs already use overlap protection, but a single scheduler is the intended deployment topology.

## Database Migrations and Production Data

Every release must apply migrations before it accepts traffic:

```bash
php artisan migrate --force
```

On the first production deployment, seed the standard gate inventory after migrations:

```bash
php artisan db:seed --force
```

`DatabaseSeeder` provisions gates `G1` through `G20`. The seed is idempotent, so it is safe to run again during a controlled release; it does not add sample unavailability records.

Check the deployed schema before any manual database intervention:

```bash
php artisan migrate:status
```

Never use `migrate:fresh`, `db:wipe`, or destructive seed/reset commands against production. Managed PostgreSQL backups and restoration procedures must be configured before the first public release.

## Logs and Failed Jobs

Production uses `LOG_CHANNEL=stderr`, so inspect application, worker, and scheduler output with Fly.io:

```bash
fly logs
fly logs --app <your-fly-app>
```

Use `LOG_LEVEL=info` by default. Raise it to `debug` only for a short, controlled investigation, then return it to `info`; logs can contain operational context that should not be retained unnecessarily.

Failed queue jobs are stored in PostgreSQL. Inspect and respond to them with:

```bash
php artisan queue:failed
php artisan queue:retry <failed-job-uuid>
php artisan queue:forget <failed-job-uuid>
```

Do not retry a failed job blindly. First inspect the error, relevant Fly.io logs, and `/api/v1/system/status`:

| Condition | Response |
| --- | --- |
| One unexpected failed job | Inspect its exception and the matching job log entry. Retry only after the underlying condition is resolved. |
| Repeated job failures | Pause deployment changes, inspect database and Redis connectivity, then verify worker configuration. |
| `last_successful_at` is stale | Inspect `/api/v1/system/status`, OpenSky breaker states, worker logs, and scheduler logs. |
| Sync run is `degraded` | Confirm fallback data is expected; investigate if the state persists beyond the fallback TTL. |
| Circuit breaker is `open` | Check OpenSky credentials, provider availability, and outbound network connectivity. Do not force repeated manual syncs. |

The scheduler prunes failed-job records older than 14 days every day at `03:15`. This retention window preserves recent diagnostic context while preventing unbounded growth of the `failed_jobs` table.

Never expose raw log entries, failed-job exception traces, provider payloads, tokens, API keys, or database URLs through API responses or support material.

## Configuration Checks

Before first deployment, confirm:

- `APP_DEBUG` is `false` and `OPENSKY_VERIFY_SSL` is `true`.
- PostgreSQL and Redis URLs point to managed, persistent services in the selected Fly.io region.
- The production PHP image includes `pdo_pgsql` and `redis` extensions.
- `APP_KEY` is unique to production and stored only in Fly.io secrets.
- `DB_URL`, `REDIS_URL`, and OpenSky credentials are not present in Git history or committed files.
- The final `APP_URL` uses HTTPS.
- The environment values are available to web, worker, and scheduler processes.

## Fly.io Deployment

The repository includes `Dockerfile`, `.dockerignore`, `docker/nginx.conf`, `docker/start-web.sh`, and `fly.toml`.

The Docker image uses PHP 8.4 with the `pdo_pgsql`, `redis`, and `zip` extensions. Nginx listens on port `8080` and forwards PHP requests to PHP-FPM. Fly.io uses separate process groups for the web API, Redis queue worker, and Laravel scheduler.

### First Deployment

1. Install `flyctl` and authenticate with `fly auth login`.
2. Choose a globally unique application name and replace the placeholder in `fly.toml`.
3. Provision managed PostgreSQL and Redis in the same Fly.io region, then collect their connection URLs.
4. Generate a production app key with `php artisan key:generate --show`.
5. Set the required Fly.io secrets, including `APP_KEY`, `APP_URL`, `DB_URL`, `REDIS_URL`, `OPENSKY_CLIENT_ID`, and `OPENSKY_CLIENT_SECRET`.
6. Deploy with `fly deploy`. The Fly.io release command runs `php artisan migrate --force` before new Machines receive traffic.
7. Seed the initial gate inventory once: `fly ssh console -C "php artisan db:seed --force"`.
8. Start exactly one instance of each process group:

```bash
fly scale count web=1 worker=1 scheduler=1
```

9. Confirm the web Machine is healthy, then verify `/up`, `/api/v1/system/health`, and `/api/v1/system/status` with a valid API key.

### Subsequent Deployments

```bash
fly deploy
fly status
fly logs
```

`fly deploy` runs migrations before traffic changes. New worker Machines start with the new image, so a separate `queue:restart` command is not required. Do not seed sample data on subsequent deployments.

### Rollback

If a release command fails, Fly.io stops the deployment before new Machines receive traffic. If a healthy deployment causes an application regression, inspect the release and logs, then deploy the prior known-good image or revision using the Fly.io dashboard or CLI. Database migrations must be backward-compatible before a release; do not roll back a migration blindly against production data.

## Local Development

Keep `.env` and `.env.example` configured for local development. Local SQLite, database cache, and database queue settings should not be changed merely to mirror production. Tests continue to use their isolated in-memory configuration from `phpunit.xml`.
