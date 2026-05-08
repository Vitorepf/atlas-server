---
title: AP-236 AP Agent Workflow Release Evidence Execution Implementation Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionContractTest.php
depends_on:
  - AP-235
---

# AP-236 AP Agent Workflow Release Evidence Execution Implementation Decision Contract

## Purpose

Normalize the human decision over AP-235 execution implementation preflight.

This AP decides whether the future execution implementation preflight is
accepted, needs repair, or is rejected. It still does not execute the authorized
work.

## Non-Goals

- It does not execute authorized work.
- It does not run commands.
- It does not publish releases.
- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not create runtime jobs.
- It does not run dry-runs.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionContract`

Schema: `atlas.ap_agent_workflow_release_evidence_execution_implementation_decision_contract.v1`

## Allowed Decisions

- `accept_execution_implementation`
- `request_execution_implementation_changes`
- `reject_execution_implementation`

## Statuses

- `execution_implementation_accepted_by_human`
- `execution_implementation_changes_requested_by_human`
- `execution_implementation_rejected_by_human`
- `blocked_by_execution_implementation_preflight`
- `blocked_invalid_execution_implementation_decision`

## Rules

- Every decision requires a human reason.
- Acceptance requires AP-235 status `ready_for_execution_implementation_review`.
- Change requests route back to AP-235 repair.
- Rejection stops the implementation path until the scope reopens.
- Acceptance only allows a future receipt AP to report the decision.

## Human Meaning

This AP is the human sign-off over the execution implementation map. It approves
the plan shape, not the act of running it.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionContractTest.php`
