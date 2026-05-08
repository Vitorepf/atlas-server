---
title: AP-249 AP Agent Workflow Release Evidence Runtime Execution Activation Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionContractTest.php
depends_on:
  - AP-248
---

# AP-249 AP Agent Workflow Release Evidence Runtime Execution Activation Decision Contract

## Purpose

Normalize the human decision over AP-248 runtime execution activation preflight.

This AP records whether the human accepts, requests changes, or rejects the
future runtime execution activation review without activating behavior,
executing runtime payloads, creating jobs, or writing Evidence Ledger events.

## Non-Goals

- It does not activate runtime behavior.
- It does not execute runtime payloads.
- It does not run commands.
- It does not publish releases.
- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not create runtime jobs.
- It does not persist the decision.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionContract`

Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_decision_contract.v1`

## Inputs

- Full AP-248 preflight chain evidence.
- `runtimeExecutionActivationDecision`
- `runtimeExecutionActivationDecisionReason`

## Allowed Decisions

- `accept_runtime_execution_activation`
- `request_runtime_execution_activation_changes`
- `reject_runtime_execution_activation`

## Statuses

- `runtime_execution_activation_accepted_by_human`
- `runtime_execution_activation_changes_requested_by_human`
- `runtime_execution_activation_rejected_by_human`
- `blocked_by_runtime_execution_activation_preflight`
- `blocked_invalid_runtime_execution_activation_decision`

## Rules

- AP-248 must report `ready_for_runtime_execution_activation_review`.
- Human decision must be one of the allowed values.
- Decision reason is required for every value.
- Acceptance authorizes only a future receipt AP to report the decision.
- Acceptance does not activate, execute, persist, enqueue, publish, or write.
- Change requests return to the AP-248 preflight evidence.
- Rejection stops the activation flow until scope is reopened.

## Human Meaning

This AP answers whether the runtime activation preflight is acceptable to a
human reviewer while preserving silence, reversibility, and operational control.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionContractTest.php`
