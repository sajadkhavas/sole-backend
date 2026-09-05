#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MYSQL_DEFAULTS_FILE="${MYSQL_DEFAULTS_FILE:-}"
DB_DATABASE="${DB_DATABASE:-}"
BACKUP_DIR="${SOLE_BACKUP_DIR:-/var/backups/sole}"
RETENTION_DAYS="${SOLE_BACKUP_RETENTION_DAYS:-7}"
PRUNE="${SOLE_BACKUP_PRUNE:-NO}"

fail() { printf 'ERROR=%s\n' "$1" >&2; exit 1; }
[[ -n "$MYSQL_DEFAULTS_FILE" && -f "$MYSQL_DEFAULTS_FILE" ]] || fail MYSQL_DEFAULTS_FILE_REQUIRED
[[ "$DB_DATABASE" =~ ^[A-Za-z0-9_]+$ ]] || fail SAFE_DATABASE_NAME_REQUIRED
[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || fail INVALID_RETENTION_DAYS
[[ "$PRUNE" =~ ^(NO|YES)$ ]] || fail INVALID_BACKUP_PRUNE_GUARD
command -v mysqldump >/dev/null || fail MYSQLDUMP_REQUIRED
command -v gzip >/dev/null || fail GZIP_REQUIRED
command -v sha256sum >/dev/null || fail SHA256SUM_REQUIRED

MODE="$(stat -c '%a' "$MYSQL_DEFAULTS_FILE")"
[[ "$MODE" =~ ^[46]00$ ]] || fail MYSQL_DEFAULTS_FILE_MUST_BE_0400_OR_0600

install -d -m 0700 "$BACKUP_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FINAL="$BACKUP_DIR/${DB_DATABASE}-${STAMP}.sql.gz"
TMP="${FINAL}.partial.$$"
CHECKSUM="${FINAL}.sha256"
trap 'rm -f "$TMP"' EXIT

mysqldump --defaults-extra-file="$MYSQL_DEFAULTS_FILE" \
  --single-transaction \
  --quick \
  --routines \
  --events \
  --triggers \
  --hex-blob \
  --set-gtid-purged=OFF \
  --no-tablespaces \
  "$DB_DATABASE" | gzip -9 > "$TMP"

test -s "$TMP" || fail EMPTY_BACKUP
mv "$TMP" "$FINAL"
chmod 0600 "$FINAL"
(
  cd "$BACKUP_DIR"
  sha256sum "$(basename "$FINAL")" > "$(basename "$CHECKSUM")"
)
chmod 0600 "$CHECKSUM"

if [[ "$PRUNE" == 'YES' ]]; then
  find "$BACKUP_DIR" -maxdepth 1 -type f \( -name '*.sql.gz' -o -name '*.sql.gz.sha256' \) -mtime "+$RETENTION_DAYS" -delete
fi

printf 'BACKUP_RESULT=PASS\n'
printf 'BACKUP_FILE=%s\n' "$FINAL"
printf 'CHECKSUM_FILE=%s\n' "$CHECKSUM"
