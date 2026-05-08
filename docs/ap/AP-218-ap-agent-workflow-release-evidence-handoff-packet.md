---
title: AP-218 AP Agent Workflow Release Evidence Handoff Packet
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceHandoffPacket.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceHandoffPacketTest.php
depends_on:
  - AP-217
---

# AP-218 AP Agent Workflow Release Evidence Handoff Packet

## Purpose

Create a read-only handoff packet for the future owner of release or Evidence Ledger
work after AP-217 says the closeout is ready to be consumed.

This AP does not publish, persist, emit Evidence Ledger events, write the ledger,
create release surfaces, or execute runtime side effects.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidenceHandoffPacket`

Schema: `atlas.ap_agent_workflow_release_evidence_handoff_packet.v1`

## Required Evidence

- `reviewed_release_evidence_preflight`
- `confirmed_future_ap_owner`
- `confirmed_no_runtime_side_effect`
- `confirmed_no_direct_release_execution`
- `confirmed_no_direct_ledger_write`
- `confirmed_followup_ap_required`

Each required key must be boolean `true`.

## Statuses

- `ready_for_release_evidence_owner_review`
- `blocked_by_release_evidence_preflight`
- `blocked_invalid_release_evidence_handoff_shape`
- `release_evidence_handoff_incomplete`

## Rules

- AP-217 must be `ready_for_future_release_or_evidence_layer`.
- Missing handoff evidence blocks owner review.
- False handoff evidence blocks owner review.
- Wrong evidence types block owner review.
- Unknown evidence keys block owner review.
- The packet never writes files.
- The packet never runs commands.
- The packet never publishes a release.
- The packet never emits Evidence Ledger events.
- The packet never writes the Evidence Ledger.
- The packet never creates a release surface.

## Output

The payload includes:

- release evidence preflight summary
- handoff evidence status
- future AP and owner hints
- failed evidence keys
- shape errors
- next action
- guardrails

## Human Meaning

This is the sealed envelope handed to the next owner. It says the work can be
reviewed for a future AP, while making it explicit that no release or ledger write
has happened yet.

## Implementation Notes

This AP intentionally avoids API, MCP, scheduler, release automation, and Evidence
Ledger integrations. The next layer must be a separate AP with explicit ownership.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceHandoffPacketTest.php`

Expected coverage:

- ready AP-217 plus complete evidence becomes ready for owner review
- blocked AP-217 blocks the handoff packet
- false handoff evidence returns incomplete
- wrong handoff evidence shape blocks owner review
