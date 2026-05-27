---
id: AP-765-stewardship-runtime-result-bridge-contract
type: ap_contract
title: AP-765 Stewardship Runtime Result Bridge (Evidence / Product Mode loop closer) Contract
status: active
summary: Closes the first complete 24h Software Company Stewardship cycle. Takes a direct Atlas Dev/Forge/owner execution_result plus the loop ids (finding/spec/handoff/sandbox) and turns it into five reviewable artifacts - a structured evidence pack, a real light operator-review inbox item (via the canonical ProposalInboxEmitter -> AtlasInboxService path), a Product Mode visibility event, a portfolio/area health signal in the AP-751 feed shape, and a final cycle receipt. It composes and reuses canonical owners (Evidence Ledger, inbox, portfolio feed) instead of duplicating AP-750/AP-740. It never invokes Dev/Forge/providers, creates branches/worktrees, mutates repos, merges, deploys, pushes externally, touches secrets, auto-approves or bypasses operator review.
owner: programming
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipRuntimeResultBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeRuntimeResultEventService.php
  - app/Services/Ai/Mobile/ProposalInboxEmitter.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipRuntimeResultBridgeServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeRuntimeResultEventServiceTest.php
  - tests/Feature/Ai/SoftwareCompany/StewardshipRuntimeResultBridgeCommandTest.php
---
# AP-765 Stewardship Runtime Result Bridge Contract

## Summary

AP-765 is the **loop closer** for the Atlas Software Company Stewardship Stack.
After Area Focus finds something, Self-Directed Evolution drafts a spec, the
Branch Sandbox materializes a worktree (AP-756) and Atlas Dev/Forge run the owner
runtime, the result must come back to the operator with confidence. AP-765 takes
the direct runtime result and closes the cycle:

```text
Area Focus finding
-> Self-Directed Evolution spec
-> Branch Sandbox worktree (AP-756)
-> Atlas Dev/Forge owner runtime (AP-758/AP-759)
-> AP-765 runtime result bridge
   -> structured evidence pack
   -> real, light operator-review inbox item (AtlasInboxService via ProposalInboxEmitter)
   -> Product Mode visibility event (cockpit read-model)
   -> portfolio/area health signal (AP-751 feed shape)
   -> final cycle receipt
-> operator reviews and decides (AP-731); no merge/deploy without the operator
```

Atlas Software Company Stewardship Stack is a stack/capability family inside the
Atlas Autonomous Software Company Runtime, **not a new OS**.

## Non-duplication (vs AP-750 / AP-740)

AP-765 deliberately does **not** duplicate existing owners:

- **AP-750 `StewardshipOwnerRuntimeResultBridgeService`** requires the full
  AP-749 owner queue consumption chain and only **projects** evidence/morning
  inbox items. AP-765 closes the cycle from the **direct** execution_result plus
  loop ids, and adds the four things AP-750 does not produce: a structured
  evidence pack, a **real** inbox item, a Product Mode visibility event and a
  final cycle receipt. AP-750 remains the strict, chain-gated bridge; AP-765 is
  the higher-level composition that closes the first complete 24h cycle.
- **Evidence Ledger** — AP-765 records through the canonical
  `AtlasEvidenceLedger` (`LedgerEventType::EvidencePacked`), never a parallel
  ledger.
- **Inbox** — AP-765 creates the backend item through the canonical
  `ProposalInboxEmitter` (which writes via `AtlasInboxService`), never a parallel
  inbox. The list payload stays a **bridge to detail** (ids + counts + detail
  command); heavy detail (changed files, tests) goes to the context bundle /
  evidence pack so the mobile inbox list stays light.
- **Portfolio** — AP-765 emits the exact `owner_runtime_result_portfolio_feed`
  shape that `PortfolioStewardshipHealthModelService` already consumes (AP-751),
  so the integration is **live**, not deferred.

## Input

`StewardshipRuntimeResultBridgeService::project(array $input)`:

| Key | Required | Meaning |
|---|---|---|
| `execution_result` | yes | Atlas Dev/Forge/owner runtime result (also `owner_result`/`result`). Carries `result_status`, `summary`, `changed_files`, `tests`/`tests_run`, `test_results`, `validation_commands`, `risks`, `rollback`, `branch_ref`/`worktree_path`, and irreversible flags (`merge_performed`/`deploy_performed`/`external_push_performed`/`secret_access`/`destructive_change`). |
| `area_id` | no | Defaults `agentic_engineering_os`. |
| `portfolio_id` | no | Defaults `atlas_software_company`. |
| `owner` | no | e.g. `atlas_dev`/`atlas_forge`. |
| `sandbox_id` | no | AP-756 sandbox id. |
| `finding_id`/`spec_id`/`handoff_id` (+`*_ref`) | no | Loop refs. |
| `actor` | no | Operator id for the Evidence Ledger. |
| `irreversible_approval_receipt` | no | `{decision: approve_irreversible_result, operator_actor}` — required only if the result triggered an irreversible flag. |
| `emit_inbox`/`record_evidence`/`record_event`/`record_cycle` | no | Default `false` (projection-only). |

