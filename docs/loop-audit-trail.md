# Atlas Loop Audit Trail CLI

`atlas:loop:audit` is the operator-facing surface for the loop audit trail. It composes events
from registered sub-ledgers, replays causal chains, exports them to JSONL, and verifies receipt
chain integrity.

## Subcommands

### `timeline --since=ISO --until=ISO`

Prints the chronological list of audit events composed for the window
`[since, until)` (UTC, ISO-8601).

### `replay --from=ISO --to=ISO [--filter=JSON]`

Runs `AtlasLoopAuditTrailReplayer` over the window and prints the deterministic
`ReplayReport` (per-source counts, causal chains, idle gaps, chronological events).

### `export --since=ISO --until=ISO --out=PATH.jsonl`

Streams composed events through `AtlasLoopAuditTrailExporter` to write a JSONL
file at `PATH`, plus a sidecar manifest. The body file round-trips through the
integrity verifier byte-for-byte.

### `verify --since=ISO --until=ISO`

Runs `AtlasLoopAuditTrailIntegrityVerifier` over the composed events. Exits 0
when the chain is intact; non-zero when anomalies are detected (BROKEN_LINK,
SEQUENCE_GAP, ORPHAN_REF).

## Architecture

The CLI resolves the four primitives from the container — it never instantiates
them inline. The bindings live in `AppServiceProvider::register()` as singletons:

- `AtlasLoopAuditTrailComposer`
- `AtlasLoopAuditTrailReplayer`
- `AtlasLoopAuditTrailExporter`
- `AtlasLoopAuditTrailIntegrityVerifier`

`Replayer` is constructed with a closure pointing at the singleton composer's
`compose()` iterator, so a single sub-ledger registration is shared across all
four primitives.

## Fixture source (tests only)

For feature tests, the CLI accepts `--source-jsonl=PATH` to register a JSONL
fixture as a source on the composer. Production callers register real sub-ledger
sources via their own service providers.
