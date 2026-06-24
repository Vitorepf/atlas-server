# Cortex Adoption Receipt Ledger

`AtlasCortexAdoptionReceiptLedger` is an **append-only** JSON-lines ledger that records every scope which has a real, **schema-valid** Cortex today.

## Record shape

Each line is a JSON object with the nine canonical fields:

```json
{
  "recorded_at_unix": 1700000000,
  "repo_root": "/path/to/foreign-repo",
  "scope_root": "src",
  "schema_id": "atlas.cortex.facts.v1",
  "snapshot_id": "snap-...",
  "facts_path": "storage/cortex/facts/repo-scope.json",
  "units_count": 42,
  "orphans_count": 3,
  "clones_count": 1
}
```

## API

- `record(array $facts, string $factsPath): array` — validates `$facts` against `AtlasCortexUniversalFactsSchema::validate()` FIRST; on any error throws `InvalidArgumentException` and writes nothing. Only proven adoptions land.
- `list(): array` — returns all records sorted ascending by `recorded_at_unix`.
- `setRootForTesting(?string $path): void` — test seam, mirroring the pattern in `AtlasLoopScopeComprehensionReadModel`.
- `ledgerPath(): string` — resolved path (defaults to `storage_path('app/atlas/cortex/adoption.jsonl')`).

## Pétreo invariants

- **Append-only**: the implementation uses `file_put_contents(..., FILE_APPEND | LOCK_EX)` exclusively. Zero `fopen($path, 'w')`, zero truncating `file_put_contents`, zero `unlink(` calls. Enforced by string-grep in the unit test.
- **Schema-first**: a facts blob that fails `validate()` never reaches disk. The ledger refuses adoption rather than recording a non-validating snapshot.

## CLI

`atlas:loop:cortex-adoption-list [--json]` prints every recorded adoption (table by default; JSON with `--json`).
