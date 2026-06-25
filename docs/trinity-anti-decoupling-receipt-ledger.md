# Trinity Anti-Decoupling Receipt Ledger

`AtlasLoopTrinityContractReceiptLedger` closes the recursive loop of the four anti-decoupling packets:

| Packet | Role |
|---|---|
| 01 emitter | Declares the contract |
| 02 auditor | Gates on it |
| 03 drift detector | Attributes past breaches |
| **04 receipt ledger** | **Makes every gate decision permanently auditable** |

## Receipt shape

```json
{
  "seq": 17,
  "ts": 1700000000,
  "kind": "audit",
  "primitive": "loop",
  "counterpart": "cortex",
  "side": "emit",
  "contract_fingerprint": "...",
  "outcome": "BREACH",
  "source_command_sha": "..."
}
```

`kind` is `audit` (live verdicts from the auditor) or `drift` (historical facts from the drift detector).

## Storage

Daily-rotated NDJSON at `storage/atlas/trinity/anti-decoupling/receipts/YYYY-MM-DD.ndjson`.

## Pétreo invariants

- **APPEND-ONLY**: only `append()` writes. No public `update`/`delete`/`truncate`. Attempting to mutate a receipt file via the API throws `TrinityReceiptImmutabilityException`.
- **REPLAY-DETERMINISTIC**: replaying the file via `replay()` reconstructs the same in-memory state every time (insertion-ordered by seq).

## Future loop closure

The ledger exposes `breachesThisWeekFor($primitive, $atTs)` so a future auditor wrapper can refuse "this primitive already broke and re-broke its contract twice this week" patterns — the structural defense against perpetual decoupling.

## CLI

`atlas:loop:trinity:contract-receipts [--primitive=loop] [--kind=audit] [--json]` — replays the ledger, optionally filtered by primitive or kind.
