---
title: AP-229 AP Agent Workflow Release Evidence Consumer Readiness Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContractTest.php
depends_on:
  - AP-228
---

# AP-229 AP Agent Workflow Release Evidence Consumer Readiness Decision Contract

## Status
- Status: implemented
- Owner: Atlas Kernel / Architecture
- Schema: `atlas.ap_agent_workflow_release_evidence_consumer_readiness_decision_contract.v1`
- Depends on: AP-228

## Purpose
AP-229 normalizes the human decision over AP-228 consumer readiness before any future
release or Evidence Ledger execution AP may consume that readiness. It is the review
gate after the future consumer owner, payload schema, policy/privacy review, and
replay/rollback plan have been declared.

## Non-Goals
- It does not publish a release.
- It does not write to the Evidence Ledger.
- It does not emit runtime evidence events.
- It does not run a dry-run or create a runtime job.
- It does not bypass AP-228 readiness.

## Contract
The service is
`AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContract`.

It calls AP-228 first, then accepts one of three human decisions:
- `accept_consumer_readiness`
- `request_consumer_readiness_changes`
- `reject_consumer_readiness`

Requests for changes and rejections require a reason. Invalid decision values or
missing reasons return `blocked_invalid_consumer_readiness_decision`.

## Output States
- `consumer_readiness_accepted_by_human`
- `consumer_readiness_changes_requested_by_human`
- `consumer_readiness_rejected_by_human`
- `blocked_by_consumer_readiness_contract`
- `blocked_invalid_consumer_readiness_decision`

## Guardrails
The output explicitly marks these operations as false:
- `writes_files`
- `executes_commands`
- `persists_decision`
- `publishes_release`
- `emits_evidence_event`
- `writes_evidence_ledger`
- `creates_runtime_job`
- `runs_dry_run`
- `accepts_without_ready_consumer_readiness`

## Workflow Placement
AP-229 follows AP-228 in the post-completion review chain.

AP-228 answers: "Is the future consumer ready?"
AP-229 answers: "Did the human accept, reject, or request changes to that readiness?"

An accepted AP-229 result may be consumed by a future AP that designs actual
release or Evidence Ledger execution authorization. That future AP must still be
separate and reviewed.

## Related Paths
- `app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContract.php`
- `tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContractTest.php`
