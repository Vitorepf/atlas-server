---
title: AP-246 AP Agent Workflow Release Evidence Runtime Execution Implementation Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionImplementationDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionImplementationDecisionContractTest.php
depends_on:
  - AP-245
---

# AP-246 AP Agent Workflow Release Evidence Runtime Execution Implementation Decision Contract

## Purpose

Normalize the human decision over AP-245 runtime execution implementation preflight.

This AP decides whether the future runtime execution implementation preflight is
accepted, needs repair, or is rejected. It still does not execute runtime
payloads.

## Non-Goals

- It does not execute runtime payloads.
- It does not run commands.
- It does not publish releases.
- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not create runtime jobs.
- It does not activate implementation paths.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionImplementationDecisionContract`

Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_implementation_decision_contract.v1`

## Allowed Decisions

- `accept_runtime_execution_implementation`
- `request_runtime_execution_implementation_changes`
- `reject_runtime_execution_implementation`

## Statuses

- `runtime_execution_implementation_accepted_by_human`
- `runtime_execution_implementation_changes_requested_by_human`
- `runtime_execution_implementation_rejected_by_human`
- `blocked_by_runtime_execution_implementation_preflight`
- `blocked_invalid_runtime_execution_implementation_decision`

## Rules

- Every decision requires a human reason.
- Acceptance requires AP-245 status `ready_for_runtime_execution_implementation_review`.
- Change requests route back to AP-245 repair.
- Rejection stops the implementation path until the scope reopens.
- Acceptance only allows a future receipt AP to report the decision.

## Human Meaning

This AP is the human sign-off over the runtime execution implementation map. It
approves the plan shape, not the act of running payloads.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionImplementationDecisionContractTest.php`
