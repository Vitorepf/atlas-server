---
title: AP-239 AP Agent Workflow Release Evidence Execution Activation Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionContractTest.php
depends_on:
  - AP-238
---

# AP-239 AP Agent Workflow Release Evidence Execution Activation Decision Contract

## Purpose

Normalize the human decision after AP-238 declares a future execution activation
preflight ready for review.

This AP records whether the human accepts, requests changes, or rejects the
activation review. It still does not activate runtime behavior.

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

Class: `AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionContract`

Schema: `atlas.ap_agent_workflow_release_evidence_execution_activation_decision_contract.v1`

## Decisions

- `accept_execution_activation`
- `request_execution_activation_changes`
- `reject_execution_activation`

Every decision requires a non-empty human reason, including acceptance.

## Statuses

- `execution_activation_accepted_by_human`
- `execution_activation_changes_requested_by_human`
- `execution_activation_rejected_by_human`
- `blocked_by_execution_activation_preflight`
- `blocked_invalid_execution_activation_decision`

## Rules

- AP-238 must be `ready_for_execution_activation_review`.
- Unknown decisions are blocked.
- Missing reasons are blocked.
- Acceptance is only a review outcome, not activation authority.
- A future receipt AP may report the accepted decision without activation.
- A future runtime AP must own any command, job, release, or ledger write.

## Human Meaning

This AP is the human yes/change/no moment before an activation receipt can be
created. It keeps the system governable while preserving operational silence.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionContractTest.php`
