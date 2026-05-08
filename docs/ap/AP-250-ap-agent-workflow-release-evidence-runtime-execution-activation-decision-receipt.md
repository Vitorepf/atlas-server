---
title: AP-250 AP Agent Workflow Release Evidence Runtime Execution Activation Decision Receipt
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionReceiptTest.php
depends_on:
  - AP-249
---

# AP-250 AP Agent Workflow Release Evidence Runtime Execution Activation Decision Receipt

## Purpose

Create a read-only receipt after AP-249 records the human runtime execution
activation decision.

This AP reports the activation decision outcome for a future runtime activation
AP while keeping the current review layer inert.

## Non-Goals

- It does not activate runtime behavior.
- It does not execute runtime payloads.
- It does not run commands.
- It does not publish releases.
- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not create runtime jobs.
- It does not persist the receipt.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionReceipt`

Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_decision_receipt.v1`

## Statuses

- `runtime_execution_activation_acceptance_reported`
- `runtime_execution_activation_returned_for_repair`
- `runtime_execution_activation_stopped_by_rejection`
- `blocked_by_runtime_execution_activation_decision_contract`

## Rules

- AP-249 acceptance becomes `runtime_execution_activation_acceptance_reported`.
- AP-249 change request becomes `runtime_execution_activation_returned_for_repair`.
- AP-249 rejection becomes `runtime_execution_activation_stopped_by_rejection`.
- Blocked AP-249 output blocks the receipt.
- Accepted receipt still does not activate or execute runtime payloads.
- A future activation AP must own any command, runtime job, release, or ledger write.

## Human Meaning

This AP is the stamped receipt for the human runtime activation decision. It
gives a future activation AP a clean input, but prevents the review layer from
acting.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionReceiptTest.php`
