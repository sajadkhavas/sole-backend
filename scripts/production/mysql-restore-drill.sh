#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MYSQL_DEFAULTS_FILE="${MYSQL_DEFAULTS_FILE:-}"
BACKUP="${SOLE_RESTORE_BACKUP:-${1:-}}"
RESTORE_DB="${SOLE_RESTORE_DB:-sole_restore_$(date -u +%Y%m%d%H%M%S)_$$}"
KEEP="${SOLE_KEEP_RESTORE_DB:-0}"

fail() { printf 'ERROR=%s\n' "$1" >&2; exit 1; }
[[ -n "$MYSQL_DEFAULTS_FILE" && -f "$MYSQL_DEFAULTS_FILE" ]] || fail MYSQL_DEFAULTS_FILE_REQUIRED
[[ -n "$BACKUP" && -f "$BACKUP" ]] || fail BACKUP_REQUIRED
[[ -f "${BACKUP}.sha256" ]] || fail CHECKSUM_REQUIRED
[[ "$RESTORE_DB" =~ ^sole_restore_[a-zA-Z0-9_]+$ ]] || fail DISPOSABLE_DATABASE_NAME_REQUIRED
command -v mysql >/dev/null || fail MYSQL_CLIENT_REQUIRED
command -v gzip >/dev/null || fail GZIP_REQUIRED
command -v sha256sum >/dev/null || fail SHA256SUM_REQUIRED

MODE="$(stat -c '%a' "$MYSQL_DEFAULTS_FILE")"
[[ "$MODE" =~ ^[46]00$ ]] || fail MYSQL_DEFAULTS_FILE_MUST_BE_0400_OR_0600

(
  cd "$(dirname "$BACKUP")"
  sha256sum -c "$(basename "${BACKUP}.sha256")" >/dev/null
) || fail CHECKSUM_MISMATCH

cleanup() {
  if [[ "$KEEP" != '1' ]]; then
    mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -e "DROP DATABASE IF EXISTS \`$RESTORE_DB\`;" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT INT TERM

EXISTS="$(mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -N -B -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$RESTORE_DB';")"
[[ "$EXISTS" == '0' ]] || fail RESTORE_DATABASE_ALREADY_EXISTS

mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -e "CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gzip -dc "$BACKUP" | mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" "$RESTORE_DB"

MIGRATIONS="$(mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -N -B "$RESTORE_DB" -e 'SELECT COUNT(*) FROM migrations;')"
[[ "$MIGRATIONS" =~ ^[1-9][0-9]*$ ]] || fail MIGRATION_HISTORY_MISSING

for table in users products product_variants orders payment_attempts analytics_events; do
  COUNT="$(mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -N -B -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB' AND TABLE_NAME='$table';")"
  [[ "$COUNT" == '1' ]] || fail "CRITICAL_TABLE_MISSING_$table"
done

printf 'RESTORE_DRILL_RESULT=PASS\n'
printf 'RESTORE_DATABASE=%s\n' "$RESTORE_DB"
printf 'MIGRATION_ROWS=%s\n' "$MIGRATIONS"
