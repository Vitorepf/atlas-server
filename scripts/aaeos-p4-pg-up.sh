#!/usr/bin/env bash
# Provision local ATLAS_P4_PG_* durable Postgres (docker) for AAEOS P4 contracts.
# Usage: source <(./scripts/aaeos-p4-pg-up.sh --export)
set -euo pipefail

NAME="${ATLAS_P4_PG_CONTAINER:-atlas-p4-pg}"
PORT="${ATLAS_P4_PG_PORT:-55449}"
DB="${ATLAS_P4_PG_DATABASE:-atlas_p4_main}"
PRODUCER_USER="${ATLAS_P4_PG_PRODUCER_USERNAME:-atlas_p4_producer}"
VERIFIER_USER="${ATLAS_P4_PG_VERIFIER_USERNAME:-atlas_p4_verifier}"
PRODUCER_PASS="${ATLAS_P4_PG_PRODUCER_PASSWORD:-p4_producer_local}"
VERIFIER_PASS="${ATLAS_P4_PG_VERIFIER_PASSWORD:-p4_verifier_local}"

if ! docker ps --format '{{.Names}}' | grep -qx "$NAME"; then
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker run -d --name "$NAME" \
    -e POSTGRES_DB="$DB" \
    -e POSTGRES_HOST_AUTH_METHOD=trust \
    -p "127.0.0.1:${PORT}:5432" \
    postgres:16-alpine >/dev/null
fi

for _ in $(seq 1 30); do
  if docker exec "$NAME" pg_isready -U postgres >/dev/null 2>&1; then
    break
  fi
  sleep 0.5
done

docker exec "$NAME" psql -U postgres -d "$DB" -v ON_ERROR_STOP=1 <<SQL >/dev/null
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${PRODUCER_USER}') THEN
    CREATE ROLE ${PRODUCER_USER} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS PASSWORD '${PRODUCER_PASS}';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${VERIFIER_USER}') THEN
    CREATE ROLE ${VERIFIER_USER} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS PASSWORD '${VERIFIER_PASS}';
  END IF;
END;
\$\$;
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
BEGIN NEW.updated_at = CURRENT_TIMESTAMP; RETURN NEW; END; \$fn\$;
SQL

export ATLAS_ALLOW_LIVE_DB_TESTS=1
export ATLAS_P4_PG_HOST=127.0.0.1
export ATLAS_P4_PG_PORT="$PORT"
export ATLAS_P4_PG_DATABASE="$DB"
export ATLAS_P4_PG_SETUP_URL="pgsql://postgres@127.0.0.1:${PORT}/${DB}"
export ATLAS_P4_PG_PRODUCER_URL="pgsql://${PRODUCER_USER}:${PRODUCER_PASS}@127.0.0.1:${PORT}/${DB}"
export ATLAS_P4_PG_VERIFIER_URL="pgsql://${VERIFIER_USER}:${VERIFIER_PASS}@127.0.0.1:${PORT}/${DB}"

if [[ "${1:-}" == "--export" ]]; then
  cat <<EOF
export ATLAS_ALLOW_LIVE_DB_TESTS=1
export ATLAS_P4_PG_HOST=127.0.0.1
export ATLAS_P4_PG_PORT=${PORT}
export ATLAS_P4_PG_DATABASE=${DB}
export ATLAS_P4_PG_SETUP_URL=pgsql://postgres@127.0.0.1:${PORT}/${DB}
export ATLAS_P4_PG_PRODUCER_URL=pgsql://${PRODUCER_USER}:${PRODUCER_PASS}@127.0.0.1:${PORT}/${DB}
export ATLAS_P4_PG_VERIFIER_URL=pgsql://${VERIFIER_USER}:${VERIFIER_PASS}@127.0.0.1:${PORT}/${DB}
EOF
else
  echo "atlas-p4-pg ready on 127.0.0.1:${PORT} db=${DB}"
  echo "export ATLAS_P4_PG_PRODUCER_URL=pgsql://${PRODUCER_USER}:***@127.0.0.1:${PORT}/${DB}"
  echo "export ATLAS_P4_PG_VERIFIER_URL=pgsql://${VERIFIER_USER}:***@127.0.0.1:${PORT}/${DB}"
  echo "source: source <($0 --export)"
fi
