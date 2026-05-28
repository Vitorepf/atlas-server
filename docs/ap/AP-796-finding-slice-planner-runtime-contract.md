---
id: AP-796-finding-slice-planner-runtime
type: ap_contract
title: AP-796 Finding Slice Planner Runtime Contract
status: active
implementation_state: implemented
owner: software_company_stewardship
summary: Runtime implementation of the AP-794 Finding Slice Planner contract. AP-796 is the executable service that turns a large, strategic or self-referential Stewardship finding into one or more bounded executable slices, or blocks honestly when no honest slice exists. It is a pure, read-only planner with no provider call, no branch/worktree and no mutation outside its own output. AP-786 invokes it before any owner runtime in factory_max so a broad "make the factory better" finding can never reach a provider unsliced.
related_paths:
  - docs/ap/AP-794-finding-slice-planner-contract.md
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FindingSlicePlannerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FindingSlicePlannerServiceTest.php
requires_evidence: true
risk_level: high
---
# AP-796 Finding Slice Planner Runtime Contract

## Authority

AP-796 is the runtime of AP-794. AP-794 is the architecture contract; AP-796 is
`FindingSlicePlannerService`, the executable that produces an
`atlas.stewardship.finding_slice_plan.v1` from a single finding.

AP-796 is not a new finding engine, scheduler, priority engine, owner runtime,
provider router, merge governor or multi-agent lane orchestrator. It does not
read or write Product Mode, does not call a judge or repair agent, does not open
a branch/worktree and does not invoke any provider. Its only output is the slice
plan structure returned to the caller.

Anti-duplication decision:

```text
reuse_or_extend:
  AP-748/AP-785 finding discovery and priority backlog (input only)
  AP-786 autonomous evolution session (caller)
  AP-790 reliable loop runner (supervisor)
  AP-793 isolated agent execution substrate (downstream)
do_not_create:
  parallel finding engine
  parallel priority/selection engine
  parallel owner runtime or provider router
  multi-agent lane orchestrator
  prompt-only decomposition layer
```

## Core Rule

Large findings are not executable work. AP-796 converts a large, strategic or
self-referential finding into bounded slices, or it blocks:

```text
finding + mode + scope_profile (+ optional context)
  -> finding_slice_plan
     -> decomposition_status = sliced   -> executable_slice[]
     -> decomposition_status = blocked  -> blockers[]  (no provider, no branch)
     -> decomposition_status = operator_review_required -> operator routing
```

In `factory_max`, a broad finding is mandatorily decomposed or blocked. The
planner never downgrades a broad finding into a trivial test, docs-only edit or
starvation-recovery item just to keep the loop moving.

## Input

`FindingSlicePlannerService::plan(array $input): array`

| Field | Meaning |
|---|---|
| `finding` | The source finding (AP-748 deep finding, AP-785 backlog seed, or factory seed). Required. |
| `mode` | `dry_run` (default) or `record`. AP-796 has no side effects in either mode; `mode` is carried for audit only. |
| `scope_profile` | `balanced` (default) or `factory_max`. `factory_max` enforces the broad-finding gate and the docs-only-churn rejection. |
| `context` | Optional. May carry `forge_authority` readiness, extra `allowed_files`, `forbidden_files` and explicit `validation_commands`. The planner never reads the filesystem. |

The planner is deterministic: the same input yields the same `plan_hash`.

## Output Schema

```text
atlas.stewardship.finding_slice_plan.v1
```

| Field | Meaning |
|---|---|
| `schema_version` | `atlas.stewardship.finding_slice_plan.v1`. |
| `finding_id` | Stable id/hash of the source finding. |
| `finding_summary` | Human-readable finding summary. |
| `finding_kind` | `bug`, `gap`, `test`, `risk`, `runtime`, `architecture`, etc. |
| `factory_value_score` | Expected factory leverage (from priority_score). |
| `scope_profile` | Profile the plan was built under. |
| `mode` | Mode the plan was built under. |
| `decomposition_status` | `sliced`, `blocked`, `operator_review_required`. |
| `blockers[]` | Why no slice can run (empty when `sliced`). |
| `slices[]` | Ordered executable slices (empty when blocked). |
| `plan_hash` | Deterministic hash of the canonical plan. |
| `source_receipts[]` | Finding/priority/context receipts. |

Each slice uses:

```text
atlas.stewardship.executable_slice.v1
```

