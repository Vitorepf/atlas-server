#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF_FILE="${ASI03_POSTGRES_CONF:-$ROOT_DIR/docker/postgres/16/acos-max-asi-03.conf}"
PSQL_BIN="${PSQL_BIN:-}"
PGHOST="${PGHOST:-127.0.0.1}"
PGPORT="${PGPORT:-5433}"
PGDATABASE="${PGDATABASE:-atlas}"
PGUSER="${PGUSER:-atlas}"
export PGPASSWORD="${PGPASSWORD:-atlas_local_2026}"

if [[ -z "$PSQL_BIN" ]]; then
  if [[ -x /opt/homebrew/opt/libpq/bin/psql ]]; then
    PSQL_BIN=/opt/homebrew/opt/libpq/bin/psql
  else
    PSQL_BIN="$(command -v psql || true)"
  fi
fi

usage() {
  cat <<'EOF'
Usage: scripts/acos-max-asi03-postgres-tuning.sh [mode]

Modes:
  --verify-file        Validate the shipped ASI-03 Postgres conf only.
  --verify            Validate the file, apply table autovacuum if reachable,
                      and report live SHOW pass or pending_window.
  --live-show         Require live shared_buffers/effective_cache_size to pass.
  --apply-autovacuum  Apply atlas_memory_entry_usages table autovacuum options.
  --revert-autovacuum Reset atlas_memory_entry_usages table autovacuum options.
  --restart-db        Recreate/restart only docker compose service "db", then verify.

Environment:
  PSQL_BIN, PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD, ASI03_POSTGRES_CONF
EOF
}

