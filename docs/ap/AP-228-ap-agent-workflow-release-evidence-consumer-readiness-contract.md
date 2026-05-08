---
title: AP-228 AP Agent Workflow Release Evidence Consumer Readiness Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessContractTest.php
depends_on:
  - AP-227
---

# AP-228 AP Agent Workflow Release Evidence Consumer Readiness Contract

## Purpose

Create a read-only readiness contract for the future AP that may consume the
accepted post-dry-run handoff.

This AP does not publish, persist, emit Evidence Ledger events, write the ledger,
create runtime jobs, run dry-runs, or execute a release.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessContract`

Schema: `atlas.ap_agent_workflow_release_evidence_consumer_readiness_contract.v1`

## Required Evidence

- `reviewed_post_dry_run_handoff`
- `confirmed_future_consumer_owner`
- `confirmed_payload_schema_final`
- `confirmed_policy_and_privacy_review`
- `confirmed_replay_or_rollback_plan`
- `confirmed_no_auto_release`
- `confirmed_no_auto_ledger_write`
- `confirmed_no_runtime_job`

Each required key must be boolean `true`.

## Statuses

- `consumer_readiness_ready_for_future_release_or_ledger_decision`
- `blocked_by_post_dry_run_handoff_packet`
- `blocked_invalid_consumer_readiness_shape`
- `consumer_readiness_evidence_incomplete`

## Rules

- AP-227 must be `post_dry_run_handoff_ready_for_future_release_or_ledger_ap`.
- Future consumer owner must be declared.
- Payload schema must be final.
- Policy and privacy review must be confirmed.
- Replay or rollback plan must be declared.
- No release is automatically published.
- No Evidence Ledger write is performed.
- No runtime job is created.

## Output

The payload includes:

- post-dry-run handoff summary
- consumer readiness evidence status
- target surface
- future consumer owner
- payload schema
- replay or rollback plan
- guardrails

## Human Meaning

This is the readiness gate for the next owner. It proves the future release or
ledger AP has enough context to decide safely, without letting this contract act.

## Implementation Notes

This AP intentionally avoids API, MCP, scheduler, release automation, and Evidence
Ledger integrations. A future AP must own any release or ledger decision.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessContractTest.php`

Expected coverage:

- ready AP-227 plus complete consumer readiness becomes ready
- blocked AP-227 blocks consumer readiness
- false readiness evidence returns incomplete
- invalid readiness shape blocks review