| Field | Meaning |
|---|---|
| `slice_id` | Stable, deterministic slice id. |
| `sequence` | Order within the plan (1-based). |
| `owner` | `atlas_dev`, `forge`, `memory`, `stewardship`. |
| `risk_level` | `low`, `medium`, `high`, `critical`. |
| `objective` | One-sentence executable objective, narrower than the finding. |
| `allowed_files[]` | Exact, bounded allowed paths. |
| `forbidden_files[]` | Paths never touched (always includes the global forbidden set). |
| `expected_diff_shape` | `test_only`, `service_only`, `service_and_test`, `docs_and_test`, `docs_only`. |
| `validation_commands[]` | Focused commands required before merge (at least one). |
| `evidence_obligations[]` | Receipts, logs, tests, hashes, inbox refs. |
| `provider_fit` | Provider/model role hints for Atlas Decide. |
| `max_runtime_seconds` | Slice runtime budget. |
| `retry_policy` | Retry count and transient/permanent blockers. |
| `merge_policy` | `auto_merge_eligible`, `review_required`, `never_auto_merge`. |
| `success_condition` | Machine-checkable completion condition. |

## Slice Quality Bar

A slice is emitted only if all are true; otherwise the finding blocks:

- the objective is narrower than the finding and not a generic improvement prompt;
- allowed files are explicit and bounded (non-empty, no directory/glob, within cap);
- at least one validation command exists;
- the expected diff shape is declared;
- evidence obligations are declared;
- the resolved owner runtime can execute it;
- max runtime is bounded;
- success condition and merge policy are explicit;
- a provider fit can be assigned.

## Blocking Modes

| Blocker | Meaning |
|---|---|
| `operator_or_architect_spec_required` | Valuable but too broad/generic to slice autonomously (includes "make Atlas better"). |
| `owner_runtime_not_ready` | Slice exists but the target owner cannot execute it honestly (e.g. forge without live authority). |
| `validation_command_missing` | No focused validation can be derived. |
| `allowed_files_too_broad` | No bounded file scope (empty, directory-level or glob). |
| `provider_fit_unknown` | Atlas Decide cannot be given a provider/model role hint. |
| `evidence_obligations_missing` | Completion could not be certified. |
| `factory_max_docs_only_churn` | `factory_max` docs-only change with no runtime/certification unlock. |

Blocked plans are useful evidence. They are failures only if the loop repeats
them without changing the plan.

## Accepted And Decomposed Cases

- **Critical missing test** (`kind=test`/`missing_test`) with concrete
  `affected_files` and an `expected_test:*` evidence ref becomes one `atlas_dev`
  slice with `expected_diff_shape=test_only` (or `service_and_test`).
- **Runtime bottleneck** (`kind=runtime`) with a target service file and a test
  command becomes one implementable `atlas_dev` slice with
  `expected_diff_shape=service_and_test`.
- **Broad finding with multiple concrete target files** is decomposed into one
  bounded slice per target file (each paired with its derived test), in
  deterministic order, capped to a safe maximum.

## Rejected Cases

- Broad self-referential finding with no concrete files and a generic objective
  ("make Atlas better") -> `operator_or_architect_spec_required`.
- `factory_max` docs-only churn with no runtime/certification unlock ->
  `factory_max_docs_only_churn`.
- Forge-owned slice without live Forge authority in context ->
  `owner_runtime_not_ready`.

## Interaction With AP-786 / AP-790 / AP-793

```text
AP-748/AP-785 finding
  -> AP-796 slice plan (this contract)
  -> AP-793 substrate facts
  -> AP-786 owner execution
  -> AP-792 certification
```

AP-786 invokes AP-796 in `factory_max` execute mode for the selected finding,
**before** sandbox materialization or any owner runtime. When the plan is
`sliced` the cycle proceeds and the plan is attached to the cycle for audit.
When the plan is `blocked` or `operator_review_required`, AP-786 records the
blocker, attaches the plan and does **not** materialize a branch or call a
provider. AP-790 records the slice/blocker evidence and never counts a blocked
broad finding as progress. AP-792 must not certify a `factory_max` production run
where a large finding reached provider execution without an AP-796 slice plan.

## Safety Rules

- No provider call.
- No branch/worktree, no git, no merge.
- No mutation outside the returned plan structure.
- Deterministic `plan_hash` over canonical input.
- Never fabricate a slice to keep the loop moving; block instead.

## Acceptance

- AP-796 is documented and implemented as the runtime of AP-794, reuse/extend.
- `plan_hash` is deterministic for identical input.
- A broad self-referential finding with no bounded scope is blocked.
- A broad finding with multiple concrete targets is decomposed into bounded
  slices.
- A narrow critical missing-test finding is accepted as one `atlas_dev` slice.
- `factory_max` docs-only churn without runtime unlock is rejected.
- AP-786 in `factory_max` does not call the owner runtime when the plan blocks.
- No provider receives a broad "make the factory better" prompt.
