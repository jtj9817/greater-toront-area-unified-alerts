#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

OUTPUT_PATH="storage/app/alert-export.sql"
TABLES=""
CHUNK_SIZE=500
USE_COMPRESS=0
USE_NO_HEADER=0
RUNNER_MODE="auto" # auto|local|sail

usage() {
    cat <<'USAGE'
Usage: ./scripts/export-alert-data.sh [options]

Runs `db:export-sql` and prints file transfer/import guidance for operators.

Options:
  --output <path>       Output path for SQL export (default: storage/app/alert-export.sql)
  --tables <csv>        Comma-separated table list (default: fire_incidents,police_calls,transit_alerts,go_transit_alerts)
  --chunk <n>           Rows per VALUES batch (default: 500)
  --compress            Generate gzip output (.sql.gz)
  --no-header           Omit SQL header statements
  --sail                Force running command through ./vendor/bin/sail artisan
  --no-sail             Force running command through local php artisan
  --help                Show this help message

Examples:
  ./scripts/export-alert-data.sh --sail --compress
  ./scripts/export-alert-data.sh --output storage/app/private/gta-alerts.sql --chunk 1000
USAGE
}

log_info() {
    echo "[INFO] $*"
}

log_warn() {
    echo "[WARN] $*"
}

log_error() {
    echo "[ERROR] $*" >&2
}

is_positive_integer() {
    [[ "$1" =~ ^[1-9][0-9]*$ ]]
}

resolve_absolute_path() {
    local target="$1"

    if [[ "${target}" = /* ]]; then
        echo "${target}"
        return
    fi

    echo "${ROOT_DIR}/${target#./}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --output)
            OUTPUT_PATH="${2:-}"
            shift 2
            ;;
        --tables)
            TABLES="${2:-}"
            shift 2
            ;;
        --chunk)
            CHUNK_SIZE="${2:-}"
            shift 2
            ;;
        --compress)
            USE_COMPRESS=1
            shift
            ;;
        --no-header)
            USE_NO_HEADER=1
            shift
            ;;
        --sail)
            RUNNER_MODE="sail"
            shift
            ;;
        --no-sail)
            RUNNER_MODE="local"
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            usage
            exit 1
            ;;
    esac
done

if [[ -z "${OUTPUT_PATH}" ]]; then
    log_error "--output cannot be empty."
    exit 1
fi

if ! is_positive_integer "${CHUNK_SIZE}"; then
    log_error "--chunk must be a positive integer."
    exit 1
fi

if [[ ! -f "artisan" ]]; then
    log_error "artisan not found in repository root: ${ROOT_DIR}"
    exit 1
fi

ARTISAN_PREFIX=()

if [[ "${RUNNER_MODE}" == "sail" ]]; then
    if [[ ! -x "./vendor/bin/sail" ]]; then
        log_error "./vendor/bin/sail not found or not executable."
        exit 1
    fi

    ARTISAN_PREFIX=("./vendor/bin/sail" "artisan")
elif [[ "${RUNNER_MODE}" == "local" ]]; then
    if ! command -v php >/dev/null 2>&1; then
        log_error "php is not installed or not available in PATH."
        exit 1
    fi

    ARTISAN_PREFIX=("php" "artisan")
else
    if command -v php >/dev/null 2>&1 && php artisan --version >/dev/null 2>&1; then
        ARTISAN_PREFIX=("php" "artisan")
    elif [[ -x "./vendor/bin/sail" ]]; then
        ARTISAN_PREFIX=("./vendor/bin/sail" "artisan")
    else
        log_error "Unable to find a working Artisan runner. Install PHP locally or use --sail."
        exit 1
    fi
fi

run_artisan() {
    "${ARTISAN_PREFIX[@]}" "$@"
}

final_output_path="${OUTPUT_PATH}"
if [[ "${USE_COMPRESS}" -eq 1 && "${final_output_path}" != *.gz ]]; then
    final_output_path="${final_output_path}.gz"
fi

log_info "Using Artisan runner: ${ARTISAN_PREFIX[*]}"
log_info "Output path: ${OUTPUT_PATH}"
log_info "Chunk size: ${CHUNK_SIZE}"
if [[ -n "${TABLES}" ]]; then
    log_info "Tables: ${TABLES}"
else
    log_info "Tables: default alert tables"
fi
if [[ "${USE_COMPRESS}" -eq 1 ]]; then
    log_info "Compression: enabled"
else
    log_info "Compression: disabled"
fi
if [[ "${USE_NO_HEADER}" -eq 1 ]]; then
    log_warn "Header statements will be omitted (--no-header)."
fi

command_args=("db:export-sql" "--output=${OUTPUT_PATH}" "--chunk=${CHUNK_SIZE}")

if [[ -n "${TABLES}" ]]; then
    command_args+=("--tables=${TABLES}")
fi

if [[ "${USE_COMPRESS}" -eq 1 ]]; then
    command_args+=("--compress")
fi

if [[ "${USE_NO_HEADER}" -eq 1 ]]; then
    command_args+=("--no-header")
fi

log_info "Running db:export-sql..."
run_artisan "${command_args[@]}"

if [[ ! -f "${final_output_path}" ]]; then
    log_error "Expected export file was not generated: ${final_output_path}"
    exit 1
fi

absolute_output_path="$(resolve_absolute_path "${final_output_path}")"
file_size_bytes="$(wc -c < "${final_output_path}" | tr -d ' ')"
file_name="$(basename "${final_output_path}")"
checksum=""

if command -v sha256sum >/dev/null 2>&1; then
    checksum="$(sha256sum "${final_output_path}" | awk '{print $1}')"
elif command -v shasum >/dev/null 2>&1; then
    checksum="$(shasum -a 256 "${final_output_path}" | awk '{print $1}')"
fi

echo
log_info "Export completed successfully."
echo "  File: ${absolute_output_path}"
echo "  Size: ${file_size_bytes} bytes"

if [[ -n "${checksum}" ]]; then
    echo "  SHA256: ${checksum}"
fi

echo
echo "Transfer instructions:"
echo "1. Copy from source host to destination machine (run on destination):"
echo "   scp <user>@<source-host>:${absolute_output_path} ./"
echo "2. Verify checksum after transfer:"
if [[ -n "${checksum}" ]]; then
    echo "   sha256sum ${file_name}   # expected ${checksum}"
else
    echo "   sha256sum ${file_name}"
fi
echo "3. Import on destination host:"
if [[ "${final_output_path}" == *.gz ]]; then
    echo "   gunzip -c ${file_name} > ${file_name%.gz}"
    echo "   php artisan db:import-sql --file=/path/to/${file_name%.gz} --force"
else
    echo "   php artisan db:import-sql --file=/path/to/${file_name} --force"
fi
