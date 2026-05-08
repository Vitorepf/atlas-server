---
title: AP-216 AP Agent Workflow Closeout Acceptance Receipt
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowCloseoutAcceptanceReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowCloseoutAcceptanceReceiptTest.php
depends_on:
  - AP-215
---

# AP-216 AP Agent Workflow Closeout Acceptance Receipt

## Purpose

Create a read-only receipt after AP-215 reports the human closeout decision.

This AP is the handoff point for future release or Evidence Ledger work, but it does
not publish, persist, emit events, or close work by itself.

## Contract

Class: `AtlasApAgentWorkflowCloseoutAcceptanceReceipt`

Schema: `atlas.ap_agent_workflow_closeout_acceptance_receipt.v1`

## Statuses

- `closeout_acceptance_reported`
- `closeout_returned_for_repair`
- `closeout_stopped_by_rejection`
- `blocked_by_closeout_decision_contract`

## Rules

- AP-215 `closeout_accepted_by_human` becomes `closeout_acceptance_reported`.
- AP-215 change request becomes `closeout_returned_for_repair`.
- AP-215 rejection becomes `closeout_stopped_by_rejection`.
- Any blocked AP-215 decision becomes `blocked_by_closeout_decision_contract`.
- The receipt never writes files.
- The receipt never runs commands.
- The receipt never publishes a release.
- The receipt never emits an Evidence Ledger event.
- The receipt never persists itself.

## Output

The payload includes:

- closeout decision summary
- final audit packet status
- manual integration receipt status
- next action
- guardrails

## Human Meaning

This is the final "accepted, but not yet published" receipt. It gives future execution
layers a clean input while preserving the rule that read-only contracts never mutate
the Atlas.

## Implementation Notes

This AP intentionally does not touch API, MCP, scheduler, release automation, or
Evidence Ledger. Those are separate implementation surfaces with higher blast radius.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowCloseoutAcceptanceReceiptTest.php`

Expected coverage:

- accepted closeout becomes a receipt
- requested changes return to repair
- rejected closeout stops the flow
- blocked AP-215 blocks receipt creation
