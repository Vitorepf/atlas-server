# Loop Model-Check CLI

`atlas:loop:model-check` wires the FSM extractor and deadlock checker into a single
operator surface so cycle drift is observable.

## Subcommands

| Command | Effect |
| --- | --- |
| `atlas:loop:model-check extract [--json]` | Builds the FSM artifact via `AtlasLoopCycleStateMachineExtractor` and persists it to `storage/atlas/model-check/fsm.json`. |
| `atlas:loop:model-check deadlock [--json]` | Runs `AtlasLoopCycleDeadlockChecker` over the most recent FSM and persists `verdict.json`. |
| `atlas:loop:model-check history [--limit=N] [--json]` | Tails the append-only `storage/atlas/model-check/history.ndjson` audit log. |

## Master switch

All write subcommands are guarded by `config('atlas.loop.master_enabled')`. When OFF,
write subcommands exit with `EXIT_REFUSED` and produce zero filesystem side-effects.
History reads are always allowed (read-only).

## Sample history line

```
{"action":"extract","artifact_path":"storage/atlas/model-check/fsm.json","sha256":"abc...","ts":"2026-06-25T13:14:00Z"}
```

Each line is a canonical JSON object with stable key order so two PHP processes produce
byte-identical bytes for the same input.

## Anti-Goodhart

The CLI emits FACTS only — no scores, no qualitative summary, no recommendation prose.
The receipt is the artifact path + sha256 + timestamp; downstream Atlas Cortex consumers
can hash-chain or diff over time without conflating into a scalar.
