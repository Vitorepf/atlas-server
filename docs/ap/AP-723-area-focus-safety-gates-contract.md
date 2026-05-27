---
id: AP-723-area-focus-safety-gates-contract
type: architecture_proposal
title: AP-723 Area Focus Safety Gate Evaluator Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Adds a read-only Area Focus Safety Gate Evaluator for the Atlas Software Company Stewardship Stack. It evaluates the canonical safety gates for every Area Focus Loop run — owner docs, budget, WIP, risk policy, kill switch, no secrets/merge/deploy/destructive change, evidence completeness, operator inbox and Atlas-internal-first — and decides allow/warn/block. It executes nothing; it only decides. max_governed is bounded strictly by these gates.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-716-area-focus-loop-core-read-model-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusGateEvaluatorService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusGateEvaluatorServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-723 Area Focus Safety Gate Evaluator Contract

> Atlas Software Company Stewardship Stack is a stack/capability family inside
> the Atlas Autonomous Software Company Runtime, not a new OS.

## Decision

Add a read-only **Area Focus Safety Gate Evaluator** that enforces the canonical
safety boundary for every Area Focus Loop run. It is a pure decision service: it
takes a run context assembled from the orchestrator / work orders / evidence and
returns `allow` / `warn` / `block`. It performs no IO and executes no work.

This is not a new OS, not a parallel runtime and not an executor. It reuses the
existing Area Focus Loop slices (AP-712, AP-716..AP-722) as inputs and decides
whether a proposed run is permitted by the gates.

## Schema

```text
atlas.software_company_stewardship.area_focus_gate_report.v1
```

## Gates (minimum)

| Gate | Blocks when |
|---|---|
| `area_owner_docs_present` | a declared owner doc is missing / presence unconfirmed |
| `budget_within_limit` | dev/forge budget used exceeds the limit (near-limit warns) |
| `wip_within_limit` | WIP used exceeds the limit (at-capacity warns) |
| `risk_policy_satisfied` | unresolved high-risk finding (sensitive-domain touch warns) |
| `kill_switch_open` | kill switch engaged (state unreported warns) |
| `no_secrets_requested` | the run requests secret access |
| `no_merge_requested` | the run requests a merge |
| `no_deploy_requested` | the run requests a deploy |
| `no_destructive_change_requested` | the run requests a destructive change |
| `evidence_pack_present` | evidence pack absent (present-but-incomplete warns) |
| `operator_inbox_present` | no operator inbox destination |
| `atlas_internal_first` | a non-Atlas (external) repo is targeted |

## Decision Model

- `block` if any gate blocks.
- `warn` if no gate blocks but at least one warns (warnings are non-blocking).
- `allow` if all gates pass clean.

The report exposes `gates[]` (per-gate status/reason/evidence/next action),
`gate_summary`, `blocked_when`, `warnings`, `required_next_actions`,
`max_governed`, `claim_policy` and a deterministic `report_hash` (excludes
`generated_at`).

## Fail-Safe Posture

- Gates that confirm a required condition (owner docs, evidence, operator inbox)
  default to **block** when the condition cannot be affirmatively confirmed.
- Gates that forbid a dangerous request (secrets/merge/deploy/destructive) pass
  only because absence of a request means none was made; any explicit request
  blocks.

## max_governed

`max_governed` is the maximum useful throughput permitted **by these gates**,
never unrestricted autonomy. The report carries
`max_governed.autonomy_unbounded=false` and lists the gates that bound it.

## Integration (optional, additive)

The evaluator is consumable by the Area Focus operational orchestrator (AP-722):
the orchestrator may call `evaluate()` with its assembled run context and refuse
to proceed on a `block`. This AP does NOT invasively edit the orchestrator (a
separate, concurrently-developed file); integration stays optional and additive
so the evaluator degrades cleanly when called standalone.

## Acceptance

- A read-only evaluator service emits the schema.
- Each gate blocks correctly on its violating input.
- Warnings are non-blocking (decision stays `allow`/`warn`, never `block`).
- `max_governed` is bounded by gates (`autonomy_unbounded=false`).
- `report_hash` is deterministic for equal input.
- `claim_policy` proves read-only / decides-only: no writes, no provider, no
  branch, no merge, no deploy, no secrets, no destructive change, no new OS, no
  parallel runtime.
- Unit tests cover every gate, the warn-vs-block distinction, bounded
  max_governed and the deterministic hash.
- docs-health and architecture validation stay green.
