---
id: atlas-engineering-enforce-flip-runbook
type: engineering_knowledge
title: Atlas Engineering Enforce Flip Runbook
status: active
category: engineering-governance
priority: 100
summary: Runbook for ENG-13/14/15 enforcement flips, rollback triggers, readiness gate, and hold policy.
---

# Atlas Engineering Enforce Flip Runbook

Scope: ENG-13 governance enforce, ENG-14 Forge gate enforce, and ENG-15 ADML cost outcome.

## HOLD

Do not flip any of these enforcement paths without both:

1. explicit operator OK for the specific flip, and
2. `php artisan atlas:engineering:enforce-readiness --json` returning `ready:true` for that flip.

Current state is HOLD. Governance and Forge are not ready. ADML is ready in the latest snapshot,
but it still remains HOLD until the operator explicitly approves the ENV flip.

Do not change config defaults to ON as part of readiness work. Defaults move only in a separate
config commit after an ENV-first soak proves the flip.

## Sequence

1. Re-run readiness:
   `php artisan atlas:engineering:enforce-readiness --json`
2. Confirm the target flip has `ready:true`.
3. Get explicit operator approval for the specific flip.
4. Flip by environment override only.
5. Soak under watchdog/ROL-01 monitoring.
6. If a rollback trigger fires, revert the environment override immediately.
7. Only after soak is clean, prepare a separate config-default commit for review.

## Flip and rollback matrix

| Slice | Flip env override | ROL-01 rollback trigger | Rollback env override |
| --- | --- | --- | --- |
| ENG-13 governance enforce | `ATLAS_AI_GOVERNANCE_ENFORCE=true` | Any real completion blocked in the 24h rollback window after the governance enforce flip. | `ATLAS_AI_GOVERNANCE_ENFORCE=false`, `ATLAS_AI_CALL_COST_GUARD_HARD_UNITS=0` |
| ENG-14 Forge gate enforce | `ATLAS_FORGE_EXECUTION_GATE_ENFORCE=true` | Any Forge-scoped real completion blocked in the 24h rollback window after the Forge gate enforce flip. | `ATLAS_FORGE_EXECUTION_GATE_ENFORCE=false` |
| ENG-15 ADML cost outcome | `ATLAS_PATAMAR4_ADML_COST_OUTCOME_ENABLED=true` | Proven route drops below minimum evidence in the 7d rollback window after the ADML flip. | `ATLAS_PATAMAR4_ADML_COST_OUTCOME_ENABLED=false` |

## Current readiness snapshot

Fresh command: `php artisan atlas:engineering:enforce-readiness --json`

Command exit is non-zero because `ready_to_enforce=false`; this is expected while any flip is blocked.

```json
{
  "schema_version": "atlas.engineering.enforce_readiness.v1",
  "status": "not_ready",
  "ready_to_enforce": false,
  "blocking": [
    "governance_soak_volume_below_floor",
    "forge_promoted_cycle_volume_below_floor"
  ],
  "flips": {
    "governance_enforce": {
      "ready": false,
      "blocking": [
        "governance_soak_volume_below_floor"
      ],
      "raw": {
        "window_days": 7,
        "by_executor": {
          "dev": 0,
          "forge": 0,
          "autonomos": 0
        },
        "bypass_rate": 0,
        "false_positive_total": 0,
        "fp_definition": "would_have_blocked=true on a consult that completed green with post_cost_units <= pre_cost_units (within expected pre-cost estimate)"
      }
    },
    "forge_gate_enforce": {
      "ready": false,
      "blocking": [
        "forge_promoted_cycle_volume_below_floor"
      ],
      "raw": {
        "promoted_harness_captured_cycles": 0
      }
    },
    "adml_cost_outcome": {
      "ready": true,
      "blocking": [],
      "raw": {
        "ready_routes": 3,
        "routes": {
          "programming|runtime_verifier": 25,
          "programming|boundary_wiring_guard": 14,
          "autonomos|task_serving": 3
        }
      }
    }
  },
  "thresholds": {
    "window_days": 7,
    "real_executions_per_executor": 1,
    "forge_promoted_cycles": 20,
    "adml_proven_routes": 3
  },
  "generated_at": "2026-07-11T19:34:40+00:00"
}
```

## Operator checklist

- [ ] Re-run readiness and confirm the exact flip is `ready:true`.
- [ ] Confirm operator approval in the session/task that will apply the ENV override.
- [ ] Apply ENV override only; do not edit config defaults.
- [ ] Run the relevant smoke/scorecard/readiness commands during soak.
- [ ] If ROL-01 fires, apply the rollback env override above.
- [ ] After soak, open a separate config-default change for review.
