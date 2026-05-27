---
id: AP-729-area-focus-forge-handoff-packet-contract
type: architecture_proposal
title: AP-729 Area Focus Forge Handoff Packet Builder
status: accepted
owner: programming
created_at: 2026-05-26
summary: Converts a long-horizon or cross-system Area Focus work order (route=forge) into a Forge/Obra handoff packet for agentic_engineering_os, gated by an AP-724 operator accept receipt and an AP-720 evidence pack. It declares an obra candidate targeting the real Forge runtime (atlas.forge.parallel_durable.v1) - it NEVER executes Forge, spawns agents, creates a branch, merges, deploys, pushes or accesses secrets, and never creates a parallel Forge.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-719-area-focus-dev-forge-router-contract.md
  - docs/ap/AP-720-area-focus-durable-cycle-evidence-pack-contract.md
  - docs/ap/AP-724-area-focus-operator-decision-receipts-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusForgeHandoffBuilderService.php
  - app/Services/Ai/AtlasForge/AtlasForgeParallelDurableCoordinatorService.php
requires_evidence: true
risk_level: critical
---
# AP-729 Area Focus Forge Handoff Packet Builder

## Decision

The Area Focus router (AP-719) routes long-horizon, multi-agent or cross-system
work orders to Forge. AP-729 converts such a `route=forge` work order into a
**Forge/Obra handoff packet**: a declaration of the obra Forge would run, gated by
an operator accept receipt (AP-724) and an evidence pack (AP-720).

Atlas Software Company Stewardship Stack é stack/capability family dentro do
Atlas Autonomous Software Company Runtime, não OS novo. AP-729 is a handoff
packet builder, not a new OS, not a parallel Forge and not an executor.

## Hard Boundary

- it NEVER executes Forge (`forge_executed=false`);
- it NEVER spawns agents (`agents_spawned=false`);
- it NEVER creates a branch/worktree, merges, deploys, pushes or accesses secrets;
- the packet is NEVER dispatched (`dispatched=false`); actual obra creation belongs
  to Forge under explicit operator authority in a future slice.

## Reuse Contract (no parallel Forge)

The obra candidate targets the real Forge runtime and coordinator; it reuses,
never reimplements, them:

| Concept | Owner | Contract |
|---|---|---|
| work order (route=forge) | `AreaFocusDevForgeRouterService` (AP-719) | `atlas.software_company_stewardship.area_work_order.v1` |
| operator accept receipt | `AreaFocusOperatorDecisionService` (AP-724) | `atlas.software_company_stewardship.area_focus_operator_decision_receipt.v1` |
| evidence pack | `AreaFocusEvidencePackService` (AP-720) | `atlas.software_company_stewardship.area_focus_evidence_pack.v1` |
| Forge execution target | `AtlasForgeParallelDurableCoordinatorService` | `atlas.forge.parallel_durable.v1` |

## Inputs

`work_order` (route=forge), `operator_receipt` (AP-724, decision=accept),
`area_id`/area context, `evidence_pack` (AP-720), `scope`, optional
`dependency_graph`, optional `requested_actions`.

## Output

A `atlas.software_company_stewardship.area_focus_forge_handoff.v1` packet with:
`handoff_id`, `obra_candidate`, `objective`, `scope`, `constraints`,
`risk_policy`, `expected_agents` (declared roles, never spawned), `evidence_refs`,
`acceptance_gates`, `rollback_plan`, `operator_decision_refs`, deterministic
`handoff_hash`.

## Blocking Rules

The build is blocked (no packet) when:

- `route != forge`;
- no operator accept receipt (missing, or decision != accept, or work_order mismatch);
- missing evidence pack;
- missing scope;
- a destructive / deploy / merge / push / secrets action is requested.

## Acceptance

- Builds a forge handoff for cross-system / long-horizon work.
- Blocks `atlas_dev` (and any non-forge) route.
- Blocks a missing/invalid operator accept receipt.
- Blocks missing evidence and missing scope.
- Blocks any unsafe (destructive/deploy/merge/secrets) request.
- Includes acceptance gates and a rollback plan.
- Deterministic `handoff_hash` for the same input.
- No Forge execution, no agent spawn, no branch, no parallel Forge.
- docs-health and architecture-validate stay green.
