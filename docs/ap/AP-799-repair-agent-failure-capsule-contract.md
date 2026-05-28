---
id: AP-799-repair-agent-failure-capsule
type: ap_contract
title: AP-799 Repair Agent and Failure Capsule Contract
status: active
implementation_state: implemented
owner: software_company_stewardship
companion_of: AP-793-atlas-isolated-agent-execution-substrate-contract
summary: Canonical contract for turning a failed multi-agent task execution into a governed repair lane. AP-799 owns the planner that reads validation/gate failures plus the implementer lane result, builds a provider-safe failure capsule, classifies the failure (retryable validation, scope violation, missing dependency, provider timeout, rate limit, security blocker) and decides whether a bounded repair attempt is allowed at all. It produces the repair lane input AP-797 repair_agent consumes; it never runs a provider, never merges and never permanently quarantines a transient failure. It is reuse/extend over AP-793 lanes/branch strategy, AP-794 executable slices and the existing AtlasDev repair primitives.
related_paths:
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-794-finding-slice-planner-contract.md
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentRepairPlannerService.php
  - app/Services/Ai/Programming/AtlasDev/Schemas/FailureCapsule.php
  - app/Services/Ai/Programming/AtlasDev/Repair/FailureCapsuleBuilder.php
  - app/Services/Ai/Programming/AtlasDev/Repair/RepairAttemptLimits.php
requires_evidence: true
risk_level: high
glossary: docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
---
# AP-799 Repair Agent and Failure Capsule Contract

## Authority

AP-799 is the contract between a *failed* multi-agent task execution and a
*governed repair attempt*. It is the `repair_agent` lane of AP-793 made
executable as a planning step.

It is **not** a new provider port, slice planner, judge, owner runtime, merge
governor or Product Mode surface. It does not run a provider, does not modify
code, does not merge and does not write to the provider/session store.

Anti-duplication decision:

```text
reuse_or_extend:
  AP-793 multi-agent lanes + branch strategy (repair_branch)
  AP-794 executable_slice (allowed/forbidden files, validation, retry policy, risk)
  AP-786 repair_loop_with_failed_gate_capsule capability
  AtlasDev FailureCapsule::signatureOf (deterministic failure signature)
  AtlasDev RepairAttemptLimits semantics (R4/R5 never auto-repair)
do_not_create:
  parallel provider port (AP-793 / future AP-796)
  parallel repair_agent runtime (AP-797)
  parallel slice planner (AP-794)
  parallel judge (future)
  parallel Product Mode surface (AP-798 / existing cockpit)
  direct provider repair loop
```

## The Problem AP-799 Solves

When validation or a universal gate fails inside a multi-agent task, the
current loop has only two honest options and both are bad on their own:

- blindly re-run the same provider with the same prompt (burns budget, repeats
  the same failure, can grow the diff out of scope); or
- give up and quarantine the candidate (loses the learning, and a transient
  provider timeout or rate limit becomes a permanent dead end).

AP-799 turns a failure into a *decision*: capture exactly what failed, classify
why, and only then decide whether a bounded repair is allowed, who is allowed to
attempt it, on which files, on which branch and with how much budget left.

## Position In The Substrate

```text
implementer lane (AP-793) runs slice (AP-794)
  -> validation + universal gates
  -> FAIL
  -> AP-799 MultiAgentRepairPlanner
       -> failure_capsule
       -> classification
       -> repair permission decision
       -> repair_lane_input  (only when repair is allowed)
  -> AP-797 repair_agent lane (repair_branch / worktree only)
  -> re-validation -> judge -> integration lane -> merge governor
```

AP-799 sits strictly between the failed validation and the repair_agent lane.
It is a pure planner/judge: deterministic, provider-safe and side-effect free.

## Inputs

`MultiAgentRepairPlannerService::plan(array $input)` consumes:

