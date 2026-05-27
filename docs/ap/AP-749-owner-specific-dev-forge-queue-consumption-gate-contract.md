---
id: AP-749-owner-specific-dev-forge-queue-consumption-gate-contract
type: architecture_proposal
title: AP-749 Owner-Specific Dev/Forge Queue Consumption Gate Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Lets existing Atlas Dev and Forge owners consume AP-747 queue items only after AP-748/AP-740 proved Evidence Ledger, Morning Inbox and Portfolio visibility and AP-757 binds the queue item to a materialized AP-756 branch/worktree sandbox. AP-749 produces owner runtime input packets and optional append-only consumption records; AP-758 invokes the governed owner runtime adapter; AP-750 bridges the eventual owner runtime result back to Evidence, Morning Inbox and Portfolio. AP-749 does not invoke providers, create branches/worktrees, mutate repos, merge, deploy, push, access secrets, create a new runtime or bypass Product Mode review.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-757-owner-queue-sandbox-binding-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-749 Owner-Specific Dev/Forge Queue Consumption Gate Contract

## Decision

AP-749 is the consumption gate after AP-747/AP-748.

```text
AP-747 owner queue record
-> AP-748 outcome bridge
-> AP-756 materialized sandbox
-> AP-757 sandbox binding
-> AP-749 owner-specific consumption gate
-> AP-758 owner runtime execution adapter
-> existing Atlas Dev runtime OR existing Forge runtime
-> AP-750 owner runtime result bridge
```

AP-749 does not execute work. It proves that a queue item is visible in Evidence,
Morning Inbox and Portfolio, then AP-757 proves a matching AP-756 worktree
sandbox exists, then AP-749 prepares the exact owner runtime input packet that
Atlas Dev or Forge may consume after an explicit operator execution receipt.
AP-750 is the next boundary after the owner has actually run and produced a
result receipt.
AP-758 is the governed adapter boundary between AP-749 and AP-750; it reuses
the existing owner runtime projection and emits an AP-750-compatible
`owner_result`.

## Anti-Duplication Resolution

Placement reports overlap with AP-747, AP-748, Atlas Dev, Forge, Product Mode
and Agent Control Plane contracts. That overlap is intentional. AP-749 extends
the existing release/outcome chain and reuses the existing owner runtimes:

- AP-747 remains queue release owner.
- AP-748/AP-740 remain outcome/evidence/inbox owners.
- AP-756 remains branch/worktree sandbox materializer.
- AP-757 remains sandbox binding check inside AP-749.
- AP-758 remains the owner runtime execution adapter after AP-749.
- AP-750 remains owner runtime result bridge owner.
- Product Mode remains operator review surface.
- Atlas Dev Runtime remains owner for `atlas_dev`.
- Atlas Forge Parallel Durable remains owner for `forge`.

No Stewardship executor, branch manager, provider path, queue runtime, OS or
parallel ledger may be created here.

## Boundary

AP-749 may:

- read one AP-747 release report or JSONL record;
- read one AP-748/AP-740 outcome bridge report;
- verify matching evidence, Morning Inbox and Portfolio signal;
- verify branch/path isolation before owner consumption;
- verify a matching AP-756 materialized sandbox through AP-757;
- require an operator execution receipt for `ready_for_owner_consumption`;
- emit Atlas Dev owner runtime input;
- emit Forge owner runtime input;
- append an idempotent consumption record only when explicitly requested.

AP-749 must not:

- invoke Atlas Dev, Forge, a provider or a scheduler;
- create branches, worktrees, commits or patches;
- mutate target repos;
- merge, deploy, push externally, touch secrets or perform destructive changes;
- mark the work done;
- bypass Product Mode, Evidence, Morning Inbox, Portfolio or operator review.

## Schemas

```text
atlas.software_company_stewardship.owner_queue_consumption_gate.v1
atlas.software_company_stewardship.owner_queue_consumption_record.v1
atlas.software_company_stewardship.ap749_atlas_dev_owner_runtime_input.v1
atlas.software_company_stewardship.ap749_forge_owner_runtime_input.v1
atlas.software_company_stewardship.ap748_outcome_visibility_check.v1
atlas.software_company_stewardship.branch_isolation_check.v1
atlas.software_company_stewardship.ap757_sandbox_binding_check.v1
atlas.software_company_stewardship.ap757_owner_runtime_sandbox_binding.v1
```

## CLI

Projection/review:

```text
php artisan atlas:software-company-stewardship owner-queue-consumption-gate \
  --release-file=<ap747.jsonl> \
  --outcome-file=<ap748.json> \
  --json
```

Ready for owner runtime input:

```text
php artisan atlas:software-company-stewardship owner-queue-consumption-gate \
  --release-file=<ap747.jsonl> \
  --outcome-file=<ap748.json> \
  --sandbox-record-file=<ap756.jsonl> \
  --sandbox-id=<sandbox-id> \
  --execution-receipt-file=<ap749-receipt.json> \
  --json
```

Append-only consumption record:

```text
php artisan atlas:software-company-stewardship owner-queue-consumption-gate \
  --release-file=<ap747.jsonl> \
  --outcome-file=<ap748.json> \
  --sandbox-record-file=<ap756.jsonl> \
  --sandbox-id=<sandbox-id> \
  --execution-receipt-file=<ap749-receipt.json> \
  --record-consumption \
  --json
```

## Acceptance

- Missing AP-747 release blocks.
- Missing AP-748/AP-740 outcome bridge blocks.
- Missing AP-747/AP-748 source contracts blocks.
- Missing matching `ap747_owner_queue_release` evidence blocks.
- Missing matching Morning Inbox release review item blocks.
- Missing Portfolio feed or release queue id blocks.
- Missing branch/path isolation blocks.
- Missing AP-756 materialized sandbox blocks once an execution receipt is
  provided.
- AP-756 handoff hash must match the AP-747 release handoff hash.
- Owner runtime input carries the AP-757 `branch_sandbox` packet.
- Missing operator execution receipt yields `operator_review_required`.
- Valid receipt yields `ready_for_owner_consumption`.
- `record_consumption=true` appends JSONL idempotently.
- Owner runtime results must return through AP-750 before they feed Evidence,
  Morning Inbox, Portfolio or follow-up allocation.
- AP-758 must be used as the canonical adapter before AP-750 when the result is
  created from an AP-749 consumption packet.
- Claim policy proves no provider, no runtime execution by this gate, no branch,
  no worktree, no repo mutation, no merge/deploy/push/secrets/destructive action,
  no OS and no parallel runtime.
