---
title: AP-221 AP Agent Workflow Release Evidence Candidate Decision Receipt
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceiptTest.php
depends_on:
  - AP-220
---

# AP-221 AP Agent Workflow Release Evidence Candidate Decision Receipt

## Purpose

Create a read-only receipt after AP-220 records the human decision for a release or
Evidence Ledger candidate.

This AP does not publish, persist, emit Evidence Ledger events, write the ledger,
create runtime jobs, or execute a release.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt`

Schema: `atlas.ap_agent_workflow_release_evidence_candidate_decision_receipt.v1`

## Statuses

- `release_evidence_candidate_acceptance_reported`
- `release_evidence_candidate_returned_for_repair`
- `release_evidence_candidate_stopped_by_rejection`
- `blocked_by_candidate_decision_contract`

## Rules

- AP-220 acceptance becomes `release_evidence_candidate_acceptance_reported`.
- AP-220 change request becomes `release_evidence_candidate_returned_for_repair`.
- AP-220 rejection becomes `release_evidence_candidate_stopped_by_rejection`.
- Blocked AP-220 output blocks the receipt.
- Accepted receipt still does not execute.
- The receipt never writes files.
- The receipt never writes the Evidence Ledger.
- The receipt never publishes releases.
- The receipt never emits Evidence Ledger events.
- The receipt never creates runtime jobs.

## Output

The payload includes:

- candidate decision summary
- candidate kind and payload schema
- human decision value and reason
- next action
- guardrails

## Human Meaning

This is the stamped receipt saying what happened to the candidate. It gives a future
release or ledger AP a clean input while keeping execution separate.

## Implementation Notes

This AP intentionally avoids API, MCP, scheduler, release automation, and Evidence
Ledger integrations. A future execution AP may consume only accepted receipts.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceiptTest.php`

Expected coverage:

- accepted candidate becomes acceptance receipt
- change request returns candidate for repair
- rejection stops candidate flow
- blocked AP-220 blocks receipt
