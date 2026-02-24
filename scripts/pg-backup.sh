#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  ./scripts/pg-backup.sh [--out-dir DIR] [--name NAME] [--format {custom|plain}]
                        [--host HOST] [--port PORT] [--db DATABASE]
                        [--user USER] [--password PASSWORD]

Defaults:
  - Reads DB_* from .env when present (DB_CONNECTION must be pgsql).
  - Writes to storage/app/private/db-backups (gitignored).
  - Uses custom format (.dump) by default.
EOF
}

repo_root() {
  if command -v git >/dev/null 2>&1; then
    git rev-parse --show-toplevel 2>/dev/null && return 0
  fi
  (cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
}

sanitize() {
  # Safe for filenames across platforms.
  echo "$1" | tr -cs 'A-Za-z0-9._-' '_'
}

load_env_file() {
  local env_file="$1"
  [[ -f "$env_file" ]] || return 0

  # Minimal .env parser: KEY=VALUE with optional single/double quotes.
  while IFS='=' read -r key value; do
    [[ -n "${key:-}" ]] || continue
    [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
    [[ "$key" == DB_* ]] || continue

    value="${value:-}"
    value="${value%$'\r'}"
    if [[ "$value" =~ ^\".*\"$ ]]; then value="${value:1:${#value}-2}"; fi
    if [[ "$value" =~ ^\'.*\'$ ]]; then value="${value:1:${#value}-2}"; fi

    # Do not override explicit environment variables.
    if [[ -z "${!key:-}" ]]; then
      export "$key=$value"
    fi
  done < <(grep -E '^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' "$env_file" || true)
}

OUT_DIR=""
NAME=""
FORMAT="custom"
DB_HOST=""
DB_PORT=""
DB_DATABASE=""
DB_USERNAME=""
DB_PASSWORD=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --out-dir) OUT_DIR="${2:-}"; shift 2 ;;
    --name) NAME="${2:-}"; shift 2 ;;
    --format) FORMAT="${2:-}"; shift 2 ;;
    --host) DB_HOST="${2:-}"; shift 2 ;;
    --port) DB_PORT="${2:-}"; shift 2 ;;
    --db) DB_DATABASE="${2:-}"; shift 2 ;;
    --user) DB_USERNAME="${2:-}"; shift 2 ;;
    --password) DB_PASSWORD="${2:-}"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; usage; exit 2 ;;
  esac
done

ROOT="$(repo_root)"
cd "$ROOT"

load_env_file "$ROOT/.env"

if [[ "${DB_CONNECTION:-}" != "pgsql" ]]; then
  echo "Refusing to run: DB_CONNECTION is '${DB_CONNECTION:-<unset>}' (expected 'pgsql')." >&2
  echo "Set Postgres connection vars or pass --host/--port/--db/--user/--password explicitly." >&2
  exit 3
fi

if ! command -v pg_dump >/dev/null 2>&1; then
  echo "pg_dump not found in PATH." >&2
  echo "Install PostgreSQL client tools (pg_dump/psql) or run this on a host that has them." >&2
  exit 4
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
db_safe="$(sanitize "${DB_DATABASE:-}")"

if [[ -z "$OUT_DIR" ]]; then
  OUT_DIR="$ROOT/storage/app/private/db-backups"
fi
mkdir -p "$OUT_DIR"

ext="dump"
pg_format_arg=(-F c -Z 9)
if [[ "$FORMAT" == "plain" ]]; then
  ext="sql"
  pg_format_arg=(-F p)
elif [[ "$FORMAT" != "custom" ]]; then
  echo "Invalid --format '$FORMAT' (expected 'custom' or 'plain')." >&2
  exit 2
fi

if [[ -z "$NAME" ]]; then
  NAME="pgdump_${db_safe}_${timestamp}.${ext}"
else
  NAME="$(sanitize "$NAME")"
fi

out_path="$OUT_DIR/$NAME"

pg_args=(
  --host "${DB_HOST:?DB_HOST is required}"
  --port "${DB_PORT:?DB_PORT is required}"
  --username "${DB_USERNAME:?DB_USERNAME is required}"
  --no-owner
  --no-privileges
  "${pg_format_arg[@]}"
  --file "$out_path"
  "${DB_DATABASE:?DB_DATABASE is required}"
)

if [[ -n "${DB_PASSWORD:-}" ]]; then
  PGPASSWORD="$DB_PASSWORD" pg_dump "${pg_args[@]}"
else
  pg_dump "${pg_args[@]}"
fi

echo "Wrote backup: $out_path"
