# Loop Pause/Resume Observability Bridge

`AtlasLoopCyclePauseResumeSignalBridge` wires the W1460 pause/resume primitives into the cycle
observability surface. Pauses and resumes become first-class cycle signals an operator can see
through the same telemetry CLI that surfaces every other cycle stage.

## Signal types

| Method                       | Stage                  | Payload keys                                                       |
| ---------------------------- | ---------------------- | ------------------------------------------------------------------ |
| `onPauseRaised`              | `cycle.paused`         | `cycle_id`, `phase_at_pause`, `reason`, `sentinel_sha256`, `paused_at` |
| `onPauseLowered`             | `cycle.pause_lowered`  | `cycle_id`, `lowered_at`                                           |
| `onResumeAttempted`          | `cycle.resumed`        | `cycle_id`, `resumed_phase`, `integrity_ok`, `resumed_at`          |
| `onResumeRefusedDueToDrift`  | `cycle.resume_refused` | `cycle_id`, `reason`, `refused_at`, `paused_phase_recovered`       |

`sentinel_sha256` is the SHA-256 of the JSON sentinel payload that
`AtlasLoopCyclePauseFlag` wrote to disk — operators can rehash the sentinel file and prove a
given signal refers to a specific pause event.

## FACT-only

A Goodhart guard inside the bridge refuses to emit any payload key matching
`/^(score|rank|health|composite|total)$/i`. The bridge surfaces discrete event facts and never
an aggregate "pause health" scalar.

## Master-OFF byte-identical

When `ATLAS_LOOP_MASTER_ENABLED` is false, every `onX` method short-circuits before touching the
signal emitter or the filesystem — verified by a counting-spy test and a sink-snapshot test.
