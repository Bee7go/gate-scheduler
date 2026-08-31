#!/usr/bin/env sh

set -eu

PROJECT_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE="$PROJECT_ROOT/docker-compose.production.yml"
ENV_FILE="$PROJECT_ROOT/.env.production"
BACKUP_DIR="$PROJECT_ROOT/backups"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_FILE="$BACKUP_DIR/gate-scheduler-$TIMESTAMP.sql.gz"

if [ ! -f "$ENV_FILE" ]; then
    echo "Missing production environment file: $ENV_FILE" >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"

docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T postgres \
    sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$BACKUP_FILE"

find "$BACKUP_DIR" -type f -name 'gate-scheduler-*.sql.gz' -mtime +"$RETENTION_DAYS" -delete

echo "Created PostgreSQL backup: $BACKUP_FILE"
