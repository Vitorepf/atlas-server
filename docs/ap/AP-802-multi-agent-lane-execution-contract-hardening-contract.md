---
id: AP-802-multi-agent-lane-execution-contract-hardening
type: ap_contract
title: AP-802 Multi-Agent Lane Execution Contract Hardening
status: active
implementation_state: implemented
owner: software_company_stewardship
companion_of: AP-797-multi-agent-lane-orchestrator-contract
summary: Hardens the AP-797 multi-agent lane plan into a set of explicit, validated per-lane EXECUTION CONTRACTS so a multi-agent Stewardship cycle can never collapse into one big undifferentiated prompt with shared context and shared write power. Each lane gets its own role, input_context_refs, allowed_actions, forbidden_actions, write_authority, provider_plan, evidence_obligations, output_schema, status and blockers; the plan is validated for canonical lanes/order, a single implementer write lane, repair_agent gating, read-only reviewer/judge, and an output schema per lane; one durable AP-795 session is bound per lane and a missing lane receipt blocks the cycle. It never invokes a provider, opens a branch or merges.
related_paths:
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-795-agent-execution-provider-port-session-store-contract.md
  - docs/ap/AP-797-multi-agent-lane-orchestrator-contract.md
  - docs/ap/AP-800-multi-agent-cycle-certification-visibility-contract.md
  - docs/ap/AP-801-multi-agent-live-cycle-executor-contract.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/LaneExecutionContractService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentLaneOrchestratorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/AgentExecutionSessionStoreService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/LaneExecutionContractServiceTest.php
requires_evidence: true
risk_level: high
glossary: docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
---
# AP-802 Multi-Agent Lane Execution Contract Hardening

## Authority

AP-802 hardens the AP-797 lane plan into per-lane **execution contracts**. AP-797
proves a lane plan exists; AP-802 proves each lane is a *separated* agent with its
own role, context, authority, provider plan, evidence obligations, output schema
and durable receipt — so "multi-agent" can never silently become one big prompt
with one shared context and one shared write power.

AP-802 is a substrate mechanic under AP-793. It is **not** a new OS, scheduler,
provider router, Dev/Forge runtime, Sandcastle clone or a fifth multi-agent
layer. It composes AP-797 (lane plan) and AP-795 (session store); it forks
neither.

Anti-duplication decision:

```text
reuse_or_extend:
  AP-797 MultiAgentLaneOrchestratorService lane plan + write-authority table
  AP-795 AgentExecutionSessionStoreService one durable session per lane
  AP-793 multi-agent lane definitions and real-cycle facts
do_not_create:
  parallel lane orchestrator / provider port / session store
  parallel judge or repair runtime
  a new mother-runtime or scheduler
  a direct provider loop
```

## The Problem

A multi-agent cycle is only real when each lane has its own scope and authority.
If every lane receives the same context and the same write power, the workcell is
a fake: one agent doing everything, relabelled six times. AP-802 makes that
collapse impossible by turning each lane into a validated contract and refusing to
certify a cycle whose lanes are not separated.

## Position In The Substrate

```text
AP-794 executable_slice
  -> AP-797 multi_agent_lane_plan        (lanes + receipts + state machine)
  -> AP-802 lane_execution_contract_set  (this contract: per-lane contracts + validation + per-lane session + certification)
  -> AP-801 live cycle executor          (runs lanes over a real owner-runtime result)
  -> AP-800 cycle certification          (read-only verdict + Product Mode)
```

## Lane Execution Contract Schema

```text
atlas.agent_execution.lane_contract.v1
```

Every lane declares all of:

| Field | Meaning |
|---|---|
| `lane_id` | Deterministic id of the lane within the plan. |
| `role` | One of the six canonical roles. |
| `input_context_refs` | The exact, lane-specific context the lane consumes (never "everything"). |
| `allowed_actions` | Actions the lane may perform. |
| `forbidden_actions` | Actions the lane may never perform. |
| `write_authority` | Canonical authority for the role. |
| `provider_plan` | Whether the lane executes a provider, its provider role, and the invocation expectation (real only via the AP-786 owner runtime). |
| `evidence_obligations` | The receipts/artifacts the lane must produce. |
| `output_schema` | The schema name of the artifact the lane must emit. |
| `status` | Lane lifecycle state. |
| `blockers` | Lane-specific blockers (empty when clean). |

The set is wrapped in `atlas.agent_execution.lane_execution_contract_set.v1` with
`lane_contracts[]`, `lane_session_refs[]`, `write_lanes[]`, `validation`,
`certification`, a `fake_multi_agent_guard`, a deterministic `contract_set_hash`
and a `claim_policy`.

