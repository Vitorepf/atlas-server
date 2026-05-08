---
title: AP-257 AP Agent Workflow Release Evidence Runtime Execution Activation Implementation Execution Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionContractTest.php
depends_on:
  - AP-256
---

# AP-257 AP Agent Workflow Release Evidence Runtime Execution Activation Implementation Execution Decision Contract

## Purpose

Normalize the human decision over AP-256 execution preflight.

This AP decides whether the reviewed execution preflight is accepted, returned
for repair, or rejected. It records the decision shape and next action only. It
does not execute payloads, create jobs, activate runtime behavior, persist
decisions, publish releases, emit events, or write to the Evidence Ledger.

## Non-Goals

- It does not execute runtime payloads.
- It does not activate runtime behavior.
- It does not run commands.
- It does not create runtime jobs.
- It does not write to the Evidence Ledger.
- It does not persist the decision.
- It does not bypass a ready AP-256 preflight.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionContract`

Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_contract.v1`

## Allowed Decisions

- `accept_runtime_execution_activation_implementation_execution`
- `request_runtime_execution_activation_implementation_execution_changes`
- `reject_runtime_execution_activation_implementation_execution`

Every allowed decision requires a non-empty human reason.

## Statuses

- `runtime_execution_activation_implementation_execution_accepted_by_human`
- `runtime_execution_activation_implementation_execution_changes_requested_by_human`
- `runtime_execution_activation_implementation_execution_rejected_by_human`
- `blocked_invalid_runtime_execution_activation_implementation_execution_decision`
- `blocked_by_runtime_execution_activation_implementation_execution_preflight`

## Rules

- AP-256 must be ready before acceptance can pass.
- Unknown decision values are blocked.
- Empty reasons are blocked.
- Acceptance only authorizes a future receipt AP to report the decision.
- This AP never executes, persists, enqueues, publishes, activates, or writes.

## Human Meaning

This AP is the deliberate stop before any dangerous runtime action. It turns the
operator decision into a governed receipt input, while keeping the system still.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionContractTest.php`
