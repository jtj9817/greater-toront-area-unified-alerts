#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

SAIL_BIN="${SAIL_BIN:-./vendor/bin/sail}"
TODAY="${TODAY:-$(date +%F)}"
LOG_DIR="${LOG_DIR:-storage/logs}"
LOG_FILE="${LOG_FILE:-${LOG_DIR}/feed-023-runtime-verify-$(date +%Y%m%d-%H%M%S).log}"

info() {
    printf '[INFO] %s\n' "$*"
}

warn() {
    printf '[WARN] %s\n' "$*" >&2
}

error() {
    printf '[ERROR] %s\n' "$*" >&2
    exit 1
}

separator() {
    printf '\n%s\n' '------------------------------------------------------------'
}

confirm() {
    local prompt="$1"
    local reply

    read -r -p "${prompt} [y/N]: " reply
    [[ "${reply}" =~ ^[Yy]$ ]]
}

require_cmd() {
    local cmd="$1"
    command -v "${cmd}" >/dev/null 2>&1 || error "Required command not found: ${cmd}"
}

list_host_scheduler_processes() {
    ps -eo pid=,args= | awk '
        $1 ~ /^[0-9]+$/ && $2 == "php" && $3 == "artisan" &&
        ($4 == "schedule:work" || $4 == "schedule:run" || $4 == "scheduler:run-and-log") {
            printf "%s %s\n", $1, substr($0, index($0, $2))
        }
    '
}

search_logs() {
    local pattern="$1"
    shift

    if command -v rg >/dev/null 2>&1; then
        rg -n "${pattern}" "$@" -S || true
    else
        grep -nE "${pattern}" "$@" || true
    fi
}

list_container_scheduler_processes() {
    "${SAIL_BIN}" exec laravel.test sh -lc '
        ps -eo pid=,args= | awk '"'"'
            $1 ~ /^[0-9]+$/ && $2 == "php" && $3 == "artisan" &&
            ($4 == "schedule:work" || $4 == "schedule:run" || $4 == "scheduler:run-and-log") {
                printf "%s %s\n", $1, substr($0, index($0, $2))
            }
        '"'"'
    ' 2>/dev/null || true
}

ensure_no_active_scheduler_processes() {
    local host_processes
    local container_processes
    local found=0

    host_processes="$(list_host_scheduler_processes || true)"
    container_processes="$(list_container_scheduler_processes || true)"

    if [[ -n "${host_processes}" ]]; then
        warn "Active host scheduler processes detected:"
        printf '%s\n' "${host_processes}"
        found=1
    fi

    if [[ -n "${container_processes}" ]]; then
        warn "Active container scheduler processes detected:"
        printf '%s\n' "${container_processes}"
        found=1
    fi

    if (( found == 1 )); then
        return 1
    fi

    info "No active schedule:work / schedule:run / scheduler:run-and-log processes detected."

    return 0
}

mkdir -p "${LOG_DIR}"
: > "${LOG_FILE}"
exec > >(tee -a "${LOG_FILE}") 2>&1

separator
info "FEED-023 runtime verification helper"
info "Repo: ${ROOT_DIR}"
info "Date filter for logs: ${TODAY}"
info "Log file: ${LOG_FILE}"

separator
info "Stage 1: pre-check required tooling"
require_cmd php
require_cmd ps
require_cmd awk
require_cmd kill

if ! command -v crontab >/dev/null 2>&1; then
    warn "crontab command not found; host cron checks may be skipped."
fi

if [[ ! -x "${SAIL_BIN}" ]]; then
    error "Sail binary missing or not executable: ${SAIL_BIN}"
fi

if ! confirm "Proceed to Stage 2 (discover scheduler authorities)?"; then
    info "Stopped by user."
    exit 0
fi

separator
info "Stage 2: discover scheduler authorities"
info "Host scheduler processes (direct php artisan invocations):"

host_processes="$(list_host_scheduler_processes || true)"
if [[ -z "${host_processes}" ]]; then
    warn "No direct host scheduler authority process found."
else
    printf '%s\n' "${host_processes}"
fi

if command -v crontab >/dev/null 2>&1; then
    info "Host crontab entries matching scheduler commands:"
    crontab -l 2>/dev/null | {
        if command -v rg >/dev/null 2>&1; then
            rg -n "schedule:run|scheduler:run-and-log" -S || true
        else
            grep -nE "schedule:run|scheduler:run-and-log" || true
        fi
    }
fi

