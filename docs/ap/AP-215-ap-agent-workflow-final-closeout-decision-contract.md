---
title: AP-215 AP Agent Workflow Final Closeout Decision Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalCloseoutDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalCloseoutDecisionContractTest.php
depends_on:
  - AP-214
---

# AP-215 AP Agent Workflow Final Closeout Decision Contract

## Purpose

Normalize the final human closeout decision after AP-214 produces a ready final audit
packet.

This AP does not publish, persist, release, or close work automatically. It only
records the allowed decision shape.

## Contract

Class: `AtlasApAgentWorkflowFinalCloseoutDecisionContract`

Schema: `atlas.ap_agent_workflow_final_closeout_decision_contract.v1`

Allowed decisions:

- `accept_closeout`
- `request_closeout_changes`
- `reject_closeout`

## Statuses

- `closeout_accepted_by_human`
- `closeout_changes_requested_by_human`
- `closeout_rejected_by_human`
- `blocked_invalid_closeout_decision`
- `blocked_missing_closeout_reason`
- `blocked_accept_requires_ready_final_audit_packet`

## Rules

- `accept_closeout` is valid only when AP-214 is `ready_for_final_human_closeout_review`.
- `request_closeout_changes` requires a non-empty reason.
- `reject_closeout` requires a non-empty reason.
- Unknown decisions fail closed.
- The contract never writes files.
- The contract never runs commands.
- The contract never publishes a release.
- The contract never persists the decision.
- The contract never auto-closes work.

## Output

The payload includes:

- final audit packet summary
- normalized closeout decision
- optional closeout reason
- allowed decision list
- next action
- guardrails

## Human Meaning

This is the last human yes/no/more-work checkpoint before any future release, Evidence
Ledger, or closeout mechanism. It keeps approval separate from publishing.

## Implementation Notes

This AP intentionally avoids API, MCP, scheduler, release, and Evidence Ledger
integration. Those surfaces need their own AP because they execute or persist.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalCloseoutDecisionContractTest.php`

Expected coverage:

- ready AP-214 can be accepted
- non-ready AP-214 cannot be accepted
- change requests require a reason
- unknown closeout decisions are blocked
