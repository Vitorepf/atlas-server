---
title: AP-273 AP Agent Workflow Release Evidence Runtime Execution Activation Implementation Execution Result Persistence Execution Ledger Write Decision Receipt
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceiptTest.php
depends_on:
  - AP-272
---

# AP-273 AP Agent Workflow Release Evidence Runtime Execution Activation Implementation Execution Result Persistence Execution Ledger Write Decision Receipt

## Purpose

Report the human decision produced by AP-272 as a read-only receipt.

This AP preserves the acceptance, repair, or rejection outcome for the future
ledger write execution path. It does not write the Evidence Ledger, emit events,
persist result state, create runtime jobs, run commands, or execute payloads.

## Non-Goals

- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not persist result state.
- It does not execute runtime payloads.
- It does not create runtime jobs.
- It does not replace a future ledger write execution AP.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceipt`

Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_receipt.v1`

## Statuses

- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_acceptance_reported`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_returned_for_repair`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_stopped_by_rejection`
- `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_contract`

## Summary Fields

- AP-272 schema, status, decision, and reason
- execution surface and idempotency key strategy
- operator confirmation surface and rollback plan ref
- payload hash and ledger write plan ref
- owner and policy receipt source

## Rules

- AP-272 must have accepted, requested changes, or rejected the ledger write plan.
- Acceptance becomes a reported acceptance, not a ledger write.
- Changes requested return the ledger write preflight path to repair.
- Rejection stops the ledger write path until scope reopens.
- Any blocked AP-272 payload remains blocked by the decision contract.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceiptTest.php`