## Canonical Lanes And Write Authority

| Lane | Role | Write Authority | Executes provider |
|---|---|---|---|
| `context_scout` | discover files, tests, risks | `read_only` | no |
| `architect` | spec/SDD/TDD/BDD and slice plan | `spec_only` (never a patch) | no |
| `implementer` | modify allowed files | `worktree_write` | yes (only via AP-786 owner runtime) |
| `reviewer` | inspect diff and evidence | `read_only` | no |
| `repair_agent` | fix a failed gate | `repair_branch_write` | yes (only via AP-786 owner runtime) |
| `judge` | pick best candidate | `read_only_no_merge` | no |

Only the implementer is the default write lane. `repair_agent` writes only to its
own repair branch and only after a failure. Reviewer and judge are always
read-only; the judge can never write or merge; context_scout never writes; the
architect produces spec/plan only.

## Validation Rules

`validateLanePlan()` returns a structured `{valid, blockers, violations}` (it
never throws, so a tampered plan is reported honestly). It blocks on:

| Blocker | Meaning |
|---|---|
| `missing_required_lane:{role}` | One of context_scout/architect/implementer/reviewer/judge is absent. |
| `lane_order_violation` | Lanes are not in canonical order. |
| `no_implementer_write_lane` | No implementer `worktree_write` lane. |
| `multiple_write_lanes` | More than one `worktree_write` lane. |
| `repair_agent_enabled_without_failure` | A repair lane is present with no failure / judge block / repair request. |
| `repair_lane_required_after_failure` | A failure is present but no repair lane was planned. |
| `reviewer_write_authority_forbidden` | The reviewer has any write authority or write/merge action. |
| `judge_write_or_merge_forbidden` | The judge has any write authority or a write/merge action. |
| `context_scout_write_authority_forbidden` | The context_scout has write authority or a write action. |
| `architect_patch_authority_forbidden` | The architect can write source/branches (it may write spec only). |
| `lane_missing_output_schema:{role}` | A lane declares no output schema. |
| `forbidden_action_in_lane` | A lane requests a globally forbidden action (merge/deploy/delete/secrets/force-push/rebase/source mutation). |

## Repair Agent Gating

The `repair_agent` lane is conditional. It is enabled **only** when a failure is
present: `validation_failure_present`, `gate_failure_present`,
`policy_requires_repair`, `judge_blocked` or `repair_requested`. A repair lane on
a clean cycle is rejected; a failure with no repair lane is rejected. This keeps
repair honest: it never appears speculatively and never goes missing after a real
failure.

## One Session Per Lane

AP-802 binds exactly one durable AP-795 session
(`atlas.agent_execution.session_store.v1`) per lane, keyed by the loop session id
and the lane. Governance lanes (context_scout/architect/reviewer/judge) never
claim a provider invocation; the implementer and repair_agent map to a real
provider run only when a real AP-786 owner-runtime result is present, otherwise
they are `planned`/`deferred`. `replay(session_id)` returns every lane of a cycle.

## Lane Receipt Certification

`certifyLaneReceipts()` blocks the cycle (`lane_receipt_missing`) when any lane
contract has no bound session/receipt. A lane with no receipt is an unaudited
agent run and is not allowed to pass.

## Fake Multi-Agent Guard

The receipt carries a `fake_multi_agent_guard` that is `is_real_multi_agent=true`
only when: distinct roles cover all required lanes, every lane has a distinct
output schema, exactly one implementer worktree-write lane exists, and the plan
validation passed. This is the machine-checkable proof that the cycle did not
collapse into one generic prompt.

## Safety Rules

- No provider call, branch, merge, deploy or secret access from AP-802.
- Reviewer, judge and context_scout never write; the architect never patches.
- Exactly one implementer write lane; repair writes only to its repair branch.
- Every lane must have a durable receipt or the cycle is blocked.
- The orchestrator is composed when available; AP-802 falls back to a canonical
  lane plan so lane hardening stays deterministic and never breaks because the
  AP-797 orchestrator was mid-change (antifragile to substrate churn).

## Acceptance

- A clean slice yields six (or five, no-repair) per-lane contracts, each with the
  full contract fields.
- A reviewer handed write authority blocks.
- A judge with a write/merge action blocks.
- `repair_agent` is absent without a failure and present on `judge_blocked`.
- One durable session is recorded per lane; `replay` returns them all.
- A missing lane receipt blocks certification.
- `contract_set_hash` is deterministic for identical input.
- The flag-off legacy single-agent AP-786 path is unchanged (no lane contracts).
