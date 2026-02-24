#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

OUTPUT_PATH="database/seeders/ProductionDataSeeder.php"
CHUNK_SIZE=500
MAX_BYTES=10485760
RUNNER_MODE="auto" # auto|local|sail
AUTO_STAGE=0
AUTO_COMMIT=0

usage() {
    cat <<'USAGE'
Usage: ./scripts/generate-production-seed.sh [options]

DEPRECATED: This workflow is superseded by SQL export/import.
Use ./scripts/export-alert-data.sh + php artisan db:import-sql for new transfers.

Generates production seeders from current alert tables, verifies them,
and optionally stages/commits generated seeder files.

Options:
  --path <path>         Output path for main seeder (default: database/seeders/ProductionDataSeeder.php)
  --chunk <n>           Chunk size for export command (default: 500)
  --max-bytes <n>       Max bytes per generated part file before split (default: 10485760)
  --sail                Force running Artisan commands through ./vendor/bin/sail artisan
  --no-sail             Force running Artisan commands through local php artisan
  --stage               Stage generated seeder files with git add (non-interactive)
  --commit              Create a commit after staging files (non-interactive default message)
  --help                Show this help message
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

confirm_prompt() {
    local prompt="$1"

    if [[ ! -t 0 ]]; then
        return 1
    fi

    local answer
    read -r -p "${prompt} [y/N]: " answer

    case "${answer}" in
        y|Y|yes|YES)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --path)
            OUTPUT_PATH="${2:-}"
            shift 2
            ;;
        --chunk)
            CHUNK_SIZE="${2:-}"
            shift 2
            ;;
        --max-bytes)
            MAX_BYTES="${2:-}"
            shift 2
            ;;
        --sail)
            RUNNER_MODE="sail"
            shift
            ;;
        --no-sail)
            RUNNER_MODE="local"
            shift
            ;;
        --stage)
            AUTO_STAGE=1
            shift
            ;;
        --commit)
            AUTO_STAGE=1
            AUTO_COMMIT=1
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
    log_error "--path cannot be empty."
    exit 1
fi

if ! is_positive_integer "${CHUNK_SIZE}"; then
    log_error "--chunk must be a positive integer."
    exit 1
fi

if ! is_positive_integer "${MAX_BYTES}"; then
    log_error "--max-bytes must be a positive integer."
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
        log_error "Unable to find a working Artisan runner. Install PHP locally or use Sail."
        exit 1
    fi
fi

run_artisan() {
    "${ARTISAN_PREFIX[@]}" "$@"
}

log_info "Using Artisan runner: ${ARTISAN_PREFIX[*]}"
log_info "Export path: ${OUTPUT_PATH}"
log_info "Chunk size: ${CHUNK_SIZE}"
log_info "Max bytes per file: ${MAX_BYTES}"
log_warn "DEPRECATED: Seeder export workflow is superseded."
log_warn "Use ./scripts/export-alert-data.sh and db:import-sql for new data transfers."

log_info "Running db:export-to-seeder..."
run_artisan db:export-to-seeder --path="${OUTPUT_PATH}" --chunk="${CHUNK_SIZE}" --max-bytes="${MAX_BYTES}"

if [[ ! -f "${OUTPUT_PATH}" ]]; then
    log_error "Expected seeder file was not generated: ${OUTPUT_PATH}"
    exit 1
fi

log_info "Running db:verify-production-seed..."
run_artisan db:verify-production-seed --path="${OUTPUT_PATH}"

output_dir="$(dirname "${OUTPUT_PATH}")"
main_stem="$(basename "${OUTPUT_PATH}" .php)"

declare -a generated_files
generated_files=("${OUTPUT_PATH}")

while IFS= read -r part_file; do
    generated_files+=("${part_file}")
done < <(find "${output_dir}" -maxdepth 1 -type f -name "${main_stem}_Part*.php" | sort)

log_info "Generated seeder files:"
for file_path in "${generated_files[@]}"; do
    if [[ -f "${file_path}" ]]; then
        file_size="$(wc -c < "${file_path}" | tr -d ' ')"
        echo "  - ${file_path} (${file_size} bytes)"
    fi
done

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    log_warn "Not inside a Git repository. Skipping stage/commit prompts."
    exit 0
fi

if [[ "${AUTO_STAGE}" -eq 1 ]]; then
    log_info "Staging generated seeder files..."
    git add -- "${generated_files[@]}"
elif confirm_prompt "Stage generated seeder files with git add?"; then
    log_info "Staging generated seeder files..."
    git add -- "${generated_files[@]}"
else
    log_info "Skipping git add."
fi

if [[ "${AUTO_COMMIT}" -eq 1 ]]; then
    log_info "Creating commit for generated seeders..."
    git commit -m "chore(db): refresh production seeders"
elif confirm_prompt "Create a commit for the staged seeder files now?"; then
    commit_message=""

    if [[ -t 0 ]]; then
        read -r -p "Commit message [chore(db): refresh production seeders]: " commit_message
    fi

    commit_message="${commit_message:-chore(db): refresh production seeders}"
    git commit -m "${commit_message}"
else
    log_info "Skipping git commit."
fi

log_info "Production seed generation workflow completed successfully."
