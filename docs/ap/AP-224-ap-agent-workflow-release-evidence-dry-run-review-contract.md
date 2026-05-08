---
title: AP-224 AP Agent Workflow Release Evidence Dry Run Review Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContractTest.php
depends_on:
  - AP-223
---

# AP-224 AP Agent Workflow Release Evidence Dry Run Review Contract

## Purpose

Create a human review gate for the AP-223 dry-run plan before any future AP may
run a dry-run or prepare release Evidence Ledger execution.

This AP does not run the dry-run, publish, persist, emit Evidence Ledger events,
write the ledger, create runtime jobs, or execute a release.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContract`

Schema: `atlas.ap_agent_workflow_release_evidence_dry_run_review_contract.v1`

## Decisions

- `accept_dry_run_plan`
- `request_dry_run_plan_changes`
- `reject_dry_run_plan`

Change requests and rejections require a human reason.

## Statuses

- `dry_run_plan_accepted_by_human`
- `dry_run_plan_changes_requested_by_human`
- `dry_run_plan_rejected_by_human`
- `blocked_by_dry_run_plan_contract`
- `blocked_invalid_dry_run_review_decision`

## Rules

- AP-223 must be `ready_for_future_dry_run_review`.
- A human may accept the plan for future dry-run execution APs.
- A human may request plan changes with a reason.
- A human may reject the dry-run path with a reason.
- Invalid decision values block review.
- Missing reason blocks change requests and rejections.
- Acceptance never runs the dry-run by itself.
- Acceptance never writes the Evidence Ledger.
- Acceptance never emits Evidence Ledger events.

## Output

The payload includes:

- dry-run plan summary
- review decision
- reason when present
- next action
- guardrails

## Human Meaning

This is the sign-off on the rehearsal script. It confirms whether the planned
simulation is worth running, while keeping the actual execution owned by a later
runtime AP.

## Implementation Notes

This AP intentionally avoids API, MCP, scheduler, release automation, and Evidence
Ledger integrations. A future AP must own dry-run execution.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContractTest.php`

Expected coverage:

- ready dry-run plan can be accepted by a human
- change request with reason routes back to plan repair
- missing rejection reason blocks review
- incomplete AP-223 plan blocks review