| Input | Meaning |
|---|---|
| `validation_result` | Focused validation outcome: `passed`, `exit_code`, `commands`, `results`, `failing_tests`, `stderr_excerpt`. |
| `gate_failures` | Universal gate / programming governance failures (`gate`, `reason`, optional `kind`). |
| `lane_result` | The implementer lane result: provider/model, `timed_out`, `error_codes`, `rate_limited`, `blockers`, `status`. |
| `executable_slice` | The AP-794 `atlas.stewardship.executable_slice.v1`: `allowed_files`, `forbidden_files`, `validation_commands`, `retry_policy`, `risk_level`, `owner`, `max_runtime_seconds`, `evidence_obligations`, `provider_fit`, `objective`. |
| `diff_summary` | `changed_files` and `diff_hash` produced by the failed attempt. |
| `retry_attempts_used` | How many repair attempts already happened for this failure (optional). |
| `prior_capsules` | Prior failure capsules (`failure_signature`, `attempts`) for dedup (optional). |

## Output Schema

```text
atlas.agent_execution.repair_plan.v1
```

Required fields:

| Field | Meaning |
|---|---|
| `classification` | One of the six canonical failure classes (below) or `null` when no repair is needed. |
| `repair_decision` | `repair`, `transient_retry`, `non_retryable`, `operator_review`, `blocked_retry_exhausted` or `no_repair_needed`. |
| `repair_allowed` | Boolean — the single safety gate. |
| `failure_capsule` | The provider-safe `atlas.agent_execution.failure_capsule.v1` (below). |
| `repair_branch_strategy` | `repair_branch`, `reuse_candidate_branch` or `none_operator_review`. |
| `retry_budget` | `{max, used, remaining, transient}` and `permanent_quarantine`. |
| `repair_lane_input` | `atlas.agent_execution.repair_lane_input.v1` for AP-797, or `null`. |
| `blockers` | Machine-readable reasons repair is not allowed. |
| `next_action` | Operator-facing next step. |
| `repair_plan_hash` | Deterministic `sha256:` hash of the plan identity (excludes the hash and `generated_at`). |
| `claim_policy` | Provider-safety flags (no provider, no merge, no quarantine of transient). |

### Failure Capsule

```text
atlas.agent_execution.failure_capsule.v1
```

| Field | Meaning |
|---|---|
| `failed_command` | The validation/gate command that failed. |
| `exit_code` | Process exit code when known. |
| `stderr_excerpt` | Normalized, ≤4kb error excerpt (provider-safe). |
| `failing_tests` | List of failing test identifiers. |
| `changed_files` | Files the failed attempt touched. |
| `suspected_root_cause` | Deterministic heuristic root cause derived from the classification. |
| `allowed_repair_files` | Files the repair_agent may touch (slice `allowed_files` ∩ relevant, never forbidden). |
| `forbidden_files` | Files the repair_agent must never touch. |
| `retry_budget` | `{max, used, remaining, transient}`. |
| `repair_branch_strategy` | Branch strategy for the repair attempt. |
| `failure_signature` | Deterministic `sha256:` signature reused from `FailureCapsule::signatureOf`. |
| `classification` | The failure class. |
| `capsule_hash` | Deterministic identity hash of the capsule. |

The capsule never carries raw secrets, provider prompts, tokens or internal
trace ids.

## Failure Classification

Exactly one of six canonical classes (evaluated in this precedence order so a
hard safety signal always wins):

| Class | Retryable | Meaning |
|---|---|---|
| `security_blocker_non_retryable` | no | A security gate, secret access or destructive operation was flagged. Operator review only. |
| `scope_violation_non_retryable` | no | The attempt touched a forbidden file or a path outside `allowed_files`. Needs operator/architect re-scope. |
| `missing_dependency_operator_required` | no | A missing dependency/package/binary the agent cannot install honestly. Operator required. |
| `provider_timeout_transient` | transient | The provider timed out. Never a permanent quarantine. |
| `rate_limit_transient` | transient | The provider hit a rate limit / 429. Never a permanent quarantine. |
| `retryable_validation_failure` | yes | A focused validation/test/gate failed within scope and is safe to repair. |

