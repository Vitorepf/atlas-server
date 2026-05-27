---
id: AP-758-owner-runtime-execution-adapter
title: AP-758 Owner Runtime Execution Adapter Contract
status: accepted
owner: programming
summary: Adds the governed adapter between AP-749 owner queue consumption and AP-759/AP-750 owner runtime result flow. AP-758 consumes a ready AP-749 packet, requires the AP-757/AP-756 materialized sandbox and an explicit operator runtime-start receipt, projects the existing Atlas Dev / Forge owner handoff, and emits an AP-750-compatible owner result shape for review or AP-759 execution. It creates no OS, runtime, branch, worktree, provider path, merge, deploy, external push, secret access or destructive change.
related_paths:
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-757-owner-queue-sandbox-binding-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterServiceTest.php
---

# AP-758 Owner Runtime Execution Adapter Contract

## Decision

AP-758 is the canonical projection bridge from:

```text
AP-749 ready owner queue consumption
-> AP-758 governed owner runtime execution adapter
-> optional AP-759 owner sandbox runtime runner
-> AP-750 owner runtime result bridge
```

It closes the gap where the Stewardship Stack could prepare owner runtime input
but had no canonical handoff point that proved sandbox binding, runtime start
receipt, owner reuse and AP-750-compatible result shape. AP-758 does not execute
the owner command. AP-759 owns the first concrete owner CLI command execution
inside the AP-756 worktree.

AP-758 is not a new runtime. It reuses:

- AP-749 for consumption gate and owner input;
- AP-756/AP-757 for branch/worktree sandbox binding;
- `AtlasDevRuntimeService` for Atlas Dev runtime projection;
- `AtlasForgeParallelDurableCoordinatorService` for Forge assignment projection;
- AP-759 for approved owner command execution inside the AP-756 sandbox;
- AP-750 for Evidence, Morning Inbox and Portfolio result review.

## Flow

```text
AP-747 release
-> AP-748 evidence/inbox/portfolio visibility
-> AP-756 materialized branch sandbox
-> AP-749 owner queue consumption gate
-> AP-758 owner runtime execution adapter
-> AP-759 owner sandbox runtime runner when operator authorizes execution
-> AP-750 result bridge
-> AP-751 Portfolio signal
```

## Input Contract

AP-758 requires:

- AP-749 consumption report or record with status `ready_for_owner_consumption`
  or `owner_consumption_recorded`;
- AP-749 claim policy `owner_runtime_start_authorized=true`;
- AP-757 sandbox binding with an existing AP-756 worktree;
- explicit `runtime_start_receipt` with decision
  `start_owner_runtime`, `execute_owner_runtime` or `invoke_owner_runtime`;
- target owner `atlas_dev` or `forge`.

## Output Schemas

```text
atlas.software_company_stewardship.owner_runtime_execution_adapter.v1
atlas.software_company_stewardship.owner_runtime_execution_record.v1
atlas.software_company_stewardship.ap758_owner_runtime_invocation.v1
atlas.software_company_stewardship.owner_runtime_result.v1
```

The embedded `owner_result` intentionally uses AP-750's owner result schema so
the next command can pass it directly to `owner-runtime-result-bridge`. When the
operator wants real owner command execution, AP-758 feeds AP-759 first; AP-759
then emits the AP-750-compatible result produced by the sandboxed owner command.

## CLI

```bash
php artisan atlas:software-company-stewardship owner-runtime-execute \
  --consumption-file=storage/ap749.jsonl \
  --consumption-id=afcons_... \
  --runtime-start-receipt-file=storage/ap758-start-receipt.json \
  --record-execution \
  --json
```

Then:

```bash
php artisan atlas:software-company-stewardship owner-runtime-result-bridge \
  --consumption-file=storage/ap749.jsonl \
  --result-file=storage/ap758-owner-result.json \
  --record-result \
  --json
```

## Boundary

AP-758 may:

- verify AP-749, AP-756 and AP-757 state;
- verify the runtime-start receipt;
- invoke existing owner projections;
- emit an AP-750-compatible owner result receipt;
- append an idempotent JSONL execution record when requested.

AP-758 must not:

- create a new OS, runtime, executor or provider path;
- create branch/worktree; AP-756 owns that;
- bypass Atlas Dev or Forge;
- invoke providers directly;
- execute owner CLI commands; AP-759 owns that boundary;
- mutate target repo outside existing owner authority;
- merge, deploy, push externally, access secrets or perform destructive changes;
- mark AP-750/Evidence/Portfolio complete by itself.

## Acceptance

- Blocks without AP-749 consumption.
- Blocks when AP-749 is not ready or recorded.
- Blocks without AP-757/AP-756 sandbox binding.
- Blocks without explicit runtime-start receipt.
- Atlas Dev path reuses `AtlasDevRuntimeService` projection.
- Forge path reuses `AtlasForgeParallelDurableCoordinatorService`.
- Emits AP-750-compatible `owner_result`.
- Feeds AP-759 when concrete owner command execution is authorized.
- Records append-only/idempotent execution JSONL.
- AP-750 accepts the emitted owner result.
- `php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterServiceTest.php` passes.
