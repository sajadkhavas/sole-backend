#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

ROOT="${SOLE_BACKEND_ROOT:-/var/www/sole-backend}"
REPOSITORY="${SOLE_BACKEND_REPOSITORY:-https://github.com/sajadkhavas/sole-backend.git}"
NEW_SHA="${NEW_SHA:-}"
ENV_FILE="${SOLE_BACKEND_ENV_FILE:-$ROOT/shared/.env}"

fail() { printf 'ERROR=%s\n' "$1" >&2; exit 1; }
[[ "$NEW_SHA" =~ ^[0-9a-f]{40}$ ]] || fail FULL_NEW_SHA_REQUIRED
[[ -f "$ENV_FILE" ]] || fail SHARED_ENV_REQUIRED
command -v git >/dev/null || fail GIT_REQUIRED
command -v php >/dev/null || fail PHP_REQUIRED
command -v composer >/dev/null || fail COMPOSER_REQUIRED

RELEASE="$ROOT/releases/$NEW_SHA"
[[ ! -e "$RELEASE" ]] || fail IMMUTABLE_RELEASE_ALREADY_EXISTS
install -d -m 0755 "$ROOT/releases"
install -d -m 0750 "$ROOT/shared/storage/app/private" "$ROOT/shared/storage/app/public" "$ROOT/shared/storage/app/media-quarantine"
install -d -m 0750 "$ROOT/shared/storage/framework/cache/data" "$ROOT/shared/storage/framework/sessions" "$ROOT/shared/storage/framework/testing" "$ROOT/shared/storage/framework/views" "$ROOT/shared/storage/logs" "$ROOT/shared/bootstrap-cache"

git init "$RELEASE" >/dev/null
git -C "$RELEASE" remote add origin "$REPOSITORY"
git -C "$RELEASE" fetch --depth=1 origin "$NEW_SHA"
[[ "$(git -C "$RELEASE" rev-parse FETCH_HEAD)" == "$NEW_SHA" ]] || fail REMOTE_SHA_MISMATCH
git -C "$RELEASE" checkout --detach FETCH_HEAD >/dev/null
[[ "$(git -C "$RELEASE" rev-parse HEAD)" == "$NEW_SHA" ]] || fail CHECKOUT_SHA_MISMATCH

rm -rf "$RELEASE/storage" "$RELEASE/bootstrap/cache"
ln -s "$ROOT/shared/storage" "$RELEASE/storage"
ln -s "$ROOT/shared/bootstrap-cache" "$RELEASE/bootstrap/cache"
ln -s "$ENV_FILE" "$RELEASE/.env"

composer --working-dir="$RELEASE" install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
php "$RELEASE/artisan" optimize
php "$RELEASE/artisan" sole:production:check --json
php "$RELEASE/artisan" migrate:status --no-interaction >/dev/null

find "$RELEASE" -xdev -type d -exec chmod go-w {} +
find "$RELEASE" -xdev -type f -exec chmod go-w {} +

printf 'PREPARE_RESULT=PASS\n'
printf 'RELEASE_PATH=%s\n' "$RELEASE"
printf 'NEW_SHA=%s\n' "$NEW_SHA"
