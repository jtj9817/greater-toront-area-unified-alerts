#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  ./scripts/mysql-backup.sh [--out-dir DIR] [--name NAME] [--compress]
                            [--host HOST] [--port PORT] [--db DATABASE]
                            [--user USER] [--password PASSWORD]

Defaults:
  - Reads DB_* from .env when present (DB_CONNECTION must be mysql/mariadb).
  - Writes to storage/app/private/db-backups (gitignored).
  - Output is a single .sql file (optionally gzipped).

Notes:
  - Uses --single-transaction for consistent InnoDB dumps.
  - Does not embed secrets in the output file name.
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
COMPRESS=0
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
    --compress) COMPRESS=1; shift 1 ;;
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

case "${DB_CONNECTION:-}" in
  mysql|mariadb) ;;
  *)
    echo "Refusing to run: DB_CONNECTION is '${DB_CONNECTION:-<unset>}' (expected 'mysql' or 'mariadb')." >&2
    echo "Set MySQL connection vars or pass --host/--port/--db/--user/--password explicitly." >&2
    exit 3
    ;;
esac

if ! command -v mysqldump >/dev/null 2>&1; then
  echo "mysqldump not found in PATH." >&2
  echo "Install MySQL client tools (mysqldump/mysql) or run this on a host that has them." >&2
  exit 4
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
db_safe="$(sanitize "${DB_DATABASE:-}")"

if [[ -z "$OUT_DIR" ]]; then
  OUT_DIR="$ROOT/storage/app/private/db-backups"
fi
mkdir -p "$OUT_DIR"

ext="sql"
if [[ "$COMPRESS" -eq 1 ]]; then
  ext="sql.gz"
fi

if [[ -z "$NAME" ]]; then
  NAME="mysqldump_${db_safe}_${timestamp}.${ext}"
else
  NAME="$(sanitize "$NAME")"
fi

out_path="$OUT_DIR/$NAME"

dump_args=(
  --host "${DB_HOST:?DB_HOST is required}"
  --port "${DB_PORT:?DB_PORT is required}"
  --user "${DB_USERNAME:?DB_USERNAME is required}"
  --single-transaction
  --quick
  --skip-lock-tables
  --set-gtid-purged=OFF
  --databases "${DB_DATABASE:?DB_DATABASE is required}"
)

tmp_out="$out_path.tmp"
rm -f "$tmp_out"

cleanup() {
  rm -f "$tmp_out"
}
trap cleanup EXIT

if [[ -n "${DB_PASSWORD:-}" ]]; then
  # MYSQL_PWD avoids exposing the password via argv; still visible to same-user processes.
  export MYSQL_PWD="$DB_PASSWORD"
fi

if [[ "$COMPRESS" -eq 1 ]]; then
  mysqldump "${dump_args[@]}" | gzip -c >"$tmp_out"
else
  mysqldump "${dump_args[@]}" >"$tmp_out"
fi

mv -f "$tmp_out" "$out_path"
trap - EXIT

echo "Wrote backup: $out_path"
