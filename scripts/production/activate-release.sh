#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

ROOT="${SOLE_BACKEND_ROOT:-/var/www/sole-backend}"
NEW_SHA="${NEW_SHA:-}"
APPROVAL="${SOLE_ACTIVATION_APPROVAL:-}"
HEALTH_URL="${SOLE_BACKEND_HEALTH_URL:-}"
RUN_MIGRATIONS="${SOLE_RUN_MIGRATIONS:-0}"
PHP_FPM_SERVICE="${SOLE_PHP_FPM_SERVICE:-php8.3-fpm.service}"
QUEUE_SERVICE="${SOLE_QUEUE_SERVICE:-sole-backend-queue.service}"

fail() { printf 'ERROR=%s\n' "$1" >&2; exit 1; }
[[ "$APPROVAL" == 'P13_OR_P14_APPROVED' ]] || fail EXPLICIT_ACTIVATION_APPROVAL_REQUIRED
[[ "$NEW_SHA" =~ ^[0-9a-f]{40}$ ]] || fail FULL_NEW_SHA_REQUIRED
[[ "$HEALTH_URL" =~ ^https:// ]] || fail HTTPS_HEALTH_URL_REQUIRED
RELEASE="$ROOT/releases/$NEW_SHA"
[[ -d "$RELEASE" ]] || fail PREPARED_RELEASE_REQUIRED
[[ "$(git -C "$RELEASE" rev-parse HEAD)" == "$NEW_SHA" ]] || fail RELEASE_SHA_MISMATCH
php "$RELEASE/artisan" sole:production:check --json

CURRENT_PATH="$(readlink -f "$ROOT/current" 2>/dev/null || true)"
ROLLBACK_TARGET='NONE'
if [[ -n "$CURRENT_PATH" ]]; then
  [[ "$CURRENT_PATH" == "$ROOT/releases/"* ]] || fail CURRENT_OUTSIDE_RELEASES
  ROLLBACK_TARGET="$(basename "$CURRENT_PATH")"
  [[ "$ROLLBACK_TARGET" =~ ^[0-9a-f]{40}$ ]] || fail INVALID_ROLLBACK_TARGET
fi

rollback_code() {
  local status=$?
  trap - ERR INT TERM
  if [[ "$ROLLBACK_TARGET" != 'NONE' && -d "$ROOT/releases/$ROLLBACK_TARGET" ]]; then
    NEXT="$ROOT/.current.rollback.$$.next"
    ln -s "$ROOT/releases/$ROLLBACK_TARGET" "$NEXT"
    mv -Tf "$NEXT" "$ROOT/current"
    systemctl reload "$PHP_FPM_SERVICE" || true
    systemctl restart "$QUEUE_SERVICE" || true
  fi
  exit "$status"
}
trap rollback_code ERR INT TERM

if [[ "$RUN_MIGRATIONS" == '1' ]]; then
  [[ "${SOLE_MIGRATION_APPROVAL:-}" == 'BACKWARD_COMPATIBLE_REVIEWED' ]] || fail MIGRATION_APPROVAL_REQUIRED
  php "$RELEASE/artisan" migrate --force --isolated
elif [[ "$RUN_MIGRATIONS" != '0' ]]; then
  fail INVALID_SOLE_RUN_MIGRATIONS
fi

NEXT="$ROOT/.current.$$.next"
ln -s "$RELEASE" "$NEXT"
mv -Tf "$NEXT" "$ROOT/current"
systemctl reload "$PHP_FPM_SERVICE"
systemctl restart "$QUEUE_SERVICE"
curl --fail --silent --show-error --max-time 15 "$HEALTH_URL" >/dev/null

trap - ERR INT TERM
printf 'ACTIVATION_RESULT=PASS\n'
printf 'CURRENT_SHA=%s\n' "$ROLLBACK_TARGET"
printf 'NEW_SHA=%s\n' "$NEW_SHA"
printf 'RELEASE_PATH=%s\n' "$RELEASE"
printf 'ROLLBACK_TARGET=%s\n' "$ROLLBACK_TARGET"
printf 'HEALTH_CHECK_RESULT=PASS\n'
