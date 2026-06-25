# Loop Conflict Resolution CLI

`atlas:loop:conflict` is the operator surface for the cross-cycle conflict resolution loop.

## Subcommands

| Verb      | Effect                                                                            |
| --------- | --------------------------------------------------------------------------------- |
| `detect`  | Runs `AtlasLoopCycleConflictDetector` over the two cycles' write-sets and prints the FACT-only per-file overlap-mode `ConflictReport`. |
| `resolve` | Runs `AtlasLoopCycleConflictResolver` and prints `clause` (R-DIV / R-IDENT / R-STITCH) + per-file receipts. |
| `history` | Streams prior `R-DIV`/`R-IDENT`/`R-STITCH` decisions from the receipt store; optional `--clause=` filter. |

## Exit codes

| Code | Meaning                                                                            |
| ---- | ---------------------------------------------------------------------------------- |
| 0    | OK — including identical-bytes (R-IDENT auto-merge), stitch-pending (R-STITCH), or master-switch no-op. |
| 1    | Invalid input (unknown action, missing `--cycle-a` / `--cycle-b`, unresolvable cycle source). |
| 2    | Resolve refused (divergent-bytes ⇒ R-DIV).                                        |

## Master switch contract

When `ATLAS_LOOP_MASTER_ENABLED=false`, every subcommand short-circuits to a byte-identical
`{status: no_op, reason: master_switch_off}` envelope. The detector and resolver are NEVER
invoked, the receipt store is NEVER touched.

## FACT-only

The CLI emits no `score`, no `winner`, no `verdict`. Outputs carry only per-file overlap modes
(`identical-bytes`, `disjoint-hunks`, `divergent-bytes`) and the policy clause + receipt list.
