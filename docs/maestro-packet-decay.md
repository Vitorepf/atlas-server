# Maestro Packet Decay CLI

`atlas:task:maestro:decay` (registered via Laravel auto-discovery from `app/Console/Commands/`)
is the operator's observability surface for the packet-decay pipeline. Note the colon-only
form: a space (`atlas:task maestro:decay`) collides with the existing `atlas:task` worker
entrypoint (see memory: maestro-cost-cmd auto-cure precedent).

## Three-mode contract

| Mode | Effect |
| --- | --- |
| `inspect` | Prints the deterministic FACT list from `AtlasMaestroPacketAgeFactReporter` as `{task_packet_id, age_seconds, queue_status}`. Read-only. |
| `propose` | Invokes `AtlasMaestroPacketDecayPolicy::propose()` and prints the proposed park list with reasons. **Never** mutates the queue. Each invocation appends one audit line to `storage/atlas/maestro/packet_decay_history.jsonl`. |
| `history` | Tails the append-only audit log. |

All three modes honor `--json` for machine output and exit 0.

## Master switch

When `config('atlas.loop.master_enabled') === false`, every mode prints a one-line notice and
exits 0 with an empty payload. No history line is written.

## FACT / Policy / CLI seam

- **FACT** layer: `AtlasMaestroPacketAgeFactReporter` exposes per-packet age + status.
- **Policy** layer: `AtlasMaestroPacketDecayPolicy` consumes facts, applies a threshold,
  proposes parks. Pure.
- **CLI** layer (this command): wires facts and policy to the operator. **No** queue writes,
  **no** scores, **no** verdicts.

## Anti-Goodhart

- The CLI is **advisory only**: it cannot trigger queue mutations. Parking requires a
  separate explicit operator command.
- The history log is **append-only**. Past entries are never edited or deleted, so the
  operator can audit the policy's recommendations across time.
- The FACT output and Policy output are explicit, enumerable rows — never a scalar
  "decay score" that could be optimized for.
