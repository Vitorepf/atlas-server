# Loop Wire Format

The Loop ⇄ Cortex ⇄ Maestro wire format is the typed contract that lets the three
primitives exchange messages without losing facts. `atlas:loop:wire` surfaces it
to the operator.

## CLI

```
php artisan atlas:loop:wire {action : schemas|validate|history}
    [--schema=<schema_id>]
    [--payload=<path-to-json>]
    [--source=<primitive>]
    [--limit=<n>]
    [--json]
```

### Subcommands

- `schemas` — list every canonical schema id, its version and required fields.
  Backed by `AtlasLoopInterPrimitiveMessageSchemaRegistry::all()`.
- `validate --schema=<id> [--payload=<path>]` — validate a payload against the
  declared schema. Payload is read from `--payload=<path>` (when provided) or
  stdin otherwise. Exits 0 on `validation_ok`, non-zero with per-field errors
  otherwise.
- `history [--source=<primitive>] [--schema=<id>] [--limit=<n>]` — print the
  most-recent receipts from `AtlasLoopInterPrimitiveMessageReceiptLedger`.

### Provider safety

`atlas:loop:wire` is provider-free: no provider calls, no shell, no git. The
command is fail-closed — an unknown action exits non-zero with a usage message.

## Canonical schema ids

- `loop.cortex.snapshot.v1`
- `cortex.maestro.fact.v1`
- `maestro.loop.outcome.v1`
- `loop.cortex.scope_comprehension.v1`
- `cortex.loop.origination_seed.v1`

Each schema entry carries `id`, `family`, `version` and `required_fields`. The
registry is the source of truth — re-run `atlas:loop:wire schemas --json` to
inspect the live state from your operator session.
