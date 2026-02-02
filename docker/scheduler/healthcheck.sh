#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html}"
MAX_AGE_MINUTES="${SCHEDULER_MAX_AGE_MINUTES:-5}"

cd "$APP_DIR"

php artisan scheduler:status --max-age="$MAX_AGE_MINUTES" --no-interaction

