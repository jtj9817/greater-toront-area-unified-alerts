#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

ENV_FILE=".env.testing"
DB_DRIVER_OVERRIDE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --env-file)
            ENV_FILE="${2:-}"
            shift 2
            ;;
        --db)
            DB_DRIVER_OVERRIDE="${2:-}"
            shift 2
            ;;
        --help|-h)
            echo "Usage: ./scripts/init-testing-environment.sh [--env-file <file>] [--db mysql|pgsql]"
            exit 0
            ;;
        *)
            echo "Error: Unknown option '$1'."
            echo "Usage: ./scripts/init-testing-environment.sh [--env-file <file>] [--db mysql|pgsql]"
            exit 1
            ;;
    esac
done

if [[ ! -f "${ENV_FILE}" ]]; then
    echo "Error: ${ENV_FILE} is required. Refusing to initialize testing environment."
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

# Avoid docker compose interpolation warnings from laravel.test service config.
export WWWUSER="${WWWUSER:-$(id -u)}"
export WWWGROUP="${WWWGROUP:-$(id -g)}"

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

TEST_DB_CONNECTION="$(read_env_value "${ENV_FILE}" "DB_CONNECTION")"
TEST_DB_HOST="$(read_env_value "${ENV_FILE}" "DB_HOST")"
TEST_DB_PORT="$(read_env_value "${ENV_FILE}" "DB_PORT")"
TEST_DB_DATABASE="$(read_env_value "${ENV_FILE}" "DB_DATABASE")"
TEST_DB_USERNAME="$(read_env_value "${ENV_FILE}" "DB_USERNAME")"
TEST_DB_PASSWORD="$(read_env_value "${ENV_FILE}" "DB_PASSWORD")"
TEST_DB_ROOT_PASSWORD="$(read_env_value "${ENV_FILE}" "TEST_DB_ROOT_PASSWORD")"
TEST_PG_PASSWORD="$(read_env_value "${ENV_FILE}" "TEST_PG_PASSWORD")"

if [[ -n "${DB_DRIVER_OVERRIDE}" ]]; then
    TEST_DB_CONNECTION="${DB_DRIVER_OVERRIDE}"
fi

if [[ -z "${TEST_DB_CONNECTION}" ]]; then
    echo "Error: DB_CONNECTION is required in ${ENV_FILE}, or pass --db."
    exit 1
fi

if [[ -z "${TEST_DB_DATABASE}" || -z "${TEST_DB_USERNAME}" || -z "${TEST_DB_PASSWORD}" || -z "${TEST_DB_HOST}" || -z "${TEST_DB_PORT}" ]]; then
    echo "Error: DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_HOST, and DB_PORT must be set in ${ENV_FILE}."
    exit 1
fi

if [[ ! "${TEST_DB_DATABASE}" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Error: DB_DATABASE '${TEST_DB_DATABASE}' is invalid. Use only letters, numbers, and underscores."
    exit 1
fi

if [[ -z "${TEST_DB_ROOT_PASSWORD}" ]]; then
    TEST_DB_ROOT_PASSWORD="${TEST_DB_PASSWORD}"
fi

if [[ -z "${TEST_PG_PASSWORD}" ]]; then
    TEST_PG_PASSWORD="${TEST_DB_PASSWORD}"
fi

export APP_ENV=testing
export DB_CONNECTION="${TEST_DB_CONNECTION}"
export DB_HOST="${TEST_DB_HOST}"
export DB_PORT="${TEST_DB_PORT}"
export DB_DATABASE="${TEST_DB_DATABASE}"
export DB_USERNAME="${TEST_DB_USERNAME}"
export DB_PASSWORD="${TEST_DB_PASSWORD}"
export TEST_DB_DATABASE
export TEST_DB_USERNAME
export TEST_DB_PASSWORD
export TEST_DB_ROOT_PASSWORD
export TEST_PG_DATABASE="${TEST_DB_DATABASE}"
export TEST_PG_USERNAME="${TEST_DB_USERNAME}"
export TEST_PG_PASSWORD

case "${TEST_DB_CONNECTION}" in
    mysql|mariadb)
        if [[ "${TEST_DB_HOST}" != "mysql-testing" ]]; then
            echo "Error: ${ENV_FILE} must use DB_HOST=mysql-testing for ${TEST_DB_CONNECTION} testing."
            exit 1
        fi

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
        ;;
    pgsql)
        if [[ "${TEST_DB_HOST}" != "pgsql-testing" ]]; then
            echo "Error: ${ENV_FILE} must use DB_HOST=pgsql-testing for pgsql testing."
            exit 1
        fi

        echo "[INFO] Starting dedicated testing database container only (pgsql-testing)..."
        "${DOCKER_COMPOSE[@]}" --profile testing up -d pgsql-testing

        echo "[INFO] Waiting for pgsql-testing to become ready..."
        for _ in {1..30}; do
            if "${DOCKER_COMPOSE[@]}" --profile testing exec -T pgsql-testing \
                pg_isready -U "${TEST_PG_USERNAME}" -d "${TEST_PG_DATABASE}" >/dev/null 2>&1; then
                break
            fi
            sleep 2
        done

        if ! "${DOCKER_COMPOSE[@]}" --profile testing exec -T pgsql-testing \
            pg_isready -U "${TEST_PG_USERNAME}" -d "${TEST_PG_DATABASE}" >/dev/null 2>&1; then
            echo "Error: pgsql-testing did not become ready in time."
            exit 1
        fi
        ;;
    *)
        echo "Error: Unsupported DB_CONNECTION '${TEST_DB_CONNECTION}'. Expected mysql, mariadb, or pgsql."
        exit 1
        ;;
esac

run_artisan_testing() {
    "${DOCKER_COMPOSE[@]}" run --rm --no-deps \
        -e APP_ENV=testing \
        -e DB_CONNECTION="${TEST_DB_CONNECTION}" \
        -e DB_HOST="${TEST_DB_HOST}" \
        -e DB_PORT="${TEST_DB_PORT}" \
        -e DB_DATABASE="${TEST_DB_DATABASE}" \
        -e DB_USERNAME="${TEST_DB_USERNAME}" \
        -e DB_PASSWORD="${TEST_DB_PASSWORD}" \
        laravel.test php artisan "$@"
}

echo "[INFO] Clearing and rebuilding app config in testing environment..."
run_artisan_testing config:clear
run_artisan_testing cache:clear

echo "[INFO] Running migrations against ${TEST_DB_CONNECTION} testing database (${TEST_DB_DATABASE})..."
run_artisan_testing migrate --force

echo "[INFO] Testing environment initialization complete."
echo "[INFO] This script does not touch local development database containers."
echo "[INFO] You can now run manual scripts in no-deps mode, for example:"
echo "       ./scripts/run-manual-test.sh tests/manual/verify_ttc_phase_1_persistence.php"
echo "       ./scripts/run-manual-test.sh --db pgsql --env-file .env.testing.pgsql tests/manual/verify_feed_010_phase_2_provider_refactors_core_compatibility.php"
