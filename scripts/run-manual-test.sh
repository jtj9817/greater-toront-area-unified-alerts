#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

if [[ ! -f ".env.testing" ]]; then
    echo "Error: .env.testing is required. Refusing to run manual tests."
    exit 1
fi

if [[ -n "${APP_ENV:-}" && "${APP_ENV}" != "testing" ]]; then
    echo "Error: APP_ENV is '${APP_ENV}'. This script only runs for testing."
    exit 1
fi

if [[ ! -x "./scripts/init-testing-environment.sh" ]]; then
    echo "Error: scripts/init-testing-environment.sh is missing or not executable."
    exit 1
fi

if [[ $# -lt 1 ]]; then
    echo "Usage: ./scripts/run-manual-test.sh tests/manual/<script>.php [args...]"
    exit 1
fi

TARGET_SCRIPT="$1"
shift

if [[ ! -f "${TARGET_SCRIPT}" ]]; then
    echo "Error: Manual script not found: ${TARGET_SCRIPT}"
    exit 1
fi

if [[ "${TARGET_SCRIPT}" != tests/manual/* ]]; then
    echo "Error: Manual script must be inside tests/manual."
    exit 1
fi

if docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE=(docker compose)
else
    DOCKER_COMPOSE=(docker-compose)
fi

echo "[INFO] Initializing isolated testing environment..."
./scripts/init-testing-environment.sh

echo "[INFO] Running manual test in no-deps testing mode: ${TARGET_SCRIPT}"
"${DOCKER_COMPOSE[@]}" --profile testing run --rm --no-deps \
    --user "${APP_USER:-sail}" \
    -e APP_ENV=testing \
    laravel.test php "${TARGET_SCRIPT}" "$@"
