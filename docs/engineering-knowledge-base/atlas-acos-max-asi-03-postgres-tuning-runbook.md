# ACOS Max ASI-03 - Postgres 16 memory tuning runbook

## Scope

ASI-03 sizes the local Atlas Postgres 16 container on host port `5433` for the real corpus. The change is limited to:

- `docker/postgres/16/acos-max-asi-03.conf`
- the `db` service mount/command in `docker-compose.yml`
- `scripts/acos-max-asi03-postgres-tuning.sh`

Do not add partitioning, pgbouncer, sharding, or schema rewrites in this slice.

## Shipped profile

The mounted config wraps the generated PGDATA config:

```conf
include '/var/lib/postgresql/data/postgresql.conf'
shared_buffers = '6GB'
effective_cache_size = '24GB'
maintenance_work_mem = '2GB'
```

Autovacuum global defaults are lowered for the memory-corpus write pattern. When the live DB is reachable, the script also applies table storage parameters to `public.atlas_memory_entry_usages`:

```sql
ALTER TABLE public.atlas_memory_entry_usages SET (
  autovacuum_vacuum_scale_factor = 0.02,
  autovacuum_analyze_scale_factor = 0.01,
  autovacuum_vacuum_threshold = 1000,
  autovacuum_analyze_threshold = 1000
);
```

## Verify the repo file without touching the DB

```bash
scripts/acos-max-asi03-postgres-tuning.sh --verify-file
```

Expected:

- `shared_buffers` is at least 4GB and at most 8GB.
- `effective_cache_size` is at least 16GB.
- `maintenance_work_mem` is at least 1GB.
- autovacuum keys are present.

## Apply in the live Docker container

Safe path when the operator accepts a brief DB restart:

```bash
scripts/acos-max-asi03-postgres-tuning.sh --restart-db
```

That command recreates/restarts only `docker compose` service `db`; the named volume `pgdata` is preserved. It then applies the table autovacuum options and checks live settings.

If the operator wants to restart manually:

```bash
docker compose up -d db
scripts/acos-max-asi03-postgres-tuning.sh --verify
```

## Live acceptance check

```bash
/opt/homebrew/opt/libpq/bin/psql -h 127.0.0.1 -p 5433 -U atlas -d atlas -c 'SHOW shared_buffers'
/opt/homebrew/opt/libpq/bin/psql -h 127.0.0.1 -p 5433 -U atlas -d atlas -c 'SHOW effective_cache_size'
scripts/acos-max-asi03-postgres-tuning.sh --live-show
```

Acceptance passes when live `shared_buffers >= 4GB` and `effective_cache_size >= 16GB`.

If Postgres is not reachable, or a restart would interrupt operator work, record the live SHOW as `pending_window` only after `--verify-file` passes.

## Reversal

1. Remove the ASI-03 `db` service mount and `config_file` command from `docker-compose.yml`.
2. Restart only the DB service:

   ```bash
   docker compose up -d db
   ```

3. Reset table-specific autovacuum options:

   ```bash
   scripts/acos-max-asi03-postgres-tuning.sh --revert-autovacuum
   ```

No data wipe, database recreate, or volume removal is part of this runbook.

## Evidence

Append ASI-03 evidence to:

```text
storage/app/atlas/evidence/acos-max-asi-03-postgres-tuning.jsonl
```

Record:

- before live `SHOW` values when reachable;
- after shipped-file validation output;
- after live `SHOW` pass, or `pending_window` reason if restart is deferred.
