#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

ENV_FILE=".env.testing"
DB_DRIVER_OVERRIDE=""

if [[ -n "${APP_ENV:-}" && "${APP_ENV}" != "testing" ]]; then
    echo "Error: APP_ENV is '${APP_ENV}'. This script only runs for testing."
    exit 1
fi

if [[ ! -x "./scripts/init-testing-environment.sh" ]]; then
    echo "Error: scripts/init-testing-environment.sh is missing or not executable."
    exit 1
fi

# Avoid docker compose interpolation warnings from laravel.test service config and
# let Sail drop privileges correctly (don't override container user).
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
            echo "Usage: ./scripts/run-manual-test.sh [--env-file <file>] [--db mysql|pgsql] (tests/manual|scripts/manual_tests)/<script>.php [args...]"
            exit 0
            ;;
        --*)
            echo "Error: Unknown option '$1'."
            echo "Usage: ./scripts/run-manual-test.sh [--env-file <file>] [--db mysql|pgsql] (tests/manual|scripts/manual_tests)/<script>.php [args...]"
            exit 1
            ;;
        *)
            break
            ;;
    esac
done

if [[ ! -f "${ENV_FILE}" ]]; then
    echo "Error: ${ENV_FILE} is required. Refusing to run manual tests."
    exit 1
fi

if [[ $# -lt 1 ]]; then
    echo "Usage: ./scripts/run-manual-test.sh [--env-file <file>] [--db mysql|pgsql] (tests/manual|scripts/manual_tests)/<script>.php [args...]"
    exit 1
fi

TARGET_SCRIPT="$1"
shift

if [[ ! -f "${TARGET_SCRIPT}" ]]; then
    echo "Error: Manual script not found: ${TARGET_SCRIPT}"
    exit 1
fi

if [[ "${TARGET_SCRIPT}" != tests/manual/* && "${TARGET_SCRIPT}" != scripts/manual_tests/* ]]; then
    echo "Error: Manual script must be inside tests/manual or scripts/manual_tests."
    exit 1
fi

TEST_DB_CONNECTION="$(read_env_value "${ENV_FILE}" "DB_CONNECTION")"
TEST_DB_HOST="$(read_env_value "${ENV_FILE}" "DB_HOST")"
TEST_DB_PORT="$(read_env_value "${ENV_FILE}" "DB_PORT")"
TEST_DB_DATABASE="$(read_env_value "${ENV_FILE}" "DB_DATABASE")"
TEST_DB_USERNAME="$(read_env_value "${ENV_FILE}" "DB_USERNAME")"
TEST_DB_PASSWORD="$(read_env_value "${ENV_FILE}" "DB_PASSWORD")"

if [[ -n "${DB_DRIVER_OVERRIDE}" ]]; then
    TEST_DB_CONNECTION="${DB_DRIVER_OVERRIDE}"
fi

if [[ -z "${TEST_DB_CONNECTION}" || -z "${TEST_DB_HOST}" || -z "${TEST_DB_PORT}" || -z "${TEST_DB_DATABASE}" || -z "${TEST_DB_USERNAME}" || -z "${TEST_DB_PASSWORD}" ]]; then
    echo "Error: DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD must be set in ${ENV_FILE}."
    exit 1
fi

if docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE=(docker compose)
else
    DOCKER_COMPOSE=(docker-compose)
fi

echo "[INFO] Initializing isolated testing environment (${TEST_DB_CONNECTION})..."
./scripts/init-testing-environment.sh --env-file "${ENV_FILE}" --db "${TEST_DB_CONNECTION}"

echo "[INFO] Running manual test in no-deps testing mode (${TEST_DB_CONNECTION}): ${TARGET_SCRIPT}"
"${DOCKER_COMPOSE[@]}" --profile testing run --rm --no-deps \
    -e APP_ENV=testing \
    -e DB_CONNECTION="${TEST_DB_CONNECTION}" \
    -e DB_HOST="${TEST_DB_HOST}" \
    -e DB_PORT="${TEST_DB_PORT}" \
    -e DB_DATABASE="${TEST_DB_DATABASE}" \
    -e DB_USERNAME="${TEST_DB_USERNAME}" \
    -e DB_PASSWORD="${TEST_DB_PASSWORD}" \
    laravel.test php "${TARGET_SCRIPT}" "$@"