extract_setting() {
  local key="$1"
  awk -v key="$key" '
    $0 ~ "^[[:space:]]*" key "[[:space:]]*=" {
      sub(/#.*/, "");
      sub("^[^=]*=", "");
      gsub(/[[:space:]'\''"]/, "");
      print;
      exit;
    }
  ' "$CONF_FILE"
}

size_to_mb() {
  php -r '$value=$argv[1] ?? ""; if (! preg_match("/^\s*([0-9]+(?:\.[0-9]+)?)([a-zA-Z]+)?\s*$/", $value, $m)) { fwrite(STDERR, "invalid size: ".$value.PHP_EOL); exit(1); } $n=(float) $m[1]; $unit=strtolower($m[2] ?? "mb"); $mb=match ($unit) { "gb", "gib" => $n * 1024, "mb", "mib" => $n, "kb", "kib" => $n / 1024, "b" => $n / 1048576, default => -1 }; if ($mb < 0) { fwrite(STDERR, "unknown size unit: ".$unit.PHP_EOL); exit(1); } echo (string) (int) round($mb);' "$1"
}

require_setting_range_mb() {
  local key="$1"
  local min_mb="$2"
  local max_mb="${3:-}"
  local value
  local mb

  value="$(extract_setting "$key")"
  if [[ -z "$value" ]]; then
    echo "ASI-03 file check failed: missing $key in $CONF_FILE" >&2
    exit 1
  fi

  mb="$(size_to_mb "$value")"
  if (( mb < min_mb )); then
    echo "ASI-03 file check failed: $key=$value (${mb}MB) < ${min_mb}MB" >&2
    exit 1
  fi
  if [[ -n "$max_mb" ]] && (( mb > max_mb )); then
    echo "ASI-03 file check failed: $key=$value (${mb}MB) > ${max_mb}MB" >&2
    exit 1
  fi

  echo "file_ok: $key=$value (${mb}MB)"
}

check_file() {
  if [[ ! -f "$CONF_FILE" ]]; then
    echo "ASI-03 file check failed: missing $CONF_FILE" >&2
    exit 1
  fi

  if ! awk 'index($0, "include '\''/var/lib/postgresql/data/postgresql.conf'\''") > 0 { found=1 } END { exit found ? 0 : 1 }' "$CONF_FILE"; then
    echo "ASI-03 file check failed: wrapper must include PGDATA postgresql.conf" >&2
    exit 1
  fi

  require_setting_range_mb shared_buffers 4096 8192
  require_setting_range_mb effective_cache_size 16384
  require_setting_range_mb maintenance_work_mem 1024

  for key in autovacuum autovacuum_max_workers autovacuum_naptime autovacuum_vacuum_cost_limit autovacuum_vacuum_scale_factor autovacuum_analyze_scale_factor autovacuum_vacuum_threshold autovacuum_analyze_threshold; do
    if [[ -z "$(extract_setting "$key")" ]]; then
      echo "ASI-03 file check failed: missing $key in $CONF_FILE" >&2
      exit 1
    fi
  done

  echo "file_ok: autovacuum globals present"
}

require_psql() {
  if [[ -z "$PSQL_BIN" || ! -x "$PSQL_BIN" ]]; then
    echo "pending_window: psql binary unavailable; set PSQL_BIN or install libpq" >&2
    return 1
  fi
}

psql_base() {
  "$PSQL_BIN" -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d "$PGDATABASE" -v ON_ERROR_STOP=1 "$@"
}

can_connect() {
  require_psql >/dev/null 2>&1 || return 1
  psql_base -Atc 'SELECT 1' >/dev/null 2>&1
}

apply_autovacuum() {
  require_psql
  psql_base <<'SQL'
DO $$
BEGIN
  IF to_regclass('public.atlas_memory_entry_usages') IS NULL THEN
    RAISE NOTICE 'atlas_memory_entry_usages is absent; skipping ASI-03 table autovacuum options';
  ELSE
    ALTER TABLE public.atlas_memory_entry_usages SET (
      autovacuum_vacuum_scale_factor = 0.02,
      autovacuum_analyze_scale_factor = 0.01,
      autovacuum_vacuum_threshold = 1000,
      autovacuum_analyze_threshold = 1000
    );
  END IF;
END $$;
SQL
  echo "table_autovacuum_ok: atlas_memory_entry_usages storage params applied or table absent"
}

revert_autovacuum() {
  require_psql
  psql_base <<'SQL'
DO $$
BEGIN
  IF to_regclass('public.atlas_memory_entry_usages') IS NULL THEN
    RAISE NOTICE 'atlas_memory_entry_usages is absent; nothing to reset';
  ELSE
    ALTER TABLE public.atlas_memory_entry_usages RESET (
      autovacuum_vacuum_scale_factor,
      autovacuum_analyze_scale_factor,
      autovacuum_vacuum_threshold,
      autovacuum_analyze_threshold
    );
  END IF;
END $$;
SQL
  echo "table_autovacuum_reverted: atlas_memory_entry_usages storage params reset or table absent"
}

pg_setting_mb() {
  local rows="$1"
  local key="$2"
  printf '%s\n' "$rows" | awk -F '	' -v key="$key" '
    $1 == key {
      if ($3 == "8kB") { print int(($2 * 8) / 1024); exit; }
      if ($3 == "kB") { print int($2 / 1024); exit; }
      if ($3 == "MB") { print int($2); exit; }
      if ($3 == "GB") { print int($2 * 1024); exit; }
      print int($2); exit;
    }
  '
}

live_show() {
  require_psql
  local rows
  rows="$(psql_base -F $'\t' -Atc "SELECT name, setting, unit FROM pg_settings WHERE name IN ('shared_buffers', 'effective_cache_size', 'maintenance_work_mem') ORDER BY name")"
  printf '%s\n' "$rows"

  local shared_mb
  local effective_mb
  shared_mb="$(pg_setting_mb "$rows" shared_buffers)"
  effective_mb="$(pg_setting_mb "$rows" effective_cache_size)"

  if [[ -z "$shared_mb" || -z "$effective_mb" ]]; then
    echo "live_show_failed: required pg_settings rows missing" >&2
    exit 1
  fi
  if (( shared_mb < 4096 || effective_mb < 16384 )); then
    echo "pending_window: live Postgres has shared_buffers=${shared_mb}MB effective_cache_size=${effective_mb}MB; restart db with ASI-03 compose config to activate"
    return 2
  fi

  echo "live_show_passed: shared_buffers=${shared_mb}MB effective_cache_size=${effective_mb}MB"
}

verify() {
  check_file
  if ! can_connect; then
    echo "pending_window: Postgres @${PGHOST}:${PGPORT}/${PGDATABASE} is not reachable; shipped file is valid"
    return 0
  fi

  apply_autovacuum
  if ! live_show; then
    return 0
  fi
}

restart_db() {
  check_file
  (cd "$ROOT_DIR" && docker compose up -d db)
  for _ in {1..60}; do
    if can_connect; then
      break
    fi
    sleep 1
  done
  verify
}

mode="${1:---verify}"
case "$mode" in
  --help|-h)
    usage
    ;;
  --verify-file)
    check_file
    ;;
  --verify)
    verify
    ;;
  --live-show)
    check_file
    live_show
    ;;
  --apply-autovacuum)
    apply_autovacuum
    ;;
  --revert-autovacuum)
    revert_autovacuum
    ;;
  --restart-db)
    restart_db
    ;;
  *)
    usage >&2
    exit 1
    ;;
esac
