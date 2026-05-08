---
title: AP-238 AP Agent Workflow Release Evidence Execution Activation Preflight
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationPreflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationPreflightTest.php
depends_on:
  - AP-237
---

# AP-238 AP Agent Workflow Release Evidence Execution Activation Preflight

## Purpose

Create the read-only preflight that lets a future AP review whether an accepted
execution implementation receipt is ready for activation review.

This AP is the last gate before any future activation surface. It documents the
activation owner, entrypoint, policy receipt source, observability, idempotency,
replay window, and rollback plan without performing activation.

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

Class: `AtlasApAgentWorkflowReleaseEvidenceExecutionActivationPreflight`

Schema: `atlas.ap_agent_workflow_release_evidence_execution_activation_preflight.v1`

## Required Evidence

- `reviewed_execution_implementation_receipt`
- `declared_activation_surface`
- `declared_activation_entrypoint`
- `declared_policy_receipt_source`
- `declared_operator_confirmation_surface`
- `confirmed_execution_receipt_accepted`
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

- `ready_for_execution_activation_review`
- `blocked_by_execution_implementation_decision_receipt`
- `blocked_invalid_execution_activation_preflight_shape`
- `execution_activation_preflight_incomplete`

## Rules

- AP-237 must report `execution_implementation_acceptance_reported`.
- Activation evidence must use the declared boolean and text schema.
- Missing, unknown, or mistyped evidence blocks the preflight.
- Incomplete evidence returns an attention status.
- Ready status still does not execute, activate, publish, or write ledger events.
- A future activation AP must own any command, runtime job, release, or ledger write.

## Human Meaning

This AP answers: "is the accepted implementation ready to be reviewed for
activation?" It gives the next layer a clean checklist while preserving silence
and control.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationPreflightTest.php`
