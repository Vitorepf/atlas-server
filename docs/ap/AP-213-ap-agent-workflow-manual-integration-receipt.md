---
title: AP-213 AP Agent Workflow Manual Integration Receipt
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowManualIntegrationReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowManualIntegrationReceiptTest.php
depends_on:
  - AP-212
---

# AP-213 AP Agent Workflow Manual Integration Receipt

## Purpose

Normalize the evidence that AP agent work was manually integrated after AP-212 marked
the packet ready.

This AP does not integrate anything. It only creates a read-only receipt shape for the
post-integration report.

## Contract

Class: `AtlasApAgentWorkflowManualIntegrationReceipt`

Schema: `atlas.ap_agent_workflow_manual_integration_receipt.v1`

## Required Evidence

- `manually_applied_by_integrator`
- `applied_paths_match_handoff_scope`
- `final_diff_reviewed`
- `final_validation_reran`
- `final_docs_health_checked`
- `no_unrelated_work_included`

Each required key must be boolean `true`.

## Statuses

- `manual_integration_reported`
- `blocked_by_integrator_readiness`
- `blocked_invalid_manual_integration_evidence_shape`
- `manual_integration_evidence_incomplete`

## Rules

- AP-212 must be `ready_for_manual_integration`.
- Missing evidence blocks the receipt.
- False evidence blocks the receipt.
- Wrong evidence types block the receipt.
- Unknown evidence keys block the receipt.
- The receipt never writes files.
- The receipt never runs commands.
- The receipt never persists itself.
- The receipt never merges work.

## Output

The payload includes:

- readiness summary
- manual integration evidence status
- failed evidence keys
- shape errors
- next action
- guardrails

## Human Meaning

This is the final "what actually happened" packet after manual integration. It protects
the Atlas from vague claims like "done" by requiring the operator or principal Codex to
state exactly what was reviewed.

## Implementation Notes

This contract sits above AP-212 and below any future Evidence Ledger event or release
note generator. It does not replace either one.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowManualIntegrationReceiptTest.php`

Expected coverage:

- ready AP-212 plus complete evidence reports integration
- blocked AP-212 blocks receipt
- false evidence returns incomplete
- wrong shape blocks receipt
