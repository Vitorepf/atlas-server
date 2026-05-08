---
title: AP-212 AP Agent Workflow Integrator Readiness Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorReadinessContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorReadinessContractTest.php
depends_on:
  - AP-211
---

# AP-212 AP Agent Workflow Integrator Readiness Contract

## Purpose

Require explicit integrator evidence before AP agent work can be called ready for
manual integration.

AP-211 hands accepted work to the integrator. AP-212 checks whether the integrator has
reviewed the necessary facts.

## Contract

Class: `AtlasApAgentWorkflowIntegratorReadinessContract`

Schema: `atlas.ap_agent_workflow_integrator_readiness_contract.v1`

## Required Evidence

- `reviewed_handoff_packet`
- `reviewed_diff_scope`
- `reviewed_validation_output`
- `confirmed_no_hot_file_conflict`
- `confirmed_no_unrelated_reverts`
- `confirmed_manual_integration_owner`

Each required key must be boolean `true`.

## Statuses

- `ready_for_manual_integration`
- `blocked_by_integrator_handoff`
- `blocked_invalid_integrator_evidence_shape`
- `integrator_review_incomplete`

## Rules

- AP-211 must be `ready_for_integrator_review`.
- Missing evidence blocks readiness.
- False evidence blocks readiness.
- Wrong evidence types block readiness.
- Unknown evidence keys block readiness.
- The contract never writes files.
- The contract never runs commands.
- The contract never persists readiness.
- The contract never auto-merges work.

## Output

The payload includes:

- handoff summary
- integrator evidence status
- failed evidence keys
- shape errors
- next action
- guardrails

## Human Meaning

This is the "look again before touching the real system" checkpoint. It lets a human
or principal Codex see exactly why accepted work is or is not ready for manual
integration.

## Implementation Notes

This AP does not add a new execution surface. It is a read-only contract layered above
AP-211 and below any future manual integration command or UI.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorReadinessContractTest.php`

Expected coverage:

- ready handoff plus complete evidence returns ready
- blocked handoff blocks readiness
- false evidence returns incomplete
- wrong shape blocks readiness
