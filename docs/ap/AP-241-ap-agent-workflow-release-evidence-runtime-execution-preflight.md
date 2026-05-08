---
title: AP-241 AP Agent Workflow Release Evidence Runtime Execution Preflight
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionPreflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionPreflightTest.php
depends_on:
  - AP-240
---

# AP-241 AP Agent Workflow Release Evidence Runtime Execution Preflight

## Purpose

Create the read-only preflight for a future runtime execution AP after AP-240
reports accepted activation.

This AP verifies that runtime execution has an owner, entrypoint, payload
schema, policy receipt, operator confirmation, replay window, rollback plan,
observability hooks, and idempotency strategy before any future execution layer
can review it.

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

Class: `AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionPreflight`

Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_preflight.v1`

## Required Evidence

- `reviewed_execution_activation_receipt`
- `declared_runtime_surface`
- `declared_runtime_entrypoint`
- `declared_runtime_owner`
- `declared_execution_payload_schema`
- `confirmed_activation_receipt_accepted`
- `confirmed_policy_receipt_attached`
- `confirmed_operator_confirmation_required`
- `confirmed_idempotency_key_ready`
- `confirmed_observability_ready`
- `confirmed_replay_window_ready`
- `confirmed_rollback_plan_ready`
- `confirmed_no_runtime_execution_performed`
- `confirmed_no_runtime_job_created`
- `confirmed_no_ledger_write`

## Text Evidence

- `runtime_surface`
- `runtime_entrypoint`
- `runtime_owner`
- `execution_payload_schema`
- `policy_receipt_source`
- `operator_confirmation_surface`
- `replay_window`
- `rollback_plan_ref`
- `observability_hooks`
- `idempotency_key_strategy`

## Statuses

- `ready_for_runtime_execution_review`
- `blocked_by_execution_activation_decision_receipt`
- `blocked_invalid_runtime_execution_preflight_shape`
- `runtime_execution_preflight_incomplete`

## Rules

- AP-240 must report `execution_activation_acceptance_reported`.
- Runtime evidence must use the declared boolean and text schema.
- Missing, unknown, or mistyped evidence blocks the preflight.
- Ready status still does not execute, activate, publish, or write ledger events.
- A future runtime execution AP must own any command, job, release, or ledger write.

## Human Meaning

This AP asks whether the accepted activation is packaged safely enough for a
future runtime execution review. It keeps the system silent until the execution
layer has its own explicit contract.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionPreflightTest.php`
