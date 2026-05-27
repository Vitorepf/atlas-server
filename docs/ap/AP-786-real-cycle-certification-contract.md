---
title: AP-786 Real Cycle Certification & Three-Cycle Audit Contract
status: active
implementation_state: implemented
requires_evidence: true
owner: software_company_stewardship
companion_of: AP-786-autonomous-evolution-session-contract
glossary: docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
---

# AP-786 Real Cycle Certification & Three-Cycle Audit Contract

## Authority

This is the **proof / audit / replay** companion of AP-786. It does not run the
loop, the owner-flow, providers, git, merge or scheduler. It is a read-only,
deterministic judge: given an AP-786 autonomous evolution session report (inline
or replayed from the append-only JSONL), it decides whether each cycle is a
**real full-owner-flow cycle** or a **fake / incomplete** one, and whether at
least N (default 3) cycles are genuinely real.

It exists because the prior failure mode was a false claim: small/repeated
commits produced by a direct provider driver were presented as full Atlas
Forge / Atlas Dev cycles. See the canonical glossary for the Atlas Dev / Forge
naming (`docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md`).

- Service: `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Ap786RealCycleCertificationService.php`
- CLI: `php artisan atlas:software-company-stewardship:ap786-cycle {certify|replay}`
- Schema: `atlas.software_company_stewardship.ap786_real_cycle_certification.v1`

## Per-Cycle Requirements

A cycle is `certified_real_cycle` only when ALL hold:

| Requirement | Evidence read |
|---|---|
| `cycle_id` | `cycle.cycle_id` |
| `selected_finding` | `cycle.selected_finding.{finding_id,title}` |
| AP-756 sandbox materialized | `owner_flow.AP-756` or sandbox_id + branch_created + worktree_created |
| AP-747 release | `owner_flow.AP-747` or `cycle.release{,_id}` |
| AP-757 sandbox binding | `owner_flow.AP-757` or `cycle.sandbox_binding{,_id}` |
| AP-749 consumption ready/recorded | `owner_flow.AP-749` or `cycle.consumption{,_id}` |
| AP-758 execution ready/recorded | `owner_flow.AP-758` or `cycle.execution{,_id}` |
| AP-759 owner sandbox run ready/recorded | `owner_flow.AP-759` or `cycle.owner_run{,_id}` |
| AP-750 result bridge ready/recorded | `owner_flow.AP-750` or `cycle.result_bridge_id` |
| evidence / inbox emitted | `cycle.inbox_item_id` / `cycle.result_bridge_id` |
| validation commands present and passed | `cycle.validation.{commands,passed}` |
| merge governance present (AP-769/AP-774) | `cycle.merge_governance.status` |
| branch / worktree isolation | `cycle.branch_ref` + `cycle.worktree_path` |
| replayable from JSONL | session is recorded / loadable by `session_id` |

A cycle is **rejected** (`blocked_fake_or_incomplete`) on any of these fake
signals, regardless of the stages above:

- `session_direct_provider_driver_allowed` / `cycle_direct_provider_driver_allowed`
- `provider_router_direct_path` (`flow_integrity_gate.direct_provider_driver_path = true`)
- `not_full_owner_runtime_chain` (`flow_integrity_gate.uses_full_owner_runtime_chain != true`)
- `cycle_not_executed_to_completion` (dry-run / blocked cycle)
- `duplicated_finding_from_previous_cycle` / `duplicated_commit_title_from_previous_cycle`

A real, non-fake cycle whose merge was governed but legitimately held for human
review downgrades to `partial`, never to certified, and never to fake.

## Owner-Flow Evidence Contract (what the integrator must emit)

For a cycle to certify, the AP-786 owner-flow integrator should emit, per cycle,
an `owner_flow` map keyed by AP with `{status, id}`, where `status` is one of
`ready|recorded|materialized|merged|passed|completed|done`:

```json
"owner_flow": {
  "AP-756": {"status": "materialized", "id": "afsb_..."},
  "AP-747": {"status": "recorded", "id": "rel_..."},
  "AP-757": {"status": "recorded", "id": "bind_..."},
  "AP-749": {"status": "recorded", "id": "cons_..."},
  "AP-758": {"status": "recorded", "id": "exec_..."},
  "AP-759": {"status": "recorded", "id": "run_..."},
  "AP-750": {"status": "recorded", "id": "rb_..."}
},
"flow_integrity_gate": {"uses_full_owner_runtime_chain": true, "direct_provider_driver_path": false}
```

Until the integrator emits `owner_flow`, the certifier falls back to known cycle
fields; missing stages are reported as `AP-7xx_stage_missing` so the operator
sees exactly which stage failed.

## Statuses

| Status | Meaning |
|---|---|
| `certified_real_cycle` | Session has at least `min_real_cycles` (default 3) fully-proven real cycles. |
| `partial` | At least one real cycle, but fewer than required, or real cycles held for review. |
| `blocked_fake_or_incomplete` | No real cycle, a direct-provider/diagnostic session, an unreplayable session, or a missing required stage. |

`next_safe_command` is emitted only when safe: `null` for a direct-provider
session (fix the flow first), a dry-run command when not yet proven, and the
governed `--execute --record` command once three real cycles are certified.

## Acceptance

- Certifies a fully shaped three-real-cycle session.
- Blocks a direct-provider diagnostic session/cycle.
- Blocks a cycle missing AP-759 (or any required owner-flow stage), naming it.
- Blocks a repeated finding / commit title across cycles.
- Certifies a three-cycle session only when all three pass.
- Replays a recorded session from JSONL and re-certifies it deterministically.
- Read-only: never runs provider, git, merge or scheduler; never fabricates receipts.
