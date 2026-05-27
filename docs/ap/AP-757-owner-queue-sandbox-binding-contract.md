---
id: AP-757-owner-queue-sandbox-binding-contract
type: architecture_proposal
title: AP-757 Owner Queue Sandbox Binding Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Binds AP-749 owner-specific Dev/Forge queue consumption to a real AP-756 materialized branch/worktree sandbox. AP-757 closes the gap between "queue item is approved" and "owner runtime may start" by requiring the AP-756 record to match the AP-747 handoff, prove a local worktree exists and travel inside the owner_runtime_input. It creates no executor, provider path, branch manager, merge, deploy, external push, secret access or new OS.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-757 Owner Queue Sandbox Binding Contract

## Decision

AP-757 extends AP-749. A Dev/Forge owner queue item may become
`ready_for_owner_consumption` only when it has all of:

```text
AP-747 owner queue release
AP-748 Evidence/Morning Inbox/Portfolio visibility
AP-756 materialized branch/worktree sandbox record
AP-749 operator execution receipt
```

Without AP-757, AP-749 could prove logical path isolation while still handing an
owner runtime a queue item with no physical isolated worktree. AP-757 makes the
physical sandbox part of the owner runtime input.

## Anti-Duplication Resolution

AP-757 does not create a new owner, executor or branch manager. It reuses:

- AP-756 as the only materializer of local branch/worktree sandboxes;
- AP-749 as the owner queue consumption gate;
- AP-747 as queue release owner;
- Atlas Dev and Forge as execution owners;
- AP-750 as result bridge after owner execution.

## Boundary

AP-757 may:

- read one AP-756 sandbox report or JSONL record;
- verify schema, `ap_contract=AP-756` and `status=materialized`;
- verify branch/worktree flags are true;
- verify `materialization.worktree_path` exists locally;
- verify AP-756 `source_refs.handoff_hash` matches AP-747 handoff hash;
- attach a `branch_sandbox` packet to AP-749 owner runtime input.

AP-757 must not:

- create branches or worktrees;
- invoke Atlas Dev, Forge, providers or schedulers;
- apply patches, commits, merges, deploys or external pushes;
- access secrets or perform destructive changes;
- bypass AP-749 execution receipt or AP-750 result bridge.

## Schemas

```text
atlas.software_company_stewardship.ap757_sandbox_binding_check.v1
atlas.software_company_stewardship.ap757_owner_runtime_sandbox_binding.v1
```

## CLI

```text
php artisan atlas:software-company-stewardship owner-queue-consumption-gate \
  --release-file=<ap747.jsonl> \
  --outcome-file=<ap748.json> \
  --sandbox-record-file=<ap756.jsonl> \
  --sandbox-id=<sandbox-id> \
  --execution-receipt-file=<ap749-receipt.json> \
  --json
```

## Acceptance

- AP-749 with execution receipt but no AP-756 record blocks with
  `ap756_materialized_sandbox_required`.
- AP-756 dry-run/planned records do not satisfy AP-757.
- AP-756 handoff hash must match the AP-747 release handoff hash.
- Missing local worktree path blocks before owner runtime input is ready.
- Ready AP-749 reports include `sandbox_binding`.
- Atlas Dev owner runtime input includes `branch_sandbox` at top level and inside
  `dev_runtime_payload.artifact_agent_packet`.
- Forge owner runtime input includes `branch_sandbox`.
- Claim policy proves AP-749 still starts no runtime and AP-757 creates no
  branch/worktree itself.
