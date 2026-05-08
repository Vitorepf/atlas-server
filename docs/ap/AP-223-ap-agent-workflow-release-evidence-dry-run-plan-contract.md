---
title: AP-223 AP Agent Workflow Release Evidence Dry Run Plan Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunPlanContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunPlanContractTest.php
depends_on:
  - AP-222
---

# AP-223 AP Agent Workflow Release Evidence Dry Run Plan Contract

## Purpose

Create a read-only dry-run plan after AP-222 says a release or Evidence Ledger
candidate is ready for future execution review.

This AP does not run the dry-run, publish, persist, emit Evidence Ledger events,
write the ledger, create runtime jobs, or execute a release.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceDryRunPlanContract`

Schema: `atlas.ap_agent_workflow_release_evidence_dry_run_plan_contract.v1`

## Required Evidence

- `reviewed_execution_readiness`
- `declared_simulation_scope`
- `declared_fixture_or_corpus`
- `declared_success_criteria`
- `declared_failure_criteria`
- `confirmed_no_real_mutation`

Each required key must be boolean `true`.

## Statuses

- `ready_for_future_dry_run_review`
- `blocked_by_execution_readiness`
- `blocked_invalid_dry_run_plan_shape`
- `release_evidence_dry_run_plan_incomplete`

## Rules

- AP-222 must be `ready_for_future_release_or_ledger_execution_ap`.
- The plan must declare simulation scope.
- The plan must declare fixture or corpus.
- The plan must declare success criteria.
- The plan must declare failure criteria.
- Missing dry-run evidence blocks review.
- False dry-run evidence blocks review.
- The contract never runs a dry-run.
- The contract never writes the Evidence Ledger.
- The contract never emits Evidence Ledger events.

## Output

The payload includes:

- execution readiness summary
- dry-run evidence status
- simulation scope
- fixture or corpus
- success and failure criteria
- next action
- guardrails

## Human Meaning

This is the rehearsal script, not the rehearsal. It makes the future runtime AP prove
what it will simulate before any real mutation is even considered.

## Implementation Notes

This AP intentionally avoids API, MCP, scheduler, release automation, and Evidence
Ledger integrations. A future AP must own dry-run execution.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunPlanContractTest.php`

Expected coverage:

- ready AP-222 plus complete dry-run evidence becomes reviewable
- blocked AP-222 blocks dry-run planning
- false dry-run evidence returns incomplete
- invalid plan shape blocks review
