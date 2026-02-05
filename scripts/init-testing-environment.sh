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

if ! docker info >/dev/null 2>&1; then
    echo "Error: Docker is not running."
    exit 1
fi

if docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE=(docker compose)
else
    DOCKER_COMPOSE=(docker-compose)
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

TEST_DB_HOST="$(read_env_value ".env.testing" "DB_HOST")"
TEST_DB_DATABASE="$(read_env_value ".env.testing" "DB_DATABASE")"
TEST_DB_USERNAME="$(read_env_value ".env.testing" "DB_USERNAME")"
TEST_DB_PASSWORD="$(read_env_value ".env.testing" "DB_PASSWORD")"
TEST_DB_ROOT_PASSWORD="$(read_env_value ".env.testing" "TEST_DB_ROOT_PASSWORD")"

if [[ "${TEST_DB_HOST}" != "mysql-testing" ]]; then
    echo "Error: .env.testing must use DB_HOST=mysql-testing to avoid touching local development data."
    exit 1
fi

if [[ -z "${TEST_DB_DATABASE}" || -z "${TEST_DB_USERNAME}" || -z "${TEST_DB_PASSWORD}" ]]; then
    echo "Error: DB_DATABASE, DB_USERNAME, and DB_PASSWORD must be set in .env.testing."
    exit 1
fi

if [[ ! "${TEST_DB_DATABASE}" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Error: DB_DATABASE '${TEST_DB_DATABASE}' is invalid. Use only letters, numbers, and underscores."
    exit 1
fi

if [[ -z "${TEST_DB_ROOT_PASSWORD}" ]]; then
    TEST_DB_ROOT_PASSWORD="${TEST_DB_PASSWORD}"
fi

export APP_ENV=testing
export TEST_DB_DATABASE
export TEST_DB_USERNAME
export TEST_DB_PASSWORD
export TEST_DB_ROOT_PASSWORD

echo "[INFO] Starting dedicated testing database container only (mysql-testing)..."
"${DOCKER_COMPOSE[@]}" --profile testing up -d mysql-testing

echo "[INFO] Waiting for mysql-testing to become ready..."
for _ in {1..30}; do
    if "${DOCKER_COMPOSE[@]}" --profile testing exec -T mysql-testing \
        mysqladmin ping -h 127.0.0.1 -uroot -p"${TEST_DB_ROOT_PASSWORD}" --silent >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

if ! "${DOCKER_COMPOSE[@]}" --profile testing exec -T mysql-testing \
    mysqladmin ping -h 127.0.0.1 -uroot -p"${TEST_DB_ROOT_PASSWORD}" --silent >/dev/null 2>&1; then
    echo "Error: mysql-testing did not become ready in time."
    exit 1
fi

run_artisan_testing() {
    "${DOCKER_COMPOSE[@]}" run --rm --no-deps \
        -e APP_ENV=testing \
        laravel.test php artisan "$@"
}

echo "[INFO] Clearing and rebuilding app config in testing environment..."
run_artisan_testing config:clear
run_artisan_testing cache:clear

echo "[INFO] Running migrations against mysql-testing (${TEST_DB_DATABASE})..."
run_artisan_testing migrate --force

echo "[INFO] Testing environment initialization complete."
echo "[INFO] This script does not touch the local development mysql container."
echo "[INFO] You can now run manual scripts in no-deps mode, for example:"
echo "       ./scripts/run-manual-test.sh tests/manual/verify_ttc_phase_1_persistence.php"
