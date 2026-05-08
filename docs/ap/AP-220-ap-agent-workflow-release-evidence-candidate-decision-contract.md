---
title: AP-220 AP Agent Workflow Release Evidence Candidate Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContractTest.php
depends_on:
  - AP-219
---

# AP-220 AP Agent Workflow Release Evidence Candidate Decision Contract

## Purpose

Record the human decision for an AP-219 release or Evidence Ledger candidate.

This AP still does not publish, persist, emit Evidence Ledger events, write the
ledger, create runtime jobs, or execute a release.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract`

Schema: `atlas.ap_agent_workflow_release_evidence_candidate_decision_contract.v1`

## Decisions

- `accept_candidate`
- `request_candidate_changes`
- `reject_candidate`

Change requests and rejections require a reason.

## Statuses

- `release_evidence_candidate_accepted_by_human`
- `release_evidence_candidate_changes_requested_by_human`
- `release_evidence_candidate_rejected_by_human`
- `blocked_by_release_evidence_candidate_contract`
- `blocked_invalid_candidate_decision`

## Rules

- AP-219 must be `release_evidence_candidate_ready_for_human_review`.
- Unknown decision values block the contract.
- Change requests require reason.
- Rejections require reason.
- Accepted candidates still do not execute.
- The decision never writes files.
- The decision never writes the Evidence Ledger.
- The decision never publishes releases.
- The decision never emits Evidence Ledger events.
- The decision never creates runtime jobs.

## Output

The payload includes:

- release evidence candidate summary
- candidate kind and payload schema
- human candidate decision
- decision validation errors
- next action
- guardrails

## Human Meaning

This is the explicit human yes/no/change gate before any future AP may consume a
release or ledger candidate. It keeps the candidate reviewable without turning it
into operational execution.

## Implementation Notes

This AP intentionally avoids API, MCP, scheduler, release automation, and Evidence
Ledger integrations. A future AP may consume only accepted decisions.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContractTest.php`

Expected coverage:

- accept works only for ready candidate
- request changes requires reason
- rejection requires reason
- blocked AP-219 blocks acceptance
