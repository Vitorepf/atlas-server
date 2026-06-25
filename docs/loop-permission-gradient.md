# Loop Permission Gradient — Operator Runbook

The Loop authority gradient sits ABOVE the pétreo sandbox floor and is the SINGLE
chokepoint every write/merge path must consult before touching the workspace, the
FrozenJudge, or `main`. It can never weaken the sandbox; it only adds refusal.

## 4-piece pipeline

1. `AtlasLoopPermissionLevelRegistry` (P1) — single source of truth for the per-phase
   maximum authority. Pure data, no I/O.
2. `AtlasLoopPermissionLevelEnforcer` (P2) — fail-closed asserter. Throws
   `AtlasLoopPermissionDeniedException` when a phase attempts an operation whose
   required level exceeds the registry's max for that phase. Master OFF pins the
   allowed level to READ.
3. `AtlasLoopPermissionLevelReceiptLedger` (P3) — append-only JSON-Lines audit at
   `storage/atlas/loop/permission-receipts.jsonl`. Byte-identical no-op when master
   OFF; refuses any path under `app_path()` via the sandbox floor guard.
4. `AtlasLoopPermissionCommand` (P4) — `atlas:loop:permission inspect|enforce|history`.
   Operator observability surface; read-only by default; `enforce` is a dry-run that
   records a receipt tagged `dry_run=true`.

## Level lattice

`READ < PROPOSE < WRITE < MERGE` (closed enum `AtlasLoopPermissionLevel`).

## Operator commands

| Command | Effect |
| --- | --- |
| `atlas:loop:permission inspect` | Prints the 9-phase gradient table, sorted by phase. |
| `atlas:loop:permission enforce --phase=<p> --level=<l>` | Dry-run assertion; exit 0 on allow, exit 2 on deny. Appends a `dry_run=true` receipt. |
| `atlas:loop:permission history --phase=<p> --decision=allow\|deny --limit=N` | Tail-reads the JSONL ledger with filters. Never mutates. |

## Phases and required levels

See `AtlasLoopPermissionLevelRegistry::PHASE_LEVELS`. Observe and comprehend cap at
READ; implement caps at WRITE; merge caps at MERGE; certify/learn/originate/project/
decompose cap at PROPOSE.

## Anti-Goodhart guarantee

Without the receipt ledger (P3) the Enforcer would be invisible and the gradient
unfalsifiable. The ledger is the auditable evidence trail the operator and Atlas
Cortex compounding read to PROVE the gradient is actually consulted.
