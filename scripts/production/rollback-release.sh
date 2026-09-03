#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

ROOT="${SOLE_BACKEND_ROOT:-/var/www/sole-backend}"
TARGET_SHA="${ROLLBACK_TARGET:-${1:-}}"
APPROVAL="${SOLE_ROLLBACK_APPROVAL:-}"
HEALTH_URL="${SOLE_BACKEND_HEALTH_URL:-}"
PHP_FPM_SERVICE="${SOLE_PHP_FPM_SERVICE:-php8.3-fpm.service}"
QUEUE_SERVICE="${SOLE_QUEUE_SERVICE:-sole-backend-queue.service}"

fail() { printf 'ERROR=%s\n' "$1" >&2; exit 1; }
[[ "$APPROVAL" == 'ROLLBACK_APPROVED' ]] || fail EXPLICIT_ROLLBACK_APPROVAL_REQUIRED
[[ "$TARGET_SHA" =~ ^[0-9a-f]{40}$ ]] || fail FULL_ROLLBACK_SHA_REQUIRED
[[ "$HEALTH_URL" =~ ^https:// ]] || fail HTTPS_HEALTH_URL_REQUIRED
TARGET="$ROOT/releases/$TARGET_SHA"
[[ -d "$TARGET" ]] || fail ROLLBACK_RELEASE_MISSING
[[ "$(git -C "$TARGET" rev-parse HEAD)" == "$TARGET_SHA" ]] || fail ROLLBACK_SHA_MISMATCH

NEXT="$ROOT/.current.rollback.$$.next"
ln -s "$TARGET" "$NEXT"
mv -Tf "$NEXT" "$ROOT/current"
systemctl reload "$PHP_FPM_SERVICE"
systemctl restart "$QUEUE_SERVICE"
curl --fail --silent --show-error --max-time 15 "$HEALTH_URL" >/dev/null

printf 'ROLLBACK_RESULT=PASS\n'
printf 'ROLLBACK_TARGET=%s\n' "$TARGET_SHA"
printf 'HEALTH_CHECK_RESULT=PASS\n'
