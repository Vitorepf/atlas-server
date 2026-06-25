# Atlas Loop Cycle — Model-Check State Machine

The Atlas Loop cycle is modeled as a finite state machine extracted by
`AtlasLoopCycleStateMachineExtractor::extract()`. The artifact is deterministic
and byte-identical for an unchanged tree.

## States

`ENTRY → orient → comprehend → decide_leverage → architect → decompose →
implement → certify → close_on_main → learn → TERMINAL`, plus `ERROR`.

## Entry Gate

The cycle is fail-closed on `AtlasLoopMasterSwitch::KEY`. When the env value is
not `true`, the machine transitions `ENTRY → TERMINAL` immediately and no
cycle phase runs.

## Guards Anchor on Real Symbols

Every transition references a concrete Atlas symbol so the FSM is verifiable
against the live codebase:

- `App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch::KEY`
- `App\Services\Ai\AutonomousEvolution\AtlasLoopCycleGitContract::startCycle`
- `App\Services\Ai\AutonomousEvolution\AtlasLoopCycleGitContract::commitCycle`
- `App\Services\Ai\AutonomousEvolution\AtlasLoopCycleGitContract::mergeToMain`
- `App\Services\Ai\AutonomousEvolution\AtlasLoopCycleGitContract::discardCycle`

## Sample FSM JSON

```json
{
  "schema": "atlas.loop.cycle_state_machine.v1",
  "entry_state": "ENTRY",
  "terminal_states": ["TERMINAL", "ERROR"],
  "master_switch_gate": "App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopMasterSwitch::KEY",
  "states": [
    "ENTRY", "ERROR", "TERMINAL", "architect", "certify", "close_on_main",
    "comprehend", "decide_leverage", "decompose", "implement", "learn", "orient"
  ],
  "transitions": [
    {"from": "ENTRY", "to": "TERMINAL", "guard": "AtlasLoopMasterSwitch::KEY==false"},
    {"from": "ENTRY", "to": "orient", "guard": "AtlasLoopMasterSwitch::KEY==true"},
    {"from": "...", "to": "...", "guard": "..."}
  ]
}
```

## Read-Only Contract

`extract()` performs no writes outside this artifact path, performs no git
mutation, and never calls a cycle service. It is safe to run with the master
switch OFF.
