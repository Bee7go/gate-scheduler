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
```

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
