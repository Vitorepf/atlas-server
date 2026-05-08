---
title: AP-210 AP Agent Workflow Human Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanDecisionContractTest.php
depends_on:
  - AP-209
---

# AP-210 AP Agent Workflow Human Decision Contract

## Purpose

Normalize the human decision after an AP agent workflow review packet without executing,
persisting, merging, or overriding any evidence.

AP-209 prepares the review packet. AP-210 records the decision shape that a human can
make against that packet.

## Contract

Class: `AtlasApAgentWorkflowHumanDecisionContract`

Schema: `atlas.ap_agent_workflow_human_decision_contract.v1`

Allowed decisions:

- `accept`
- `request_changes`
- `reject`

## Statuses

- `accepted_by_human`
- `changes_requested_by_human`
- `rejected_by_human`
- `blocked_invalid_human_decision`
- `blocked_missing_human_reason`
- `blocked_accept_requires_ready_review_packet`

## Rules

- `accept` is valid only when AP-209 status is `ready_for_human_acceptance`.
- `request_changes` requires a non-empty human reason.
- `reject` requires a non-empty human reason.
- Unknown decisions fail closed.
- The contract never auto-merges work.
- The contract never persists the decision.
- The contract never replaces the Evidence Ledger.

## Output

The payload includes:

- source AP review packet summary
- normalized decision value
- optional human reason
- allowed decision list
- next action
- guardrails

## Human Meaning

This is the point where the system stops pretending it can approve itself. A clean
agent workflow can be accepted by a human, but that acceptance is still a handoff to an
integrator, not an automatic merge.

## Implementation Notes

The class depends on AP-209 and keeps all AP-209 execution receipt logic intact. It
adds no new AP workflow acceptance rule; it only governs the human decision after the
review packet exists.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanDecisionContractTest.php`

Expected coverage:

- accepted review packet can be accepted
- repair review packet cannot be accepted
- change requests require a reason
- unknown decisions are blocked
