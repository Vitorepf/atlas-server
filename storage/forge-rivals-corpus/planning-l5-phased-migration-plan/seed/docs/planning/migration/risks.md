# Risks inherited by the migration plan

- risk-1: replica lag spike during bulk backfill
- risk-2: dual-write race overwriting fresher row
- risk-3: cache poisoning between v1 reads and v2 writes
- risk-4: rollback window shorter than detection window
