#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html}"
WAIT_SECONDS="${SCHEDULER_WAIT_SECONDS:-60}"
WAIT_FOR_DB="${SCHEDULER_WAIT_FOR_DB:-1}"
WAIT_URL="${SCHEDULER_WAIT_URL:-}"

cd "$APP_DIR"

echo "[scheduler] starting (app_dir=$APP_DIR)"

if [[ ! -f artisan ]]; then
    echo "[scheduler] ERROR: $APP_DIR/artisan not found"
    exit 1
fi

if [[ "$WAIT_FOR_DB" == "1" ]]; then
    echo "[scheduler] waiting for database (max=${WAIT_SECONDS}s)"
    for ((i = 1; i <= WAIT_SECONDS; i++)); do
        if php artisan migrate:status --no-interaction >/dev/null 2>&1; then
            echo "[scheduler] database reachable"
            break
        fi

        if [[ "$i" == "$WAIT_SECONDS" ]]; then
            echo "[scheduler] WARNING: database not reachable after ${WAIT_SECONDS}s; continuing anyway"
        else
            sleep 1
        fi
    done
fi

if [[ -n "$WAIT_URL" ]]; then
    echo "[scheduler] waiting for app url (max=${WAIT_SECONDS}s): $WAIT_URL"
    for ((i = 1; i <= WAIT_SECONDS; i++)); do
        if curl -fsS "$WAIT_URL" >/dev/null 2>&1; then
            echo "[scheduler] app url reachable"
            break
        fi

        if [[ "$i" == "$WAIT_SECONDS" ]]; then
            echo "[scheduler] WARNING: app url not reachable after ${WAIT_SECONDS}s; continuing anyway"
        else
            sleep 1
        fi
    done
fi

php artisan scheduler:report --startup --no-interaction || true

exec "$@"

