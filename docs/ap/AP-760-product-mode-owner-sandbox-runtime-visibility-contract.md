---
id: AP-760-product-mode-owner-sandbox-runtime-visibility
type: ap_contract
title: AP-760 Product Mode Owner Sandbox Runtime Visibility Contract
status: active
owner: programming
summary: Extends AP-739 Product Mode/Cockpit so AP-759 owner sandbox runtime runner plans, executions and records are visible as governed review items between AP-758 and AP-750. AP-760 is a visibility and control-surface contract only: it does not execute owner commands, create branches, invoke providers, merge, deploy or accept results.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-761-product-mode-desktop-end-to-end-stewardship-console-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelServiceTest.php
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/StewardshipSurface.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/__tests__/stewardshipCockpitContract.test.ts
requires_evidence: true
risk_level: critical
---
# AP-760 Product Mode Owner Sandbox Runtime Visibility Contract

## Decision

AP-760 makes AP-759 visible inside the existing AP-739 Product Mode/Cockpit.

The canonical chain is now:

```text
AP-747 Dev/Forge queue release
-> AP-748 release outcome bridge
-> AP-749 owner-specific consumption gate
-> AP-756 branch/worktree sandbox materializer
-> AP-757 owner queue sandbox binding
-> AP-758 owner runtime execution adapter
-> AP-759 owner sandbox runtime runner
-> AP-760 Product Mode visibility for AP-759
-> AP-750 owner runtime result bridge
```

AP-759 is where an explicitly approved owner command may run inside the AP-756
sandbox. AP-760 does not run that command. AP-760 only exposes the AP-759 plan,
result, blockers, counters and command anchors in the same Product Mode cockpit
that already shows AP-747/AP-748/AP-749/AP-750.

AP-761 is the desktop rendering companion: it makes the existing Atlas Desktop
`stewardship` surface show the AP-759/AP-750 slice inside the end-to-end
operating pipeline.

## Duplicate Resolution

AP-760 must reuse:

- `ProductModeCockpitSurfaceService`;
- `ProductModeOperationalControlsReadModelService`;
- `StewardshipOwnerSandboxRuntimeRunnerService`;
- AP-739 as the cockpit authority;
- AP-759 as the execution authority.

It must not create another cockpit, another product-mode surface, another owner
runtime, another Dev/Forge dispatcher or another command runner.

## Cockpit Fields

`atlas.software_company.product_mode_cockpit.v1` includes:

```text
source_ap_contracts[] includes AP-759
health.owner_sandbox_runtime_runner_status
owner_sandbox_runtime_runner
counters.owner_sandbox_runtime_runs
counters.owner_sandbox_runtime_planned_runs
counters.owner_sandbox_runtime_ready_results
counters.owner_sandbox_runtime_recorded_runs
counters.owner_sandbox_runtime_blocked
counters.owner_sandbox_runtime_changed_files
review_queue[] item where source_ap=AP-759
operator_controls.owner_sandbox_runtime_plan_command
operator_controls.owner_sandbox_runtime_execute_command
operator_controls.owner_sandbox_runtime_record_command
claim_policy.owner_sandbox_runtime_runner_executed_by_cockpit=false
```

## Review Item

AP-760 review item:

```text
schema_version: atlas.software_company.product_mode_cockpit.review_item.v1
source_ap: AP-759
kind: owner_sandbox_runtime_runner
id: owner_sandbox_run_id
target_area: area_id
target_owner: atlas_dev | forge
decision_anchor:
  owner_sandbox_run_id
  owner_execution_id
  command_hash
  command_display
  requires_provider_authority
  worktree_path_hash
  ap750_owner_result_id
irreversible_action_allowed: false
autoimplementation_allowed: false
```

Recommended operator actions:

| AP-759 status | Product Mode recommendation |
|---|---|
| `owner_runtime_command_planned` | `review_ap759_owner_runtime_command_plan_before_execute` |
| `ready_for_ap750_result_bridge` | `feed_ap759_owner_result_into_ap750_before_merge_deploy_or_followup` |
| `owner_sandbox_runtime_run_recorded` | `feed_ap759_owner_result_into_ap750_before_merge_deploy_or_followup` |
| `blocked` | `resolve_ap759_owner_sandbox_runtime_blockers_before_execution` |

## Operator Commands

Projection only:

```bash
php artisan atlas:software-company-stewardship owner-sandbox-runtime-run \
  --execution-file=<ap758.jsonl> \
  --owner-execution-id=<owner_execution_id> \
  --runtime-command-receipt-file=<ap759-command-receipt.json> \
  --json
```

Execute:

```bash
php artisan atlas:software-company-stewardship owner-sandbox-runtime-run \
  --execution-file=<ap758.jsonl> \
  --owner-execution-id=<owner_execution_id> \
  --runtime-command-receipt-file=<ap759-command-receipt.json> \
  --execute-owner-command \
  --json
```

Execute and record:

```bash
php artisan atlas:software-company-stewardship owner-sandbox-runtime-run \
  --execution-file=<ap758.jsonl> \
  --owner-execution-id=<owner_execution_id> \
  --runtime-command-receipt-file=<ap759-command-receipt.json> \
  --execute-owner-command \
  --record-owner-run \
  --json
```

## Boundary

AP-760 may:

- read AP-759 reports or injected AP-759 projections;
- expose AP-759 as a cockpit section;
- expose AP-759 counters, health and review items;
- show operator CLI anchors for AP-759;
- require AP-759 evidence before Product Mode completion claims.

AP-760 must not:

- execute AP-759 commands from the cockpit;
- alter AP-759 receipts;
- choose commands for the operator;
- create branch/worktree;
- invoke providers, Dev or Forge;
- merge, deploy, push externally, touch secrets or auto-approve results;
- bypass AP-750 result bridge.

## Acceptance

- Product Mode source AP contracts include AP-759.
- Product Mode emits `owner_sandbox_runtime_runner` with AP-759 schema.
- Review queue includes AP-759 when the AP-759 report is planned, ready,
  recorded or blocked.
- Counters distinguish planned, ready, recorded and blocked AP-759 states.
- Product Mode controls require `owner_sandbox_runtime_run` evidence before
  completion claims.
- Claim policy proves the cockpit does not execute AP-759.
- Focused Product Mode tests pass.
