---
id: AP-798-integration-lane-judge
type: ap_contract
title: AP-798 Integration Lane and Judge Contract
status: active
implementation_state: rules_engine_implemented_with_contract
owner: software_company_stewardship
summary: Canonical composition-and-judgement layer for multi-agent task execution. AP-798 receives the outputs of execution lanes (implementer, reviewer, repair, scout, architect) plus the validation result, diff summary and evidence refs, and produces a deterministic integration judgement that routes the work to accepted_for_merge_governor, rejected, repair_required, operator_review_required or blocked_missing_evidence. It is a pure rules engine. It never merges, never mutates the repo, never invokes a provider and never uses an LLM. On acceptance it only hands off to the AP-769 merge governor; it never performs the merge itself. It reuses AP-793 lane vocabulary, AP-794 slice plans, AP-769 merge governor and AP-782 integration lane as the downstream merge authority.
related_paths:
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-794-finding-slice-planner-contract.md
  - docs/engineering-knowledge-base/atlas-multi-agent-unified-architecture.md
  - docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
  - docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentIntegrationJudgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLaneService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentIntegrationJudgeServiceTest.php
requires_evidence: true
risk_level: high
---
# AP-798 Integration Lane and Judge Contract

## Authority

AP-798 is the composition-and-judgement layer that sits between multi-agent lane
execution and the merge governor. It is not a new OS, scheduler, provider router,
Dev runtime, Forge runtime, merge engine or slice planner.

AP-793 Phase 4 enables `context_scout`, `architect`, `implementer`, `reviewer`,
`repair_agent` and `judge` as separate lanes under one task. AP-793 states the
integration lane is "the only place where outputs are composed, and it is
governed by merge policy", and that the `judge` lane is "read-only, no merge".
AP-798 makes that judge lane executable as a deterministic rules engine.

Anti-duplication decision:

```text
reuse_or_extend:
  AP-793 multi-agent lanes and real-cycle facts
  AP-794 slice plan (allowed_files, forbidden_files, expected_diff_shape,
    validation_commands, evidence_obligations, merge_policy)
  AP-769 StewardshipBranchMergeGovernorService as the downstream merge authority
  AP-782 StewardshipIntegrationLaneService as the safe integration branch
do_not_create:
  parallel merge engine
  parallel provider execution
  parallel slice planner
  parallel repair execution
  any LLM-backed judgement
```

## Problem

Running several agents on one task is not enough. Without a governed integration
lane and judge, lane outputs would either be merged directly (unsafe) or accepted
on the basis of a magic "COMPLETE" string (dishonest). AP-793 already forbids
both. AP-798 turns "did this multi-agent task produce something safe to merge?"
into a deterministic, auditable verdict that a human or the merge governor can
trust.

## What AP-798 Does Not Do

- It does not merge. On acceptance it only emits a merge-governor handoff.
- It does not mutate the repo, create branches, push, rebase or touch `main`.
- It does not invoke a provider or any LLM. The verdict is a pure rules engine.
- It does not plan slices (AP-794), execute providers (AP-786/AP-790), repair
  failed gates (repair service) or surface Product Mode state.
- It has no side effects. It is a pure function from inputs to a judgement.

## Inputs

`MultiAgentIntegrationJudgeService::judge(array $input)` consumes:

| Input | Meaning |
|---|---|
| `lane_plan` | The task/slice plan governing this multi-agent run. |
| `lane_results[]` | One result per execution lane that ran. |
| `validation_result` | Focused validation outcome for the produced diff. |
| `diff_summary` | Observed changed files and diff shape. |
| `evidence_refs` | Evidence produced, checked against the plan obligations. |

### `lane_plan`

Reuses AP-794 slice fields plus lane metadata. Recognised keys:

```text
task_id | intent_id        stable id of the task
slice_id                    AP-794 slice id (optional)
owner                       atlas_dev | forge | memory | stewardship | ...
risk_level                  low | medium | high | critical
allowed_files[]             exact paths or narrow glob patterns
forbidden_files[]           paths never touched by this task
expected_diff_shape         test_only | service_only | adapter_only | docs_and_test | ...
validation_commands[]       focused commands that must pass
evidence_obligations[]      required evidence kinds
merge_policy                auto_merge_eligible | review_required | never_auto_merge
forbidden_actions[]         actions no lane may perform (defaults applied)
expected_lanes[]            lanes the plan expected to run
repair_policy               { allowed: bool, max_attempts: int, attempts_used: int }
branch_ref / base_ref       merge-governor handoff coordinates (no git is run)
area_id                     stewardship area
```

### `lane_results[]`

Each entry describes one lane:

```text
lane | role                 implementer | reviewer | repair_agent | context_scout | architect
agent_id                    lane agent id
status                      completed | failed | blocked
receipt_ref                 lane receipt pointer
performed_actions[]         actions the lane actually performed (honesty channel)
review                      reviewer only:
  decision                  approve | reject | request_changes
  blockers[]                { kind, severity, detail }
  rationale
```

### `validation_result`

Mirrors the merge governor validation block: `{ ran, passed (bool|null),
commands[], results[] }`. `passed === null` means validation did not run, which
cannot certify success.

### `diff_summary`

`{ changed_files[], diff_shape, insertions, deletions }`.

