---
title: AP-272 AP Agent Workflow Release Evidence Runtime Execution Activation Implementation Execution Result Persistence Execution Ledger Write Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionContractTest.php
depends_on:
  - AP-271
---

# AP-272 AP Agent Workflow Release Evidence Runtime Execution Activation Implementation Execution Result Persistence Execution Ledger Write Decision Contract

## Purpose

Normalize the human decision over a ready AP-271 ledger write preflight.

This AP records only the decision shape for a future receipt. It does not write
the Evidence Ledger, emit events, persist result state, create runtime jobs, run
commands, or execute payloads.

## Non-Goals

- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not persist result state.
- It does not execute runtime payloads.
- It does not create runtime jobs.
- It does not bypass a future ledger write receipt or execution AP.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionContract`

Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_contract.v1`

## Allowed Decisions

- `accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write`
- `request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_changes`
- `reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write`

## Statuses

- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_accepted_by_human`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_changes_requested_by_human`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_rejected_by_human`
- `blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision`
- `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight`

## Rules

- AP-271 must be ready for human ledger write review.
- A decision must use one of the allowed values.
- Every allowed decision requires a non-empty reason.
- The AP returns a read-only decision payload for a future receipt AP.
- Durable ledger write remains forbidden in this AP.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionContractTest.php`
