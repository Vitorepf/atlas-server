---
title: AP-211 AP Agent Workflow Integrator Handoff Packet
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorHandoffPacket.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorHandoffPacketTest.php
depends_on:
  - AP-210
---

# AP-211 AP Agent Workflow Integrator Handoff Packet

## Purpose

Create the final read-only packet that hands accepted AP agent work to an integrator
without merging, executing, persisting, or approving anything automatically.

AP-210 says what the human decided. AP-211 translates that decision into the next
integration posture.

## Contract

Class: `AtlasApAgentWorkflowIntegratorHandoffPacket`

Schema: `atlas.ap_agent_workflow_integrator_handoff_packet.v1`

## Statuses

- `ready_for_integrator_review`
- `return_to_agent_for_repair`
- `stopped_by_human_rejection`
- `blocked_by_human_decision_contract`

## Rules

- Human `accept` becomes `ready_for_integrator_review`.
- Human `request_changes` becomes `return_to_agent_for_repair`.
- Human `reject` becomes `stopped_by_human_rejection`.
- Any blocked AP-210 decision becomes `blocked_by_human_decision_contract`.
- The packet never writes files.
- The packet never runs commands.
- The packet never persists approval.
- The packet never auto-merges work.

## Output

The payload includes:

- normalized AP integration scope
- source human decision summary
- source review packet status
- source execution receipt status
- next action
- guardrails

## Human Meaning

This is the bridge between an accepted agent packet and real integration work. It keeps
the Atlas honest: even after human acceptance, a separate integrator review still owns
the merge decision.

## Implementation Notes

This contract depends only on AP-210. It adds no new validation rule to AP-200 through
AP-210 and creates no parallel approval path.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorHandoffPacketTest.php`

Expected coverage:

- accepted human decision becomes integrator-ready
- requested changes return work to the agent
- rejection stops the scope
- blocked human decision blocks integrator handoff