## Output (final cycle receipt)

Schema `atlas.software_company_stewardship.runtime_result_bridge.v1`,
`ap_contract = AP-765`, status `ready_for_operator_review` | `runtime_result_bridge_recorded` | `blocked`:

`result_bridge_id`, `evidence_pack` (schema
`atlas.software_company_stewardship.runtime_result_evidence_pack.v1`) +
`evidence_pack_id` + `evidence_pack_hash` + `evidence_ledger_status`,
`inbox_item` (light plan, schema
`atlas.software_company_stewardship.runtime_result_inbox_item.v1`) +
`inbox_item_id`, `product_mode_event` (schema
`atlas.software_company_stewardship.product_mode_runtime_result_event.v1`) +
`product_mode_event_id` + `product_mode_event_status`, `portfolio_signal`
(schema `atlas.software_company_stewardship.runtime_result_portfolio_feed.v1`) +
`portfolio_signal_id`, `operator_next_step`, `acceptance_options`
(`accept`/`reject`/`defer`/`request_changes`; `accept_executes=false`),
`safety_summary`, `claim_policy`, `result_bridge_hash`, `generated_at`.

### Evidence pack fields

`finding_refs`, `spec_refs`, `handoff_refs`, `branch_refs`, `worktree_refs`,
`changed_files`, `tests_run`, `test_results`, `validation_commands`, `risks`,
`rollback` (rollback/cleanup instruction), `no_auto_merge=true`,
`operator_review_required=true`, `counts`, `pack_id`, `pack_hash`.

## Blocked conditions

- `execution_result_required` — no execution result provided.
- `result_status_invalid` — status not one of completed/failed/blocked/partial.
- `result_evidence_insufficient` — no tests/test_results/validation commands or
  no summary.
- `irreversible_action_without_operator_approval` — merge/deploy/push/secret/
  destructive flag set without an explicit operator approval receipt.

## CLI

```bash
php artisan atlas:software-company-stewardship runtime-result-bridge \
  --area=agentic_engineering_os --execution=<id> --json
# smoke/demo with the built-in canonical fixture:
php artisan atlas:software-company-stewardship runtime-result-bridge --area=agentic_engineering_os --fixture --json
# real result + side effects (still no merge/deploy, no auto-approve):
php artisan atlas:software-company-stewardship runtime-result-bridge \
  --area=agentic_engineering_os --owner=atlas_dev --sandbox-id=<afsb> \
  --finding-id=<aff> --spec-id=<spec> --handoff-id=<afho> --actor=<operator> \
  --result-file=<owner-result.json> \
  --emit-inbox --record-evidence --record-event --record-cycle --json
```

## Consumption contract for the final Runner

The final 24h Runner calls `project()` once Dev/Forge return a result:

```php
$bridge->project([
  'execution_result' => $ownerResult,   // result_status, summary, changed_files,
  //   tests, test_results, validation_commands, risks, rollback,
  //   branch_ref/worktree_path, merge_performed/deploy_performed ...
  'area_id' => 'agentic_engineering_os',
  'owner' => 'atlas_dev',
  'sandbox_id' => $sandboxId,
  'finding_id' => $findingId, 'spec_id' => $specId, 'handoff_id' => $handoffId,
  'actor' => $operator,
  'emit_inbox' => true, 'record_evidence' => true,
  'record_event' => true, 'record_cycle' => true,
]);
// -> result_bridge_id, evidence_pack_id/hash, inbox_item_id,
//    product_mode_event_id, portfolio_signal_id, operator_next_step,
//    acceptance_options, safety_summary
```

The portfolio signal is fed to `PortfolioStewardshipHealthModelService` via
`php artisan atlas:software-company-stewardship portfolio-health --json` (AP-751
intake reads `owner_runtime_result_portfolio_feed`).

## Claim policy

`no_auto_merge`, `no_auto_deploy`, `no_secret_access`, `auto_approval=false`,
`auto_implementation=false`, `provider_invoked_by_bridge=false`,
`dev_or_forge_invoked_by_bridge=false`, `opens_branch=false`,
`mutates_target_repo=false`, `operator_review_required=true`, `is_new_os=false`,
`parallel_runtime_created=false`, `parallel_ledger_created=false`,
`parallel_inbox_created=false`. Accepting an inbox item never executes; execution
stays with the owner runtime under operator decision (AP-731).

## Evidence

- `tests/Unit/.../StewardshipEvolution/StewardshipRuntimeResultBridgeServiceTest.php` (13 tests).
- `tests/Unit/.../ProductMode/ProductModeRuntimeResultEventServiceTest.php` (3 tests).
- `tests/Feature/Ai/SoftwareCompany/StewardshipRuntimeResultBridgeCommandTest.php` (2 tests).