If validation passed and there are no gate failures, classification is `null`
and `repair_decision = no_repair_needed`.

## Repair Permission Rules

A repair is allowed (`repair_allowed = true`) only when **all** are true:

1. `changed_files` are within scope — none in `forbidden_files`, all within
   `allowed_files` (scope violation is its own non-retryable class);
2. there is retry budget remaining;
3. the failure is not security or destructive;
4. at least one validation command exists (otherwise the fix cannot be proven —
   blocked as `validation_command_missing`).

Risk gating mirrors AtlasDev `RepairAttemptLimits`: `R4`/`R5` (or `critical`)
slices never receive an autonomous repair attempt; they escalate to operator /
Forge with budget `0`.

Transient classes (`provider_timeout_transient`, `rate_limit_transient`) are
handled separately: they may re-dispatch the same slice on the existing branch
while a transient budget remains, and they **never** set
`permanent_quarantine = true`. When the transient budget is exhausted the
decision is `blocked_retry_exhausted` but the candidate remains eligible for a
later run once the provider recovers.

## Idempotence And No-Repeat Rule

The planner is deterministic: the same input yields the same `repair_plan_hash`
and the same `failure_capsule.failure_signature`.

A repair must not repeat once the identical failure has already exhausted its
budget. If the incoming `failure_signature` matches a `prior_capsules` entry
whose `attempts >= max`, the decision is `blocked_retry_exhausted` and
`repair_allowed = false`, regardless of nominal remaining budget. This is the
false-progress guard: the loop never loops on the same dead end.

## Repair Lane Input (for AP-797)

When repair is allowed, AP-799 emits:

```text
atlas.agent_execution.repair_lane_input.v1
```

| Field | Meaning |
|---|---|
| `lane` | Always `repair_agent`. |
| `write_authority` | `repair_branch_worktree_only` — never the source worktree. |
| `objective` | One-sentence bounded repair objective. |
| `failure_capsule` | The capsule above. |
| `allowed_repair_files` | Files the repair_agent may touch. |
| `forbidden_files` | Files it must never touch. |
| `validation_commands` | The commands the repair must pass. |
| `branch_strategy` | `repair_branch` (validation repair) or `reuse_candidate_branch` (transient re-dispatch). |
| `retry_budget` | Remaining budget for the repair_agent. |
| `max_runtime_seconds` | Bounded from the slice. |
| `evidence_obligations` | Inherited from the slice. |
| `provider_fit` | Provider/model hints for Atlas Decide (AP-799 does not choose). |
| `merge_policy` | `review_required` — repair output still flows through normal gates and merge governor. |
| `stop_condition` | `same failure signature twice OR retry budget exhausted`. |

AP-799 only *prepares* this input. AP-797 executes the repair lane; merge
remains governed by AP-769/AP-774.

## Safety Rules

- No provider invocation, ever. AP-799 is a planner.
- No merge, deploy, push, secret access or destructive operation.
- No write to the provider/session store.
- No permanent quarantine for transient provider failures.
- No repair that touches forbidden files or escapes `allowed_files`.
- No repair without a validation command to prove the fix.
- No repeat of a failure capsule that already exhausted its budget.
- `R4`/`R5`/`critical` slices escalate instead of auto-repairing.

## Acceptance

- A retryable validation failure within scope and budget produces a repair plan
  with a non-null `repair_lane_input` and `branch_strategy = repair_branch`.
- A scope violation is `non_retryable`, `repair_allowed = false`, with no repair
  lane input.
- A provider timeout / rate limit is classified transient with
  `permanent_quarantine = false`.
- A security blocker routes to operator review with no repair lane input.
- An exhausted retry budget (or a repeated exhausted failure signature) is
  `blocked_retry_exhausted`.
- `repair_plan_hash` is deterministic across identical inputs.
- The plan declares no provider invocation and no merge side effects.
