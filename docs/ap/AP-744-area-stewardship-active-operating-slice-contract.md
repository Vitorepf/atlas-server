---
id: AP-744-area-stewardship-active-operating-slice-contract
type: architecture_proposal
title: AP-744 Area Stewardship Active Operating Slice Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Implements the first governed active Area Stewardship operating slice. It consumes AP-743 active handoff packets, runs the existing AP-722 Area Focus operational cycle, prepares AP-718 Self-Directed Evolution spec drafts and AP-726 Dev/Forge branch-sandbox handoffs, and may record an idempotent JSONL active operation. It creates no new OS, runtime or executor and never invokes providers, creates branches, dispatches Dev/Forge, mutates repos, merges, deploys, touches secrets or bypasses operator review.
related_paths:
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-713-area-stewardship-layer-contract.md
  - docs/ap/AP-722-area-focus-loop-operational-certification-contract.md
  - docs/ap/AP-726-area-focus-branch-sandbox-handoff-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-743-area-stewardship-active-handoff-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusLoopOperationalOrchestratorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusSpecDraftBridge.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-744 Area Stewardship Active Operating Slice Contract

## Decision

AP-744 closes the first real active operating gap for Area Stewardship.

AP-743 prepares the active handoff packet. AP-744 consumes that packet and runs
one governed Area Stewardship cycle by reusing the existing owners:

```text
AP-743 active handoff packet
-> AP-722 Area Focus operational cycle
-> AP-718 Self-Directed Evolution spec drafts for spec gaps
-> AP-726 branch-sandbox Dev/Forge handoff preflight
-> operator review queue
```

This is an active stewardship **operating slice**, not permissionless mutation.
It actively conducts owners and produces an operation queue, but it does not
execute fixes, spawn providers, open branches or dispatch Dev/Forge.

## Anti-Duplication Resolution

The placement gate correctly reports high overlap with AP-743, Area Focus,
Self-Directed Evolution, Atlas Dev, Forge, Evidence and Product Mode. AP-744
resolves the overlap by orchestration only:

- AP-743 remains the active handoff owner.
- AP-722 remains the Area Focus operational cycle owner.
- AP-718 remains the finding -> spec draft bridge owner.
- AP-726 remains the branch-sandbox Dev/Forge handoff owner.
- Atlas Dev and Forge remain the implementation owners.
- Evidence remains the proof owner.
- Product Mode/Cockpit remains the review surface.

AP-744 does not create a new OS, runtime, executor, scheduler, area registry,
proposal registry, branch manager, Dev path, Forge path or Evidence path.

## Boundary

AP-744 may:

- require AP-743 status `ready`;
- consume one AP-743 active handoff packet;
- run or accept an AP-722 Area Focus operational cycle;
- map AP-719 work orders into an active operation queue;
- create AP-718 proposal-only spec drafts for self-directed gaps;
- prepare AP-726 branch-sandbox handoff preflight for Dev/Forge work orders;
- surface AP-724 operator decisions needed for work orders;
- append an idempotent active operation JSONL record only when
  `--record-active-operation` is explicitly requested.
- expose read-only operation status, counters and review queue items inside the
  existing AP-739 Product Mode/Cockpit surface.

AP-744 must not:

- invoke providers;
- call Atlas Dev or Forge executors;
- create branches, worktrees, commits or PRs;
- mutate target repos;
- merge, deploy, push externally or touch secrets;
- perform destructive changes;
- auto-approve AP-718 specs, AP-724 work orders or AP-726 handoffs;
- bypass Product Mode/Cockpit or operator review;
- create a new OS/runtime/executor.

## Schemas

```text
atlas.area_stewardship.active_operation.v1
atlas.area_stewardship.active_operation_record.v1
atlas.area_stewardship.active_operation_queue.v1
```

## CLI

AP-744 extends the existing stewardship command:

```text
php artisan atlas:software-company-stewardship area-stewardship-active-operate --area=agentic_engineering_os --json
```

Default mode is projection-only. Durable recording is explicit:

```text
php artisan atlas:software-company-stewardship area-stewardship-active-operate --area=agentic_engineering_os --record-active-operation --json
```

The JSONL storage path is area-scoped:

```text
storage/atlas/software_company_stewardship/area_stewardship_active_operations/{area}.jsonl
```

## Status Semantics

| State | Meaning |
|---|---|
| `active_cycle_ready` | AP-743 is ready, AP-722 ran, and AP-726 is ready or not applicable. |
| `active_cycle_partial` | The cycle ran, but some Dev/Forge handoffs still await AP-724 decisions. |
| `awaiting_active_handoff` | AP-743 is still awaiting AP-731/AP-732 readiness. |
| `blocked` | Handoff, Area Focus cycle or safety gates blocked operation. |

## Acceptance

- Service emits `atlas.area_stewardship.active_operation.v1`.
- Service refuses to operate before AP-743 ready.
- Ready operation includes AP-743 packet summary and AP-722 cycle hash.
- Operation queue includes route counts, AP-724 decision anchors, AP-718 spec
  draft counts and AP-726 Dev/Forge handoff counts.
- Self-directed gaps produce AP-718 proposal-only drafts.
- Dev/Forge work orders produce AP-726 preflight handoffs, not execution.
- `--record-active-operation` appends JSONL idempotently.
- Product Mode/Cockpit exposes AP-744 status, counters, command anchor and
  review items without recording or executing the operation.
- Claim policy proves no provider, no Dev/Forge invocation, no branch, no repo
  mutation, no merge/deploy/secrets, no runtime/OS creation and operator review
  required.
- Tests prove awaiting handoff, ready operation, partial operation, idempotent
  recording and deterministic operation hashes.
