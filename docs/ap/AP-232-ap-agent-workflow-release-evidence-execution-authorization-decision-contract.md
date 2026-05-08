---
title: AP-232 AP Agent Workflow Release Evidence Execution Authorization Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionContractTest.php
depends_on:
  - AP-231
---

# AP-232 AP Agent Workflow Release Evidence Execution Authorization Decision Contract

## Purpose

Normalize the human decision over AP-231 execution authorization preflight.

This AP may record that a human approved authorization, requested changes, or
rejected authorization. It still does not execute release or Evidence Ledger work.

## Non-Goals

- It does not execute authorized work.
- It does not publish releases.
- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not create runtime jobs.
- It does not run dry-runs.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionContract`

Schema: `atlas.ap_agent_workflow_release_evidence_execution_authorization_decision_contract.v1`

## Decisions

- `authorize_execution`
- `request_execution_authorization_changes`
- `reject_execution_authorization`

All decisions require a reason. Authorization without a reason is blocked.

## Statuses

- `execution_authorization_approved_by_human`
- `execution_authorization_changes_requested_by_human`
- `execution_authorization_rejected_by_human`
- `blocked_by_execution_authorization_preflight`
- `blocked_invalid_execution_authorization_decision`

## Rules

- AP-231 must be `ready_for_execution_authorization_decision`.
- Decision value must be one of the allowed decisions.
- Decision reason is always required.
- Approval still does not execute anything.
- A future receipt AP must record the approved authorization before any execution AP.

## Human Meaning

This AP separates human authorization from machine execution. The Atlas can now
know that execution was approved, but it still cannot act from this contract.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionContractTest.php`
