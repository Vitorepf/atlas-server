---
id: AP-794-finding-slice-planner
type: ap_contract
title: AP-794 Finding Slice Planner Contract
status: active
implementation_state: architecture_contract
owner: software_company_stewardship
summary: Canonical contract for decomposing large, strategic and self-referential Stewardship findings into bounded executable slices before provider execution. AP-794 exists because factory_max must not send broad "make the factory better" prompts to providers; it must convert high-ROI findings into Atlas Dev or Forge slices with owner, allowed files, validation, evidence and merge policy. It reuses AP-748/AP-785 discovery, AP-786 sessions, AP-790 runner, AP-793 isolated execution substrate and existing Dev/Forge owners.
related_paths:
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AgenticEngineeringOsFindingEngineService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php
requires_evidence: true
risk_level: high
---
# AP-794 Finding Slice Planner Contract

## Authority

AP-794 is the decomposition contract between high-level Stewardship findings and
real owner execution. It is not a new finding engine, scheduler, Dev runtime,
Forge runtime, provider router or merge governor.

Anti-duplication decision:

```text
reuse_or_extend:
  AP-748/AP-785 finding discovery and priority backlog
  AP-786 autonomous evolution session
  AP-790 reliable loop runner
  AP-793 isolated agent execution substrate
  Atlas Dev and Atlas Forge owner runtimes
do_not_create:
  parallel finding engine
  parallel backlog
  parallel owner runtime
  direct provider task planner
  prompt-only decomposition layer
```

## Problem

`factory_max` should choose work with the highest leverage for the software
factory. The failure mode is that high-leverage findings are usually too broad
for a single provider call. When a loop sends a broad self-referential finding
to a provider, the result is usually timeout, no patch, starvation recovery or a
small accidental edit.

AP-794 prevents that by making decomposition a blocking gate.

## Core Rule

Large findings are not executable work.

Before AP-786/AP-790 invokes an owner runtime for a large, strategic or
self-referential finding, the finding must become at least one bounded slice:

```text
finding -> slice_plan -> executable_slice[] -> owner_runtime
```

If no honest slice exists, the correct result is:

```text
status = blocked
blocker = operator_or_architect_spec_required
```

The loop must not downgrade the finding into a trivial test, docs-only edit or
starvation recovery item just to keep moving.

## Schema

```text
atlas.stewardship.finding_slice_plan.v1
```

Required fields:

| Field | Meaning |
|---|---|
| `finding_id` | Stable id/hash of the source finding. |
| `finding_summary` | Human-readable finding summary. |
| `finding_kind` | `bug`, `gap`, `test`, `risk`, `runtime_bottleneck`, `architecture`, etc. |
| `factory_value_score` | Expected factory leverage. |
| `decomposition_status` | `sliced`, `blocked`, `operator_review_required`. |
| `blockers[]` | Why no slice can run. |
| `slices[]` | Ordered executable slices. |
| `plan_hash` | Deterministic hash of the plan. |
| `source_receipts[]` | Finding, priority and context receipts. |

Each slice uses:

```text
atlas.stewardship.executable_slice.v1
```

Required fields:

| Field | Meaning |
|---|---|
| `slice_id` | Stable slice id. |
| `sequence` | Order within the plan. |
| `owner` | `atlas_dev`, `forge`, `memory`, `stewardship`, etc. |
| `risk_level` | AAEOS/Dev risk level. |
| `objective` | One-sentence executable objective. |
| `allowed_files[]` | Exact allowed paths or narrow glob patterns. |
| `forbidden_files[]` | Paths never touched by this slice. |
| `expected_diff_shape` | Test-only, service-only, adapter-only, docs+test, etc. |
| `validation_commands[]` | Focused commands required before merge. |
| `evidence_obligations[]` | Receipts, logs, tests, hashes, inbox refs. |
| `provider_fit` | Provider/model role hints for Atlas Decide. |
| `max_runtime_seconds` | Slice runtime budget. |
| `retry_policy` | Retry count and transient/permanent blockers. |
| `merge_policy` | Auto-merge eligible, review required, never auto-merge. |
| `success_condition` | Machine-checkable completion condition. |

## Slice Quality Bar

A slice is executable only if all are true:

- the objective is narrower than the original finding;
- allowed files are explicit enough for scope guard;
- at least one validation command exists;
- the expected diff shape is declared;
- evidence obligations are declared;
- the owner runtime can execute it;
- max runtime is bounded;
- success and no-progress conditions are both explicit;
- merge policy is explicit;
- AP-793 real-cycle facts can be collected for it.

If a slice fails the quality bar, it must not be sent to a provider.

## Owner Routing

AP-794 does not replace routing. It annotates slices for existing owner routing:

| Slice type | Owner |
|---|---|
| Focused PHP service/test change | `atlas_dev` |
| Cross-runtime, multi-file, architecture or Obra-level change | `forge` |
| Documentation-only governance correction | `stewardship` or operator review |
| Memory/retrieval/ACOS improvement | `memory` |
| Ambiguous or sensitive change | `operator_review_required` |

Atlas Decide remains responsible for provider/model selection.

## Factory Max Policy

In `factory_max`, priority is not enough. A candidate must be both high-value
and sliceable.

Selection order:

1. high factory value and already sliceable;
2. high factory value and sliceable by AP-794;
3. medium factory value that unblocks AP-793/AP-790 reliability;
4. high-impact critical tests for runtime safety;
5. docs only when they unblock runtime or stop future agents from lying.

Rejected in `factory_max`:

- routine missing tests unless they protect critical runtime;
- docs-only churn with no runtime unlock;
- broad "improve Atlas" prompts;
- findings that need Forge but lack Forge authority;
- findings with no allowed files or validation command;
- findings that recently timed out and have not been re-sliced.

## Interaction With AP-793

AP-793 defines the substrate. AP-794 defines what enters that substrate.

```text
AP-748/AP-785 finding
  -> AP-794 slice plan
  -> AP-793 substrate facts
  -> AP-786/AP-790 owner execution
  -> AP-792 certification
```

AP-792 must not certify a `factory_max` production run if a large finding reached
provider execution without an AP-794 slice plan.

## Blocking Modes

| Blocker | Meaning |
|---|---|
| `operator_or_architect_spec_required` | Finding is valuable but too broad for autonomous slicing. |
| `owner_runtime_not_ready` | Slice exists but target owner cannot execute it honestly. |
| `validation_command_missing` | No focused validation exists. |
| `allowed_files_too_broad` | Scope is not safe enough for autonomous execution. |
| `provider_fit_unknown` | Atlas Decide cannot choose a provider/model role. |
| `evidence_obligations_missing` | Completion cannot be certified. |

Blocked plans are useful evidence. They are not failures unless the loop repeats
them without changing the plan.

## Acceptance

- AP-794 is documented as reuse/extend, not a new runtime.
- Every factory_max large finding is sliced or blocked before provider
  invocation.
- At least three high-ROI findings can be converted into executable slices.
- Routine low-value maintenance is suppressed when factory_max asks for maximal
  factory progress.
- AP-792 can detect provider execution without AP-794 slice evidence.
- No provider receives a broad "make the factory better" prompt.
