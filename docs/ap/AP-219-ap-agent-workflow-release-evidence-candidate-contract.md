---
title: AP-219 AP Agent Workflow Release Evidence Candidate Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateContractTest.php
depends_on:
  - AP-218
---

# AP-219 AP Agent Workflow Release Evidence Candidate Contract

## Purpose

Create a read-only candidate contract after AP-218, so a future release or Evidence
Ledger AP receives a shaped proposal instead of direct execution.

This AP does not publish, persist, emit Evidence Ledger events, write the ledger,
create runtime jobs, or bypass human review.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceCandidateContract`

Schema: `atlas.ap_agent_workflow_release_evidence_candidate_contract.v1`

## Candidate Kinds

- `evidence_ledger_candidate`
- `release_review_candidate`

## Required Evidence

- `reviewed_release_evidence_handoff_packet`
- `selected_candidate_kind`
- `described_payload_schema`
- `confirmed_append_only_or_release_review`
- `confirmed_human_review_before_execution`
- `confirmed_no_runtime_mutation`

Each required key must be boolean `true`.

## Statuses

- `release_evidence_candidate_ready_for_human_review`
- `blocked_by_release_evidence_handoff_packet`
- `blocked_invalid_release_evidence_candidate_shape`
- `release_evidence_candidate_incomplete`

## Rules

- AP-218 must be `ready_for_release_evidence_owner_review`.
- `candidate_kind` must be an allowed kind.
- `payload_schema` must be declared.
- Missing candidate evidence blocks review.
- False candidate evidence blocks review.
- Wrong evidence types block review.
- Unknown evidence keys block review.
- The contract never writes files.
- The contract never writes the Evidence Ledger.
- The contract never publishes releases.
- The contract never executes commands.

## Output

The payload includes:

- release evidence handoff summary
- candidate evidence status
- candidate kind and payload schema
- failed evidence keys
- shape errors
- next action
- guardrails

## Human Meaning

This is the proposal card, not the button. It lets the next AP review exactly what
would become a release or ledger candidate while preserving human control.

## Implementation Notes

This AP intentionally avoids API, MCP, scheduler, release automation, and Evidence
Ledger integrations. Actual execution must be owned by a future AP.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateContractTest.php`

Expected coverage:

- ready AP-218 plus complete candidate evidence becomes reviewable
- blocked AP-218 blocks candidate creation
- false candidate evidence returns incomplete
- invalid candidate kind or schema blocks review
