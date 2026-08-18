#!/usr/bin/env bash
set -euo pipefail

# Backup local do PostgreSQL do Mr. Bills — pensado pra rodar via crontab no host de produção.
# Ver a seção "Production deploy" do CLAUDE.md pra instruções de agendamento e restore.

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKUP_DIR="$PROJECT_DIR/docker/postgres/backups"
RETENTION_DAYS=14

cd "$PROJECT_DIR"

# shellcheck disable=SC1091
[ -f .env ] && set -a && source .env && set +a

DB_USERNAME="${DB_USERNAME:?DB_USERNAME não definido no .env}"
DB_DATABASE="${DB_DATABASE:?DB_DATABASE não definido no .env}"

mkdir -p "$BACKUP_DIR"

TIMESTAMP="$(date +%F_%H%M%S)"
DEST="$BACKUP_DIR/mrbills-${TIMESTAMP}.sql.gz"

echo "Gerando backup em ${DEST}..."

docker compose exec -T pgsql pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" | gzip > "$DEST"

echo "Backup concluído: ${DEST} ($(du -h "$DEST" | cut -f1))"

echo "Removendo backups com mais de ${RETENTION_DAYS} dias..."
find "$BACKUP_DIR" -name 'mrbills-*.sql.gz' -mtime "+${RETENTION_DAYS}" -print -delete