### `evidence_refs`

A list of evidence entries. Each entry may be a string kind or a map
`{ kind, ref, hash }`. The judge checks coverage against
`lane_plan.evidence_obligations`.

## Output Schema

```text
atlas.agent_execution.integration_judgement.v1
```

Envelope:

```text
schema_version, ap_contract, status, judgement_id, task_id, slice_id, owner,
risk_level, area_id, stack, source_ap_contracts[],
decision_reason, decision_detail, blockers[],
scoring{...}, all_gates_passed,
lane_summary{ expected_lanes, observed_lanes, missing_lanes, failed_lanes },
reviewer_findings[],
merge_governor_handoff | null,
repair_handoff | null,
operator_review | null,
next_actions[], claim_policy{...}, judged_at, judgement_hash
```

## Statuses

| Status | Meaning |
|---|---|
| `accepted_for_merge_governor` | All gates pass. The judge hands off to AP-769; it does not merge. |
| `rejected` | A hard, unsafe or non-recoverable condition (scope violation, forbidden action, reviewer security reject, exhausted repair). |
| `repair_required` | Recoverable failure (validation failed, reviewer requested changes) and repair policy still has attempts. |
| `operator_review_required` | A human must decide (security/safety flag, diff-shape divergence, risk policy, missing reviewer approval). |
| `blocked_missing_evidence` | The judge cannot decide safely because required inputs/evidence are missing or there is no diff to judge. |

`accepted_for_merge_governor` is not "merged". It is a handoff. The merge governor
(AP-769) and integration lane (AP-782) remain the only merge authorities.

## Scoring Dimensions

Each dimension produces `{ passed: bool, ... }`:

| Dimension | Pass condition |
|---|---|
| `validation_passed` | `validation_result.passed === true`. |
| `evidence_complete` | Every `evidence_obligations` kind is covered by `evidence_refs`. |
| `scope_respected` | Every changed file matches `allowed_files` and none matches `forbidden_files`. |
| `reviewer_approved` | A reviewer lane returned `approve`. |
| `no_forbidden_actions` | No lane performed any `forbidden_actions`. |
| `expected_diff_shape_matched` | Observed `diff_shape` equals `expected_diff_shape`. |
| `risk_policy_satisfied` | `merge_policy` is consistent with `risk_level` (high/critical may not be auto-merge). |

`all_gates_passed` is true only when every dimension passes.

## Decision Order (first match wins)

The verdict is deterministic and evaluated in this fixed order:

1. Missing `lane_plan` identity → `blocked_missing_evidence` (`lane_plan_required`).
2. No changed files to judge → `blocked_missing_evidence` (`no_changed_files_to_judge`).
3. `scope_respected` false → `rejected` (`scope_violation`).
4. `no_forbidden_actions` false → `rejected` (`forbidden_action_performed`).
5. Reviewer security/safety blocker:
   - reviewer decision `reject` or blocker severity `critical` → `rejected` (`reviewer_security_reject`);
   - otherwise → `operator_review_required` (`security_safety_review_required`).
6. `evidence_complete` false → `blocked_missing_evidence` (`evidence_obligations_missing`).
7. `validation_passed` false:
   - repair policy has attempts → `repair_required` (`validation_failed_repair_allowed`);
   - otherwise → `rejected` (`validation_failed_repair_exhausted`).
8. Reviewer not approved (no security blocker):
   - decision `reject` → `rejected` (`reviewer_rejected`);
   - decision `request_changes` → `repair_required` if repair allowed, else `operator_review_required`;
   - reviewer missing → `operator_review_required` (`reviewer_approval_missing`).
9. `expected_diff_shape_matched` false → `operator_review_required` (`diff_shape_mismatch`).
10. `risk_policy_satisfied` false → `operator_review_required` (`risk_policy_requires_review`).
11. All gates pass → `accepted_for_merge_governor`.

Hard, unsafe and security conditions are evaluated before recoverable ones so a
scope violation or a performed forbidden action can never be downgraded to a
repair.

## Determinism

`judgement_hash` is a sha256 over the canonical judgement identity (everything
except `judged_at` and `judgement_hash`) using `MissionCanonicalHash`. Identical
inputs always produce an identical `judgement_id` and `judgement_hash`. There is
no randomness, clock-dependence (beyond the advisory `judged_at`), provider call
or LLM in the verdict.

## Safety Rules

- The judge never merges, pushes, rebases, force-pushes or mutates `main`.
- The judge never invokes a provider and never uses an LLM.
- `accepted_for_merge_governor` only emits a handoff; AP-769/AP-782 still decide
  and perform any merge under their own policy.
- A scope violation or performed forbidden action is always a `rejected`, never a
  repair.
- A security or safety blocker always requires a human (`operator_review_required`
  or `rejected`), never an auto-accept.
- Missing evidence or a missing diff blocks; it never silently accepts.

## Acceptance

- The judge is a pure rules engine with no side effects, no git, no provider and
  no LLM.
- All five statuses are reachable and deterministic.
- The seven scoring dimensions are reported on every judgement.
- An accepted judgement forwards to the merge governor without merging.
- `judgement_hash` is reproducible for identical inputs.
- AP-769/AP-782 remain the only merge authorities; AP-798 does not fork them.
