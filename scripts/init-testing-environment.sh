#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

if [[ ! -f ".env.testing" ]]; then
    echo "Error: .env.testing is required. Refusing to initialize testing environment."
    exit 1
fi

if [[ -n "${APP_ENV:-}" && "${APP_ENV}" != "testing" ]]; then
    echo "Error: APP_ENV is '${APP_ENV}'. This script only runs for testing."
    exit 1
fi

if [[ ! -x "./vendor/bin/sail" ]]; then
    echo "Error: ./vendor/bin/sail not found or not executable. Run composer install first."
    exit 1
fi

read_env_value() {
    local env_file="$1"
    local env_key="$2"
    local raw

    raw="$(grep -E "^${env_key}=" "${env_file}" | tail -n 1 | cut -d '=' -f2- || true)"
    raw="${raw%\"}"
    raw="${raw#\"}"
    raw="${raw%\'}"
    raw="${raw#\'}"

    printf '%s' "${raw}"
}

TEST_DB_DATABASE="$(read_env_value ".env.testing" "DB_DATABASE")"

if [[ -z "${TEST_DB_DATABASE}" ]]; then
    echo "Error: DB_DATABASE is missing in .env.testing."
    exit 1
fi

if [[ ! "${TEST_DB_DATABASE}" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Error: DB_DATABASE '${TEST_DB_DATABASE}' is invalid. Use only letters, numbers, and underscores."
    exit 1
fi

ROOT_DB_PASSWORD="$(read_env_value ".env" "DB_PASSWORD")"
ROOT_DB_PASSWORD="${ROOT_DB_PASSWORD:-password}"

echo "[INFO] Starting Sail services..."
./vendor/bin/sail up -d

echo "[INFO] Waiting for MySQL to become ready..."
for _ in {1..30}; do
    if ./vendor/bin/sail mysqladmin ping -h mysql -uroot -p"${ROOT_DB_PASSWORD}" --silent >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

if ! ./vendor/bin/sail mysqladmin ping -h mysql -uroot -p"${ROOT_DB_PASSWORD}" --silent >/dev/null 2>&1; then
    echo "Error: MySQL did not become ready in time."
    exit 1
fi

echo "[INFO] Ensuring testing database exists: ${TEST_DB_DATABASE}"
./vendor/bin/sail mysql -uroot -p"${ROOT_DB_PASSWORD}" -e "CREATE DATABASE IF NOT EXISTS \`${TEST_DB_DATABASE}\`;"

echo "[INFO] Clearing and rebuilding app config in testing environment..."
APP_ENV=testing ./vendor/bin/sail artisan config:clear
APP_ENV=testing ./vendor/bin/sail artisan cache:clear

echo "[INFO] Running migrations for testing database..."
APP_ENV=testing ./vendor/bin/sail artisan migrate --force

echo "[INFO] Testing environment initialization complete."
echo "[INFO] You can now run manual scripts, for example:"
echo "       ./vendor/bin/sail php tests/manual/verify_ttc_phase_1_persistence.php"
