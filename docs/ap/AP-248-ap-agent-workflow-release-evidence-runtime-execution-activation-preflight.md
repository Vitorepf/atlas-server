---
title: AP-248 AP Agent Workflow Release Evidence Runtime Execution Activation Preflight
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationPreflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationPreflightTest.php
depends_on:
  - AP-247
---

# AP-248 AP Agent Workflow Release Evidence Runtime Execution Activation Preflight

## Purpose

Create the read-only preflight that lets a future AP review whether an accepted
runtime execution implementation receipt is ready for activation review.

This AP documents the activation owner, entrypoint, policy receipt source,
observability, idempotency, replay window, and rollback plan without performing
activation or runtime payload execution.

## Non-Goals

- It does not execute runtime payloads.
- It does not activate runtime behavior.
- It does not run commands.
- It does not publish releases.
- It does not write to the Evidence Ledger.
- It does not emit Evidence Ledger events.
- It does not create runtime jobs.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationPreflight`

Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_preflight.v1`

## Required Evidence

- `reviewed_runtime_execution_implementation_receipt`
- `declared_activation_surface`
- `declared_activation_entrypoint`
- `declared_policy_receipt_source`
- `declared_operator_confirmation_surface`
- `confirmed_runtime_execution_receipt_accepted`
- `confirmed_final_replay_window`
- `confirmed_final_rollback_plan`
- `confirmed_observability_hooks`
- `confirmed_idempotency_key_strategy`
- `confirmed_no_activation_performed`
- `confirmed_no_runtime_job_created`
- `confirmed_no_ledger_write`

## Text Evidence

- `activation_surface`
- `activation_entrypoint`
- `policy_receipt_source`
- `operator_confirmation_surface`
- `owner`
- `replay_window`
- `rollback_plan_ref`
- `observability_hooks`
- `idempotency_key_strategy`

## Statuses

- `ready_for_runtime_execution_activation_review`
- `blocked_by_runtime_execution_implementation_decision_receipt`
- `blocked_invalid_runtime_execution_activation_preflight_shape`
- `runtime_execution_activation_preflight_incomplete`

## Rules

- AP-247 must report `runtime_execution_implementation_acceptance_reported`.
- Evidence must use the declared boolean and text schema.
- Missing, unknown, or mistyped evidence blocks the preflight.
- Ready status still does not execute, activate, publish, or write ledger events.
- A future activation AP owns any command, runtime job, payload, or ledger write.

## Human Meaning

This AP answers whether the accepted runtime implementation is ready to be
reviewed for activation while preserving silence and control.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationPreflightTest.php`
