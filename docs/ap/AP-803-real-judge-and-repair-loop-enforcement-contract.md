---
id: AP-803-real-judge-and-repair-loop-enforcement
type: ap_contract
title: AP-803 Real Judge and Repair Loop Enforcement Contract
status: active
implementation_state: enforced_in_judge_certification_and_product_mode
owner: software_company_stewardship
summary: Makes the multi-agent-per-task judge and repair loop real and honest. AP-803 hardens AP-798 (judge), AP-799 (repair planner), AP-800 (cycle certification) and AP-801 (live cycle executor) so the judge blocks bad work with one auditable decision enum, repair runs only within scope and budget, transient provider failures never become permanent quarantine, a failed repair never reads as success, and Product Mode exposes the judge/repair state. The judge stays read-only and never patches; repair only plans for security/scope blockers; certification only passes when the final judge accepted with tests and evidence present and repair attempts inside budget.
related_paths:
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-798-integration-lane-judge-contract.md
  - docs/ap/AP-799-repair-agent-failure-capsule-contract.md
  - docs/ap/AP-800-multi-agent-cycle-certification-visibility-contract.md
  - docs/ap/AP-801-multi-agent-live-cycle-executor-contract.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentIntegrationJudgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentRepairPlannerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentCycleCertificationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentLiveCycleExecutorService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentJudgeRepairLoopEnforcementTest.php
requires_evidence: true
risk_level: high
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.

# AP-803 Real Judge and Repair Loop Enforcement Contract

## Authority

AP-803 is an enforcement pass, not a new runtime. It hardens the already-landed
multi-agent substrate so the judge and repair loop are honest:

```text
reuse_or_extend:
  AP-798 MultiAgentIntegrationJudgeService     (judge: now emits a normalized decision enum + score)
  AP-799 MultiAgentRepairPlannerService        (repair: classify, bound, transient vs structural)
  AP-800 MultiAgentCycleCertificationService   (certify: require judge ACCEPT + repair-in-budget)
  AP-801 MultiAgentLiveCycleExecutorService    (compose: implementer -> reviewer -> judge -> repair -> certify)
do_not_create:
  parallel judge / parallel repair runtime
  provider execution inside the judge
  any LLM in the verdict
```

## Problem

The earlier loop too often recorded `blocked`/`synthetic`/`recovery` without a real
repair, and without separating a transient provider failure from a structural
one. AP-803 makes the judge block bad work, makes repair bounded and scope-safe,
and makes certification refuse a false success.

## Judge (read-only, never patches)

The judge stays a pure rules engine. AP-803 adds one normalized, auditable
decision enum derived from the detailed `status` + reason, so downstream gating
keys off one vocabulary:

| `decision` | When | Repair |
|---|---|---|
| `accept` | every gate passed | forward to merge governor; no repair |
| `repair_required` | recoverable failure (validation failed / reviewer requested changes) with repair budget | repair **execution** allowed |
| `blocked_scope_violation` | diff left allowed_files or touched forbidden_files | repair **plan only**, never execute |
| `blocked_security` | reviewer raised a security/safety blocker | repair **plan only**, never execute |
| `blocked_missing_evidence` | required evidence/diff/plan missing | no repair until evidence supplied |
| `operator_review` | diff-shape/risk/reviewer-approval needs a human | no autonomous repair |
| `reject` | hard reject (forbidden action, exhausted repair, reviewer reject) | no repair |

The judge also emits a seven-dimension `score`
(`scope`, `tests`, `evidence`, `safety`, `maintainability`, `value`,
`merge_readiness`) projected over the detailed gate scoring plus the security
signal. The detailed `scoring` block is preserved for audit. `repair_eligible`
(decision == `repair_required`) and `repair_plan_only` (scope/security blocks)
make the gate explicit.

The judge never merges, never invokes a provider and never uses an LLM.

## Repair (bounded, scope-safe, transient-aware)

The AP-799 repair planner is the only thing that decides whether a bounded repair
may run. AP-803 relies on these invariants:

- Repair **executes** only for a repairable failure with budget
  (`repair`/`transient_retry`). Security and scope-violation classifications are
  `operator_review` / `non_retryable` — they may produce a plan, never an
  execution.
- Repair has a per-cycle `max_attempts` (default 1–2, risk-capped); R4/R5/critical
  receive zero autonomous attempts.
- Repair never grows scope: `allowed_repair_files` stays inside the slice scope.
- A provider timeout or rate limit is `transient` and re-dispatched; it is never a
  permanent quarantine.
- An identical failure signature that already exhausted its budget is blocked, not
  retried.
- A repair that fails or exhausts its budget ends `blocked`/`non_retryable`, never
  `success`.

## Certification (no false success)

AP-800 production certification (`runtime_real` only) now requires, in addition to
its existing facts:

- `judge_accepted` — the final judge `decision`/`status` is an accept, not merely a
  present judge block. A completion/merge claim without a judge accept is the hard
  blocker `completed_without_judge_accept`.
- `repair_within_budget` — a present repair that failed or exceeded its budget is
  the hard blocker `repair_failed_or_over_budget`; it can never be a pass.
- existing gates stay: required lanes, slice plan when broad, evidence/inbox/
  validation/merge present, real (non-simulated) provider, merge truth.

Missing evidence still blocks acceptance; `test_mode` still never certifies
production.

## Product Mode visibility

The AP-800 `product_mode_projection`
(`atlas.agent_execution.multi_agent_cycle_product_mode.v1`) exposes the loop state
the operator needs:

- `judge_decision`: `present`, `accepted`, `decision`, `selected_candidate`;
- `repair`: `required`, `attempted`, `present`, `status`, `within_budget`;
- `final_blocker`: the first blocker (or null);
- `lanes`, `slice_plan`, `missing_capabilities`, `next_operator_action`.

The AP-801 cycle receipt carries the same `judge_decision`, `repair_decision` and
`blockers` for the desktop console.

## Hard Rules

- Judge is read-only and never patches.
- Repair runs only when the judge decision is `repair_required` (or tests failed
  with a repairable failure class) and budget remains.
- Repair may only plan, never execute, for `blocked_security` / `blocked_scope_violation`.
- Transient provider failures (timeout/rate limit) are re-dispatched, never
  permanently quarantined.
- A failed repair ends `blocked_after_repair`/`failed_after_repair`, never success.
- Certification passes only with a real judge accept, tests/evidence present and
  repair attempts inside budget.
- Every decision carries a failure capsule (repair) or a judge verdict (judge).

## Acceptance

- The judge emits one normalized decision enum and a seven-dimension score; it
  never merges, runs a provider or uses an LLM.
- Repair is bounded, scope-safe and transient-aware; security/scope only plan.
- Certification refuses production on a non-accept judge, missing evidence or an
  over-budget/failed repair.
- Product Mode shows judge decision, repair attempted/status and the final blocker.
- AP-798/799/800/801 remain the owner implementations; AP-803 does not fork them.
