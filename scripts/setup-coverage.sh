#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

LOG_TS_FORMAT="+%Y-%m-%d %H:%M:%S"

log() {
    local level="$1"
    shift
    printf "[%s] [%s] %s\n" "$(date "${LOG_TS_FORMAT}")" "${level}" "$*"
}

die() {
    log "ERROR" "$*"
    exit 1
}

usage() {
    cat <<'USAGE'
Usage: ./scripts/setup-coverage.sh [--sail] [--local] [--no-down] [--check-only]

Sets up a PHP coverage driver for tests.

Options:
  --sail        Use Laravel Sail (default).
  --local       Check local PHP extensions only (no installation).
  --no-down     Skip `sail down` before rebuild/restart.
  --check-only  Only verify coverage driver presence, do not restart.
USAGE
}

MODE="sail"
NO_DOWN="0"
CHECK_ONLY="0"

for arg in "$@"; do
    case "${arg}" in
        --sail) MODE="sail" ;;
        --local) MODE="local" ;;
        --no-down) NO_DOWN="1" ;;
        --check-only) CHECK_ONLY="1" ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            die "Unknown option: ${arg}"
            ;;
    esac
done

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

detect_grep() {
    if command -v rg >/dev/null 2>&1; then
        echo "rg -i"
    else
        echo "grep -i"
    fi
}

check_driver() {
    local php_cmd=("$@")
    local grep_cmd
    grep_cmd="$(detect_grep)"

    if "${php_cmd[@]}" -m | eval "${grep_cmd} -q '^xdebug$'"; then
        log "INFO" "Coverage driver detected: Xdebug"
        return 0
    fi

    if "${php_cmd[@]}" -m | eval "${grep_cmd} -q '^pcov$'"; then
        log "INFO" "Coverage driver detected: PCOV"
        return 0
    fi

    return 1
}

if [[ "${MODE}" == "local" ]]; then
    require_cmd php
    log "INFO" "Checking local PHP coverage drivers..."
    if check_driver php; then
        log "INFO" "Local coverage driver is available."
        exit 0
    fi
    die "No local coverage driver found (Xdebug/PCOV). Install one and re-run."
fi

if [[ ! -x "./vendor/bin/sail" ]]; then
    die "Laravel Sail not available. Run composer install or use --local."
fi

log "INFO" "Using Sail for coverage setup."
export SAIL_XDEBUG_MODE=coverage
log "INFO" "SAIL_XDEBUG_MODE=coverage"

if [[ "${CHECK_ONLY}" == "1" ]]; then
    log "INFO" "Check-only mode enabled."
else
    if [[ "${NO_DOWN}" == "0" ]]; then
        log "INFO" "Stopping containers..."
        ./vendor/bin/sail down
    else
        log "INFO" "Skipping sail down."
    fi

    log "INFO" "Rebuilding and starting containers with coverage enabled..."
    ./vendor/bin/sail up -d --build
fi

log "INFO" "Verifying coverage driver inside Sail..."
if check_driver ./vendor/bin/sail php; then
    log "INFO" "Coverage driver is ready. You can now run:"
    log "INFO" "  XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=90"
    exit 0
fi

die "Coverage driver still not available inside Sail."
