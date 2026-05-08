---
title: AP-240 AP Agent Workflow Release Evidence Execution Activation Decision Receipt
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionReceiptTest.php
depends_on:
  - AP-239
---

# AP-240 AP Agent Workflow Release Evidence Execution Activation Decision Receipt

## Purpose

Create a read-only receipt after AP-239 records the human execution activation
decision.

This AP reports the activation decision outcome for a future runtime AP while
keeping the current layer inert.

## Non-Goals

- It does not execute authorized work.
- It does not activate runtime behavior.
- It does not run commands.
- It does not publish releases.
- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not create runtime jobs.
- It does not run dry-runs.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionReceipt`

Schema: `atlas.ap_agent_workflow_release_evidence_execution_activation_decision_receipt.v1`

## Statuses

- `execution_activation_acceptance_reported`
- `execution_activation_returned_for_repair`
- `execution_activation_stopped_by_rejection`
- `blocked_by_execution_activation_decision_contract`

## Rules

- AP-239 acceptance becomes `execution_activation_acceptance_reported`.
- AP-239 change request becomes `execution_activation_returned_for_repair`.
- AP-239 rejection becomes `execution_activation_stopped_by_rejection`.
- Blocked AP-239 output blocks the receipt.
- Accepted receipt still does not activate or execute anything.
- A future runtime AP must own any command, runtime job, release, or ledger write.

## Human Meaning

This AP is the stamped receipt for the human activation decision. It gives a
future runtime AP a clean input, but prevents the review layer from acting.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionReceiptTest.php`
