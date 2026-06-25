# AAEL Stepwise Debugger CLI

`atlas:aael:debug` is the **only** operator surface for the AAEL execution stepwise debugger.

## Subcommands

| Verb       | Effect                                                                       |
| ---------- | ---------------------------------------------------------------------------- |
| `pause`    | Arms `AtlasAaelExecutionStepwisePauseGate` and appends a `pause_armed` receipt. |
| `inspect`  | Reads the latest pre/post `InspectorSnapshot` and emits FACTS (step_index, step_kind, files_touched, diff_counts) plus an `inspected` receipt. |
| `step`     | Disarms the gate for one step, re-arms at step+1, appends `step_advanced`.   |
| `continue` | Fully disarms the gate and appends `resumed`.                                |

## Exit codes

| Code | Meaning                                                                       |
| ---- | ------------------------------------------------------------------------------|
| 0    | OK.                                                                           |
| 1    | Invalid input (unknown action, missing `--run`).                              |
| 2    | Run does not exist (no receipts on disk for the supplied `--run`).            |
| 3    | Step/continue invoked while no pause is currently armed.                      |

`--json` emits one canonical JSON envelope to stdout (jq-parsable). Errors go to stderr.
