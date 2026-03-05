#!/usr/bin/env bash
set -euo pipefail

QUEUE_WORKER_SAIL_BIN="${QUEUE_WORKER_SAIL_BIN:-./vendor/bin/sail}"
QUEUE_WORKER_TRIES="${QUEUE_WORKER_TRIES:-3}"
QUEUE_WORKER_SLEEP_SECONDS="${QUEUE_WORKER_SLEEP_SECONDS:-3}"
QUEUE_WORKER_MAX_JOBS="${QUEUE_WORKER_MAX_JOBS:-1000}"
QUEUE_WORKER_MAX_TIME="${QUEUE_WORKER_MAX_TIME:-}"
QUEUE_WORKER_MEMORY="${QUEUE_WORKER_MEMORY:-}"
QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-}"
QUEUE_WORKER_QUEUE="${QUEUE_WORKER_QUEUE:-}"
QUEUE_WORKER_RESTART_DELAY_SECONDS="${QUEUE_WORKER_RESTART_DELAY_SECONDS:-1}"

shutdown_requested=0
child_pid=""

terminate() {
    shutdown_requested=1

    if [[ -n "$child_pid" ]] && kill -0 "$child_pid" 2>/dev/null; then
        kill "$child_pid" 2>/dev/null || true
        wait "$child_pid" 2>/dev/null || true
    fi
}

build_command() {
    local command=(
        "$QUEUE_WORKER_SAIL_BIN"
        artisan
        queue:work
        "--tries=$QUEUE_WORKER_TRIES"
        "--sleep=$QUEUE_WORKER_SLEEP_SECONDS"
    )

    if [[ -n "$QUEUE_WORKER_MAX_JOBS" ]]; then
        command+=("--max-jobs=$QUEUE_WORKER_MAX_JOBS")
    fi

    if [[ -n "$QUEUE_WORKER_MAX_TIME" ]]; then
        command+=("--max-time=$QUEUE_WORKER_MAX_TIME")
    fi

    if [[ -n "$QUEUE_WORKER_MEMORY" ]]; then
        command+=("--memory=$QUEUE_WORKER_MEMORY")
    fi

    if [[ -n "$QUEUE_WORKER_TIMEOUT" ]]; then
        command+=("--timeout=$QUEUE_WORKER_TIMEOUT")
    fi

    if [[ -n "$QUEUE_WORKER_QUEUE" ]]; then
        command+=("--queue=$QUEUE_WORKER_QUEUE")
    fi

    command+=("$@")

    printf '%s\n' "${command[@]}"
}

trap terminate INT TERM

run_count=0

while true; do
    if [[ "$shutdown_requested" == "1" ]]; then
        exit 0
    fi

    run_count=$((run_count + 1))
    mapfile -t command < <(build_command "$@")

    echo "[dev-queue-worker] starting queue worker run=${run_count}: ${command[*]}"

    "${command[@]}" &
    child_pid=$!

    if wait "$child_pid"; then
        exit_code=0
    else
        exit_code=$?
    fi

    child_pid=""

    if [[ "$shutdown_requested" == "1" ]]; then
        exit 0
    fi

    if [[ "$exit_code" -eq 0 ]]; then
        echo "[dev-queue-worker] queue worker exited cleanly with code 0; restarting after ${QUEUE_WORKER_RESTART_DELAY_SECONDS}s"
        sleep "$QUEUE_WORKER_RESTART_DELAY_SECONDS"
        continue
    fi

    echo "[dev-queue-worker] queue worker exited with code ${exit_code}; not restarting" >&2
    exit "$exit_code"
done
