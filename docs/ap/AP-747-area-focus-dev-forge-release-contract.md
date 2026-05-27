---
id: AP-747-area-focus-dev-forge-release-contract
type: architecture_proposal
title: AP-747 Area Focus Dev/Forge Operator-Owned Release Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Releases an AP-726 Area Focus branch-sandbox handoff into the real Atlas Dev or Forge owner queue only after an explicit operator release receipt. AP-748 bridges recorded releases into Evidence, Morning Inbox and Portfolio signals; AP-749 then gates owner-specific consumption; AP-750 bridges the eventual owner runtime result back to Stewardship outcomes. It creates no new OS/runtime/executor, does not invoke providers, does not create branches/worktrees, and never merges, deploys, pushes externally, touches secrets or performs destructive changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-719-area-focus-dev-forge-router-contract.md
  - docs/ap/AP-724-area-focus-operator-decision-receipts-contract.md
  - docs/ap/AP-726-area-focus-branch-sandbox-preflight-handoff-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxPreflightService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxHandoffService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-747 Area Focus Dev/Forge Operator-Owned Release Contract

## Decision

AP-747 is the first release boundary after AP-726.

AP-726 prepares a branch-sandbox preflight and handoff packet. AP-747 consumes
that packet and, only when a human/operator supplies an explicit release
receipt, emits a durable queue item for the existing implementation owners:

```text
AP-744 active operation
-> AP-726 branch-sandbox handoff
-> AP-747 operator release receipt
-> Atlas Dev queue item OR Forge queue item
-> AP-748 release outcome bridge
-> AP-749 owner-specific queue consumption gate
-> AP-750 owner runtime result bridge
```

This is a release to the owner queue, not runtime execution. Dev/Forge still own
execution, provider usage, worktree creation and verification gates. AP-748 owns
Evidence, Morning Inbox and Portfolio visibility for the release outcome.

## Anti-Duplication Resolution

The placement/session gates report overlap with Product Mode, AP-726, Atlas Dev,
Forge and Self-Construction release surfaces. That overlap is correct. AP-747
does not create a Stewardship OS, branch manager, Dev path, Forge path, scheduler
or provider path.

AP-747 resolves the overlap by reusing:

- AP-726 as the source preflight/handoff owner;
- AP-724/AP-747 operator receipt as the release authority;
- Atlas Dev Runtime (`atlas.dev_runtime.v1`) as the small/local owner;
- Atlas Forge Parallel Durable (`atlas.forge.parallel_durable.v1`) as the
  long-horizon/cross-system owner queue coordinator;
- Product Mode as the review/control surface;
- Evidence as the proof owner for later execution results.
- AP-748/AP-740 as the outcome bridge into Evidence, Morning Inbox and Portfolio.
- AP-749 as the owner-specific queue consumption gate.
- AP-750 as the owner runtime result bridge after Dev/Forge reports back.

## Boundary

AP-747 may:

- validate an AP-726 ready handoff;
- require `decision=release`, `operator_actor` and matching
  `target_handoff_hash`;
- emit an Atlas Dev queue item targeting `atlas.dev_runtime.v1`;
- emit a Forge queue item using the real
  `AtlasForgeParallelDurableCoordinatorService`;
- append an idempotent JSONL release record only when `record_release=true`;
- expose CLI status for operator review.

AP-747 must not:

- create a branch or worktree;
- invoke providers or start runtime execution;
- mutate target repos or apply fixes;
- merge, deploy, push externally, touch secrets or perform destructive actions;
- auto-approve AP-718 specs, AP-724 decisions, AP-726 handoffs or AP-747
  releases;
- bypass Product Mode, Evidence, Atlas Dev, Forge or operator review;
- create any new OS/runtime/executor.

## Schemas

```text
atlas.software_company_stewardship.area_focus_dev_forge_release.v1
atlas.software_company_stewardship.area_focus_dev_forge_release_record.v1
atlas.software_company_stewardship.area_focus_atlas_dev_queue_item.v1
atlas.software_company_stewardship.area_focus_forge_queue_item.v1
```

## CLI

Projection-only release:

```text
php artisan atlas:software-company-stewardship area-focus-dev-forge-release \
  --preflight-file=<ap726.json> \
  --release-receipt-file=<ap747-release-receipt.json> \
  --json
```

Durable operator-owned queue record:

```text
php artisan atlas:software-company-stewardship area-focus-dev-forge-release \
  --preflight-file=<ap726.json> \
  --release-receipt-file=<ap747-release-receipt.json> \
  --record-release \
  --json
```

## Status Semantics

| State | Meaning |
|---|---|
| `ready_for_owner_queue` | AP-726 handoff and AP-747 receipt are valid; queue item projected. |
| `owner_queue_recorded` | Queue item was appended to JSONL idempotently. |
| `blocked` | Required preflight, receipt, target hash, kill switch or ready handoff check failed. |

## Acceptance

- Missing AP-726 preflight blocks.
- Missing explicit release receipt blocks.
- Non-`release` decision blocks.
- Missing `operator_actor` blocks.
- Mismatched `target_handoff_hash` blocks.
- Kill switch blocks.
- Route `atlas_dev` emits a queue item targeting `atlas.dev_runtime.v1`.
- Route `forge` emits a queue item using
  `atlas.forge.parallel_durable.v1` assignment projection.
- AP-747 accepts both AP-726 `branch_plan.allowed_paths` and AP-786
  `branch_plan.allowed_files` as the same sandbox scope boundary; the emitted
  owner queue item normalizes them into `allowed_paths`/`expected_files` for
  AP-749 branch isolation.
- `record_release=true` appends JSONL idempotently.
- AP-748 can consume AP-747 projected or recorded releases without moving
  Dev/Forge execution authority into AP-747.
- AP-749 can consume AP-747/AP-748 only after Evidence, Morning Inbox and
  Portfolio visibility are proven.
- AP-750 can consume AP-749 only after the owner runtime produces a result
  receipt with evidence, isolation and irreversible-action checks.
- Claim policy proves no provider, no runtime execution, no branch/worktree, no
  target repo mutation, no merge/deploy/push/secrets/destructive action, no OS
  and no parallel runtime.
