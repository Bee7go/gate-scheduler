# Oracle Cloud Deployment Guide

## Scope

This guide deploys the complete Gate Scheduler topology at no recurring cost on one Oracle Cloud Always Free ARM VM. Docker Compose runs the Laravel API, queue worker, scheduler, PostgreSQL, Redis, and Caddy HTTPS proxy on the same server.

This is suitable for a portfolio project, not a high-availability production system. The VM, database, Redis, and backups are self-managed. Keep the server patched and verify backups regularly.

## Prerequisites

- An Oracle Cloud account with an Always Free ARM instance available in its home region.
- A registered free DNS hostname, such as a DuckDNS hostname, pointing to the VM public IP.
- An Ubuntu 24.04 ARM VM with at least 2 OCPUs and 8 GB RAM allocated within the Always Free allowance.
- SSH access to the VM.

In the Oracle Cloud security list or network security group, allow inbound TCP ports `22`, `80`, and `443`. Restrict SSH to your own IP address where possible.

## VM Setup

Connect to the VM and install Docker Compose:

```bash
sudo apt update
sudo apt install --yes docker.io docker-compose-v2 git
sudo usermod -aG docker "$USER"
```

Sign out and back in so the Docker group membership is applied. Enable the host firewall after allowing SSH, HTTP, and HTTPS:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

## Production Configuration

Clone the project and create the server-only environment file:

```bash
git clone https://github.com/Bee7go/gate-scheduler.git /opt/gate-scheduler
cd /opt/gate-scheduler
cp .env.production.example .env.production
chmod 600 .env.production
chmod 700 scripts/backup-postgres.sh
```

Edit `.env.production` and set every blank secret:

- `APP_KEY`: generate it with `php artisan key:generate --show` on a trusted machine.
- `DB_PASSWORD` and `POSTGRES_PASSWORD`: use the same long, unique value.
- `OPENSKY_CLIENT_ID` and `OPENSKY_CLIENT_SECRET`.
- `APP_URL` and `APP_DOMAIN`: the DNS hostname configured for this server.

Keep `APP_DEBUG=false`, `OPENSKY_VERIFY_SSL=true`, `CACHE_STORE=redis`, and `QUEUE_CONNECTION=redis`. Do not commit `.env.production` or copy it into the image.

## First Deployment

Build the image and start only PostgreSQL and Redis first:

```bash
docker compose --env-file .env.production -f docker-compose.production.yml build
docker compose --env-file .env.production -f docker-compose.production.yml up -d postgres redis
```

Wait until both are healthy, then migrate and seed the initial gate inventory:

```bash
docker compose --env-file .env.production -f docker-compose.production.yml run --rm app php artisan migrate --force
docker compose --env-file .env.production -f docker-compose.production.yml run --rm app php artisan db:seed --force
docker compose --env-file .env.production -f docker-compose.production.yml run --rm app php artisan scramble:cache
```

The last command pre-generates the interactive API documentation served at `/docs/api`; generating it per request is too slow for a web request.

Start the API, worker, scheduler, and HTTPS proxy:

```bash
docker compose --env-file .env.production -f docker-compose.production.yml up -d app worker scheduler caddy
docker compose --env-file .env.production -f docker-compose.production.yml ps
```

Caddy obtains and renews the TLS certificate automatically when DNS points to the VM and ports `80` and `443` are reachable.

## Updates and Rollback

For each release, pull the tested `master` branch, rebuild, apply migrations, then recreate the application services:

```bash
git pull --ff-only origin master
docker compose --env-file .env.production -f docker-compose.production.yml build
docker compose --env-file .env.production -f docker-compose.production.yml run --rm app php artisan migrate --force
docker compose --env-file .env.production -f docker-compose.production.yml run --rm app php artisan scramble:cache
docker compose --env-file .env.production -f docker-compose.production.yml up -d app worker scheduler caddy
```

Do not use `migrate:fresh`, `db:wipe`, or destructive reset commands on the server. Migrations must be backward-compatible. To roll back an application regression, check out a known-good commit and rebuild the application services; do not roll back database migrations blindly.

## Logs and Failed Jobs

Inspect all service logs:

```bash
docker compose --env-file .env.production -f docker-compose.production.yml logs --follow
```

