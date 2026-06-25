# Atlas Loop Cycle — Deadlock / Livelock Model Check

`AtlasLoopCycleDeadlockChecker` answers the FACT question: does the cycle DAG
admit deadlock or livelock under any combination of guard valuations?

## Invariants

- Pure: no IO outside the explicit `verdictPath` argument of
  `checkAndWrite()`.
- FACT-only: verdict carries `is_fact: true` and NO score / quality / rating.
- Deterministic: identical FSM ⇒ identical verdict bytes.

## Sample verdict shape

```json
{
  "schema": "atlas.loop.cycle_deadlock_check.v1",
  "is_fact": true,
  "entry_state": "ENTRY",
  "terminal_states": ["TERMINAL", "ERROR"],
  "visited_states": ["ENTRY", "ERROR", "TERMINAL", "architect", "certify", "close_on_main", "comprehend", "decide_leverage", "decompose", "implement", "learn", "orient"],
  "deadlocks": [],
  "livelocks": [],
  "verdict_hash": "verdict_<sha256:24>"
}
```

A non-terminal reachable state with no outgoing transitions appears in
`deadlocks`. A reachable SCC of size ≥ 2 with no exit edge appears in
`livelocks`.
