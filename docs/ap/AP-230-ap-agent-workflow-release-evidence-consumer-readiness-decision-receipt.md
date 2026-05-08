---
title: AP-230 AP Agent Workflow Release Evidence Consumer Readiness Decision Receipt
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionReceiptTest.php
depends_on:
  - AP-229
---

# AP-230 AP Agent Workflow Release Evidence Consumer Readiness Decision Receipt

## Purpose

Create a read-only receipt after AP-229 records the human decision over future
consumer readiness.

This AP does not publish, persist, emit Evidence Ledger events, write the ledger,
create runtime jobs, run dry-runs, or execute a release.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionReceipt`

Schema: `atlas.ap_agent_workflow_release_evidence_consumer_readiness_decision_receipt.v1`

## Statuses

- `consumer_readiness_acceptance_reported`
- `consumer_readiness_returned_for_repair`
- `consumer_readiness_stopped_by_rejection`
- `blocked_by_consumer_readiness_decision_contract`

## Rules

- AP-229 acceptance becomes `consumer_readiness_acceptance_reported`.
- AP-229 change request becomes `consumer_readiness_returned_for_repair`.
- AP-229 rejection becomes `consumer_readiness_stopped_by_rejection`.
- Blocked AP-229 output blocks the receipt.
- Accepted receipt still does not execute.
- The receipt never writes files.
- The receipt never writes the Evidence Ledger.
- The receipt never publishes releases.
- The receipt never emits Evidence Ledger events.
- The receipt never creates runtime jobs.

## Output

The payload includes:

- consumer readiness decision summary
- target surface
- future consumer owner
- payload schema
- replay or rollback plan
- human decision value and reason
- next action
- guardrails

## Human Meaning

This is the stamped receipt for AP-229. It gives a future release or ledger AP a
clean accepted input while keeping execution in a separate reviewed AP.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionReceiptTest.php`

Expected coverage:

- accepted readiness becomes acceptance receipt
- change request returns readiness for repair
- rejection stops readiness flow
- blocked AP-229 blocks receipt
