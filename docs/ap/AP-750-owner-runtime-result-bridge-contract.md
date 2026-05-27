---
id: AP-750-owner-runtime-result-bridge-contract
type: ap_contract
title: AP-750 Owner Runtime Result Bridge Contract
status: active
summary: Bridges Atlas Dev / Forge owner runtime results back into the Atlas Software Company Stewardship Stack after AP-749 owner queue consumption and AP-758 owner runtime execution adapter. It emits canonical Evidence, Morning Inbox and Portfolio outcome signals and optional append-only result records. It never invokes Dev/Forge/providers, creates branches/worktrees, mutates repos, merges, deploys, pushes externally, touches secrets, creates a runtime or bypasses operator review.
owner: programming
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeServiceTest.php
---
# AP-750 Owner Runtime Result Bridge Contract

## Summary

AP-750 closes the next Stewardship loop boundary after AP-749.

```text
AP-747 Dev/Forge release
-> AP-748 Evidence/Morning Inbox/Portfolio visibility
-> AP-749 owner-specific consumption gate
-> AP-758 governed owner runtime execution adapter
-> Atlas Dev or Forge owner runtime executes under its own authority/projection
-> AP-750 owner runtime result bridge
-> Evidence + Morning Inbox + Portfolio outcome signals
-> AP-751 Portfolio health/risk/rebalance intake
```

AP-750 does not execute work. It accepts a result receipt produced by the real
owner runtime (`AtlasDevRuntimeService` or `AtlasForgeParallelDurableCoordinator`)
and turns that result into reviewable, replayable Stewardship signals.
AP-758 is the canonical adapter that can produce the AP-750-compatible
`owner_result` from a ready AP-749 consumption packet without creating a new
runtime or bypassing owner authority.

## Anti-Duplication Decision

The placement gate reports high overlap with AP-740/AP-748, AP-749, Evidence,
Morning Inbox, Portfolio, Atlas Dev, Forge and Agent Control Plane contracts.
That overlap is intentional. AP-750 reuses those owners and adds only the missing
post-consumption result bridge.

AP-750 is not:

- a Dev runtime;
- a Forge runtime;
- a provider invoker;
- a branch/worktree creator;
- a new Evidence Ledger;
- a new Morning Inbox;
- a Product Mode executor;
- a new OS or parallel runtime.

## Authority

AP-750 may:

- read AP-749 ready/recorded consumption packets;
- read owner runtime result receipts;
- verify consumption/result identity;
- verify evidence pack presence;
- verify changed files against the AP-749 isolation boundary;
- block merge/deploy/external push/secret/destructive claims without explicit
  operator approval;
- emit projected Evidence items;
- emit projected Morning Inbox review items;
- emit Portfolio outcome feed signals;
- append an idempotent JSONL result record only when explicitly requested.

AP-750 must not:

- invoke Atlas Dev;
- invoke Forge;
- call providers;
- create branches or worktrees;
- mutate target repos;
- merge, deploy or push;
- access secrets;
- perform destructive changes;
- mark owner work merged/deployed;
- bypass operator review.

## Schemas

```text
atlas.software_company_stewardship.owner_runtime_result_bridge.v1
atlas.software_company_stewardship.owner_runtime_result.v1
atlas.software_company_stewardship.owner_runtime_result_record.v1
```

## Required Inputs

| Input | Requirement |
|---|---|
| AP-749 consumption | `status` is `ready_for_owner_consumption` or `owner_consumption_recorded`. |
| Owner result receipt | Uses `atlas.software_company_stewardship.owner_runtime_result.v1` and contains result id/status, `consumption_id`, `release_id`, `queue_item_id`, `target_owner`, evidence pack and changed files/tests. |
| Evidence pack | Contains at minimum `evidence_hash` and summary. |
| Isolation boundary | Changed files must stay inside AP-749 allowed paths and outside forbidden paths. |
| Irreversible approval | Required only if the owner result claims merge, deploy, external push, secret access or destructive change already occurred. |

## CLI

Projection:

```bash
php artisan atlas:software-company-stewardship owner-runtime-result-bridge \
  --consumption-file=<ap749.jsonl> \
  --result-file=<owner-result.json> \
  --json
```

Append-only record:

```bash
php artisan atlas:software-company-stewardship owner-runtime-result-bridge \
  --consumption-file=<ap749.jsonl> \
  --result-file=<owner-result.json> \
  --record-result \
  --json
```

Irreversible action approval, only for already-claimed irreversible owner
results:

```bash
php artisan atlas:software-company-stewardship owner-runtime-result-bridge \
  --consumption-file=<ap749.jsonl> \
  --result-file=<owner-result.json> \
  --approval-file=<operator-approval.json> \
  --json
```

## Acceptance

- Missing AP-749 consumption blocks.
- Missing owner result receipt blocks.
- AP-749 status not ready/recorded blocks.
- Missing result schema or missing `consumption_id`, `release_id`, `queue_item_id`
  or `target_owner` blocks.
- Consumption/result identity mismatch blocks.
- Missing evidence pack blocks.
- Changed files outside AP-749 isolation blocks.
- Merge/deploy/external push/secret/destructive claims without explicit operator
  approval block.
- Valid result emits one Evidence item, one Morning Inbox item and one Portfolio
  feed signal.
- `record_result=true` appends JSONL idempotently.
- Product Mode/Cockpit exposes AP-750 as a review item and control, without
  executing anything.
- Focused unit tests and Product Mode aggregate tests pass.
