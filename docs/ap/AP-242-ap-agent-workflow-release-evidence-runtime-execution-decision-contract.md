---
title: AP-242 AP Agent Workflow Release Evidence Runtime Execution Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionDecisionContractTest.php
depends_on:
  - AP-241
---

# AP-242 AP Agent Workflow Release Evidence Runtime Execution Decision Contract

## Purpose

Normalize the human decision after AP-241 declares runtime execution preflight
ready for review.

This AP records whether runtime execution is accepted, needs changes, or is
rejected. It still does not run the runtime payload.

## Non-Goals

- It does not execute runtime payloads.
- It does not activate runtime behavior.
- It does not run commands.
- It does not publish releases.
- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not create runtime jobs.
- It does not run dry-runs.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionDecisionContract`

Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_decision_contract.v1`

## Decisions

- `accept_runtime_execution`
- `request_runtime_execution_changes`
- `reject_runtime_execution`

Every decision requires a non-empty human reason, including acceptance.

## Statuses

- `runtime_execution_accepted_by_human`
- `runtime_execution_changes_requested_by_human`
- `runtime_execution_rejected_by_human`
- `blocked_by_runtime_execution_preflight`
- `blocked_invalid_runtime_execution_decision`

## Rules

- AP-241 must be `ready_for_runtime_execution_review`.
- Unknown decisions are blocked.
- Missing reasons are blocked.
- Acceptance is only a review outcome, not runtime execution.
- A future receipt AP may report the accepted decision without execution.
- A future runtime AP must own any command, job, release, or ledger write.

## Human Meaning

This AP is the human yes/change/no moment before runtime execution can be
reported. It preserves governance while keeping the execution layer silent.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionDecisionContractTest.php`
