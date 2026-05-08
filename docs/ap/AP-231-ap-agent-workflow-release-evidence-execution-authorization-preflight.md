---
title: AP-231 AP Agent Workflow Release Evidence Execution Authorization Preflight
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationPreflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationPreflightTest.php
depends_on:
  - AP-230
---

# AP-231 AP Agent Workflow Release Evidence Execution Authorization Preflight

## Purpose

Create a read-only preflight before any future AP may decide execution
authorization for release or Evidence Ledger work.

This AP consumes AP-230 acceptance, then checks that execution ownership, locked
payload schema, rollback, policy/privacy clearance, and human authorization
requirements are declared.

## Non-Goals

- It does not authorize execution.
- It does not publish a release.
- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not create runtime jobs.
- It does not run dry-runs.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationPreflight`

Schema: `atlas.ap_agent_workflow_release_evidence_execution_authorization_preflight.v1`

## Required Evidence

- `reviewed_consumer_readiness_receipt`
- `confirmed_execution_owner`
- `confirmed_payload_schema_locked`
- `confirmed_replay_or_rollback_ready`
- `confirmed_policy_and_privacy_clearance`
- `confirmed_human_authorization_required`
- `confirmed_no_auto_release`
- `confirmed_no_auto_ledger_write`
- `confirmed_no_runtime_job`

## Statuses

- `ready_for_execution_authorization_decision`
- `blocked_by_consumer_readiness_decision_receipt`
- `blocked_invalid_execution_authorization_preflight_shape`
- `execution_authorization_preflight_incomplete`

## Rules

- AP-230 must be `consumer_readiness_acceptance_reported`.
- Authorization surface, scope, execution owner, payload schema, and rollback
  reference must be declared.
- Human authorization is required before execution.
- No release, ledger write, runtime job, event emission, or dry-run occurs here.

## Human Meaning

This AP is the airlock before execution authorization. It proves the future
decision AP has the minimum inputs to review, while keeping the system inert.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationPreflightTest.php`