info "Container scheduler/cron entries:"
"${SAIL_BIN}" exec laravel.test sh -lc '
  echo "[container] crontab scheduler lines:";
  crontab -l 2>/dev/null | (command -v rg >/dev/null 2>&1 && rg -n "schedule:run|scheduler:run-and-log" -S || grep -nE "schedule:run|scheduler:run-and-log") || true;
  echo "[container] /etc/cron.d/laravel-scheduler:";
  cat /etc/cron.d/laravel-scheduler 2>/dev/null || true
'

info "Scheduler process safety check (before this script runs any scheduler command):"
if ! ensure_no_active_scheduler_processes; then
    warn "Resolve active scheduler processes before Stage 5."
    if ! confirm "Continue to Stage 3 for cleanup/inspection?"; then
        warn "No scheduler tick will be started while active scheduler processes exist."
        exit 1
    fi
fi

if ! confirm "Proceed to Stage 3 (optional duplicate process cleanup)?"; then
    info "Stopped by user."
    exit 0
fi

separator
info "Stage 3: optional duplicate host process cleanup"
mapfile -t scheduler_pids < <(list_host_scheduler_processes | awk '{print $1}')

if (( ${#scheduler_pids[@]} <= 1 )); then
    info "No duplicate direct host scheduler authority process detected."
else
    warn "Detected ${#scheduler_pids[@]} direct host scheduler authority processes:"
    printf '%s\n' "${host_processes}"

    if confirm "Attempt automatic cleanup now (kill extra host PIDs)?"; then
        read -r -p "Enter the PID to keep running: " keep_pid

        keep_valid=0
        for pid in "${scheduler_pids[@]}"; do
            if [[ "${pid}" == "${keep_pid}" ]]; then
                keep_valid=1
                break
            fi
        done

        if (( keep_valid == 0 )); then
            error "PID ${keep_pid} is not in the detected scheduler process list."
        fi

        for pid in "${scheduler_pids[@]}"; do
            if [[ "${pid}" == "${keep_pid}" ]]; then
                continue
            fi

            if kill "${pid}" 2>/dev/null; then
                info "Sent SIGTERM to PID ${pid}."
            else
                warn "Could not kill PID ${pid} without elevated privileges."
                warn "Run manually: sudo kill ${pid}"
            fi
        done

        sleep 1
        info "Scheduler processes after cleanup attempt:"
        list_host_scheduler_processes || true
    else
        warn "Skipping automatic cleanup. Resolve duplicate scheduler authorities manually before final sign-off."
    fi
fi

if ! confirm "Proceed to Stage 4 (runtime pre-flight checks in Sail)?"; then
    info "Stopped by user."
    exit 0
fi

separator
info "Stage 4: runtime pre-flight checks"
"${SAIL_BIN}" artisan tinker --execute="dump(config('cache.schedule_store'));"
"${SAIL_BIN}" artisan tinker --execute="cache()->store('redis')->put('scheduler:phase0:health','ok',60); dump(cache()->store('redis')->get('scheduler:phase0:health'));"
"${SAIL_BIN}" artisan optimize:clear

if ! confirm "Proceed to Stage 5 (run scheduler tick)?"; then
    info "Stopped by user."
    exit 0
fi

info "Final scheduler process safety check before Stage 5:"
if ! ensure_no_active_scheduler_processes; then
    warn "Active scheduler process found. Aborting before starting scheduler:run-and-log."
    exit 1
fi

separator
info "Stage 5: run one scheduler tick"
"${SAIL_BIN}" artisan scheduler:run-and-log --no-interaction

if ! confirm "Proceed to Stage 6 (log verification)?"; then
    info "Stopped by user."
    exit 0
fi

separator
info "Stage 6: verify logs"
info "Potential cache lock duplicate-key errors in laravel.log (today only):"
search_logs "\\[${TODAY} .*cache_locks_pkey|duplicate key value violates unique constraint \"cache_locks_pkey\"" storage/logs/laravel.log

info "Recent scheduler enqueues from queue_enqueues.log (today only):"
if command -v rg >/dev/null 2>&1; then
    rg -n "\\[${TODAY} [0-9:]+\\].*\"argv\":\\[\"artisan\",\"schedule:run\"\\]" storage/logs/queue_enqueues.log -S | tail -n 40 || true
else
    grep -nE "\\[${TODAY} [0-9:]+\\].*\"argv\":\\[\"artisan\",\"schedule:run\"\\]" storage/logs/queue_enqueues.log | tail -n 40 || true
fi

separator
info "Manual FEED-023 verification script completed."
info "If duplicate scheduler authorities still exist, resolve them manually and rerun this script."
