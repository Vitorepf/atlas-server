---
id: AP-753-product-mode-cockpit-executive-allocation-handoff-visibility-contract
type: architecture_proposal
title: AP-753 Product Mode Cockpit Executive Allocation Handoff Visibility Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Extends the existing AP-739 Product Mode/Cockpit read-only surface so AP-752 Autonomous Executive allocation handoff packets are visible in the same review queue, counters, health model and operator controls. It creates no cockpit, executor, scheduler, provider call, Dev/Forge dispatch, branch, worktree, merge, deploy, secret access, budget spend or autonomous mutation path.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-752-autonomous-executive-allocation-handoff-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-753 Product Mode Cockpit Executive Allocation Handoff Visibility Contract

## Decision

AP-753 makes AP-752 allocation handoff packets visible inside the existing
Night Shift Product Mode/Cockpit surface.

It answers:

```text
When an AP-735 executive recommendation is accepted and AP-752 creates an
owner allocation handoff packet, where does the operator review it?
```

The answer is: the existing AP-739 Product Mode/Cockpit surface.

## Boundary

AP-753 is a visibility expansion. It must reuse:

- AP-739 as the cockpit owner;
- AP-752 as the allocation handoff owner;
- AP-731 as the only operator decision authority;
- AP-735 as the recommendation source;
- Area Stewardship, Area Focus, Portfolio, Atlas Dev and Forge as downstream
  owners.

AP-753 must not create a new cockpit, runtime, scheduler, executive executor,
branch allocator, worktree allocator, provider caller, Dev/Forge dispatcher,
ledger, inbox, merge/deploy path, secret path, budget spend path or
auto-approval path.

## Required Surface Additions

The AP-739 cockpit must include:

- `AP-752` in `source_ap_contracts`;
- `executive_allocation_handoff` section using
  `atlas.autonomous_executive.allocation_handoff.v1`;
- `health.executive_allocation_handoff_status`;
- counters:
  - `executive_allocation_handoff_packets`;
  - `ready_executive_allocation_handoffs`;
  - `awaiting_executive_allocation_acceptance`;
  - `blocked_executive_allocation_handoffs`;
- review queue items with:
  - `source_ap: AP-752`;
  - `kind: executive_allocation_handoff`;
  - stable packet and decision anchors;
  - `irreversible_action_allowed: false`;
  - `autoimplementation_allowed: false`;
- operator controls:
  - `executive_allocation_handoff_command`;
  - `executive_allocation_handoff_record_command`;
- cockpit claim policy proving the cockpit does not execute the handoff.

## Review Semantics

AP-752 packets are reviewable instructions to an owner, not execution orders.

The cockpit may display:

- target owner;
- target owner doc;
- target owner contract;
- AP-735 pack and recommendation anchors;
- AP-731 decision anchor;
- allowed next actions;
- blockers;
- required target-owner gate sequence.

The cockpit may not route work by itself. The target owner must replay AP-735,
AP-731 and AP-752 anchors and then run its own gate before any provider, Dev,
Forge, branch, worktree, merge, deploy, secret, budget or destructive action.

## Acceptance

- `ProductModeCockpitSurfaceService` includes AP-752 in source AP contracts.
- The cockpit emits `executive_allocation_handoff`.
- The cockpit exposes AP-752 status in health.
- The cockpit exposes AP-752 counters.
- AP-752 packets appear in the unified review queue.
- AP-752 operator commands are visible but do not execute from the cockpit.
- Tests prove AP-753 remains read-only and operator gated.