Inspect failed jobs or retry one only after resolving the underlying problem:

```bash
docker compose --env-file .env.production -f docker-compose.production.yml exec app php artisan queue:failed
docker compose --env-file .env.production -f docker-compose.production.yml exec app php artisan queue:retry <failed-job-uuid>
```

The scheduler prunes failed jobs older than 14 days. Investigate a stale `last_successful_at`, a failed sync, or an open circuit breaker with `/api/v1/system/status` and the worker/scheduler logs.

## Monitoring and Alerting

What should page a human, at what threshold, and how to check it. All checks
are driven by the API itself, so any uptime monitor (or a cron + curl) can run
them.

| Signal | Check | Page when | First response |
|--------|-------|-----------|----------------|
| App down | `GET /up` (no auth) | Non-200, 2 consecutive checks | `docker compose ps`, app container logs |
| Scheduler/worker stalled | `GET /api/v1/system/health` | `503` (degraded), or `last_successful_sync` older than 10 minutes | Worker and scheduler container logs; `queue:failed` |
| Upstream broken | `GET /api/v1/system/status` | `circuit_breaker` open for more than 30 minutes | Check OpenSky status and credentials; syncs degrade to cached data meanwhile |
| Repeated sync failures | `GET /api/v1/system/status` | 3 consecutive runs `failed` | Inspect `failure_reason`; `abandoned_run` means a worker is being killed mid-sync (memory/timeout) |
| Failed jobs piling up | `php artisan queue:failed` | More than 5 in an hour | Logs around the failures; retry only after fixing the cause |
| Disk/backup | host monitoring | Backup older than 25 hours or disk above 85% | Run the backup script manually; prune Docker images |

Log lines worth alerting on (all JSON, greppable by message): `flight.sync.reconciled_abandoned_runs` (a worker died mid-sync) and `throttle:api-key used on a route without auth.apikey` (a route misconfiguration that fails closed).

## Data Retention

What the database keeps and for how long. Pruning runs daily at 03:30 via
`model:prune` on the scheduler container.

| Data | Retention | Why |
|------|-----------|-----|
| Users, API keys | Until deleted by the owner | Account data; API keys expire after 1 year (`AUTH_API_KEY_TTL_DAYS`) but expired rows stay visible to their owner |
| Bearer tokens | Pruned 7 days after expiry (`AUTH_TOKEN_PRUNE_AFTER_DAYS`) | Tokens live 1 hour; the grace period keeps them visible for audit |
| Idempotency records | Pruned after the 24-hour replay window | Hold encrypted response bodies; kept no longer than replays need |
| Sync runs | Pruned after 90 days (`SYNC_RUN_RETENTION_DAYS`) | A sync every 2 minutes is ~263k rows/year; 90 days of history is plenty for `/system/status` and trend analysis |
| Failed jobs | Pruned after 14 days (`queue:prune-failed`) | Enough time to investigate and retry |
| Flights, allocations, unavailabilities | Kept indefinitely | Operational history; export and archive externally if volume ever demands it |

## Backups and Restore

Create a compressed PostgreSQL backup:

```bash
./scripts/backup-postgres.sh
```

Schedule it daily with the VM user's crontab:

```cron
0 2 * * * cd /opt/gate-scheduler && ./scripts/backup-postgres.sh >> backups/backup.log 2>&1
```

The script retains 14 days by default. Copy backups off the VM periodically, for example to Oracle Object Storage, because a backup stored only on the VM cannot recover a lost VM.

To restore, stop application services, create a fresh database only when appropriate, and run the following against a verified backup:

```bash
gzip -dc backups/gate-scheduler-<timestamp>.sql.gz | docker compose --env-file .env.production -f docker-compose.production.yml exec -T postgres psql -U "$POSTGRES_USER" "$POSTGRES_DB"
```

Export `POSTGRES_USER` and `POSTGRES_DB` in your shell before the restore command, or replace them with the values from `.env.production`.

## Verification

After deployment, verify:

- `https://<your-domain>/up` returns `200`.
- `docker compose ... ps` shows all six services running.
- The worker processes scheduled jobs and `/api/v1/system/status` reports a recent successful sync.
- `/api/v1/system/health` and `/api/v1/system/status` require a valid API key.
- A manual backup is created and can be inspected.
