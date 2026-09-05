#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

for script in scripts/production/*.sh; do
  bash -n "$script"
done

QUEUE='deploy/systemd/sole-backend-queue.service.example'
SCHEDULER='deploy/systemd/sole-backend-scheduler.service.example'
TIMER='deploy/systemd/sole-backend-scheduler.timer.example'

for file in "$QUEUE" "$SCHEDULER" "$TIMER"; do
  test -s "$file"
done

grep -Fq -- '--timeout=60' "$QUEUE"
grep -Fq 'KillMode=control-group' "$QUEUE"
grep -Fq 'ProtectSystem=strict' "$QUEUE"
grep -Fq 'ReadWritePaths=/var/www/sole-backend/shared/storage /var/www/sole-backend/shared/bootstrap-cache' "$QUEUE"
grep -Fq 'OnCalendar=*-*-* *:*:00' "$TIMER"
grep -Fq -- '--single-transaction' scripts/production/mysql-backup.sh
grep -Fq 'sha256sum' scripts/production/mysql-backup.sh
grep -Fq 'sole_restore_' scripts/production/mysql-restore-drill.sh
grep -Fq 'P13_OR_P14_APPROVED' scripts/production/activate-release.sh
grep -Fq 'ROLLBACK_APPROVED' scripts/production/rollback-release.sh

if grep -R --line-number --exclude-dir=config --include='*.php' -E '\benv\s*\(' app bootstrap database routes tests; then
  echo 'ERROR=ENV_OUTSIDE_CONFIG' >&2
  exit 1
fi

printf 'P12_BACKEND_STATIC_AUDIT=PASS\n'
