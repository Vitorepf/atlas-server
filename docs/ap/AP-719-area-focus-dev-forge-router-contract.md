---
id: AP-719-area-focus-dev-forge-router-contract
type: architecture_proposal
title: AP-719 Area Focus Dev/Forge Work Order Router
status: accepted
owner: programming
created_at: 2026-05-26
summary: Turns Area Focus Loop findings and inbox items into governed, proposal-only work orders inside the Atlas Software Company Stewardship Stack (Night Shift Product Mode). The router classifies each item into Self-Directed Evolution, Atlas Dev, Forge or operator review, allocates governed Dev/Forge budgets and a WIP limit, and blocks anything over budget or WIP. It emits work orders only — it never executes Dev or Forge, never merges, deploys, accesses secrets or makes destructive changes, and creates no parallel runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-717-agentic-engineering-os-area-finding-engine-contract.md
  - docs/ap/AP-718-area-focus-inbox-spec-draft-bridge-contract.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AgenticEngineeringOsFindingEngineService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusInboxService.php
requires_evidence: true
risk_level: high
---
# AP-719 Area Focus Dev/Forge Work Order Router

## Decision

The Area Focus Finding Engine (AP-717) produces findings and the Area Focus
Inbox (AP-718) produces operator-reviewable items. AP-719 adds the next governed
step: those findings and inbox items become **work orders** routed to the
correct execution owner under governed budgets — without executing anything.

Atlas Software Company Stewardship Stack é stack/capability family dentro do
Atlas Autonomous Software Company Runtime, não OS novo. AP-719 is a read-only,
proposal-only routing slice inside that stack. It is not a new OS, not a parallel
runtime and not a new execution engine. It never invokes Atlas Dev or Forge.

## Reuse Contract

AP-719 must reuse existing classification and create no parallel authority:

- it anchors on the finding's existing `route_hint` (already classified and
  safety-escalated by the AP-717 Finding Engine) rather than re-deriving routing
  from scratch;
- gap/spec ownership stays with the Self-Directed Evolution Layer; the router
  only routes uncontracted gaps there, it never drafts specs;
- Atlas Dev and Forge remain the execution owners; the router only emits work
  orders for operator review, it never dispatches or runs them;
- nothing is persisted and no proposal/backlog registry is created.

## Schema

```text
atlas.software_company_stewardship.area_work_order.v1
```

## Inputs

```text
findings        list  AP-717 area findings (atlas.software_company_stewardship.area_finding.v1)
inbox_items     list  AP-718 inbox items (atlas.software_company_stewardship.area_focus_inbox_item.v1)
area_id         str   canonical area (default: agentic_engineering_os)
dev_budget      int   max emitted atlas_dev work orders
forge_budget    int   max emitted forge work orders
wip_limit       int   max total emitted work orders across all lanes
risk_policy     map   sensitive domains that force operator_review
```

Findings and inbox items are deduplicated by `finding_hash`.

## Routes

```text
self_directed_evolution : gap with no spec yet (uncontracted gap)
atlas_dev               : small, local, low-risk, with clear tests
forge                   : long-horizon, multi-agent, cross-system, high-context
operator_review         : high-risk, ambiguous, destructive, secrets/deploy/merge, missing owner docs
```

Routing precedence is safety-first: operator_review wins over everything, then
self_directed_evolution for uncontracted gaps, then forge for cross-system work,
then atlas_dev for small local work, with operator_review as the conservative
default.

## Governed Budgets

`max_governed` means maximum useful throughput inside the safety boundary, never
permissionless autonomy:

- each emitted `atlas_dev` work order consumes one `dev_budget` unit;
- each emitted `forge` work order consumes one `forge_budget` unit;
- every emitted work order (any lane) consumes one `wip_limit` unit;
- when the WIP limit is reached, further work orders are blocked with
  `wip_limit_reached`;
- when a lane budget is exhausted, further work orders for that lane are blocked
  with `budget_exhausted`;
- blocked work orders are still returned (transparency) but consume no budget and
  are never executed.

## Work Order Contract

Every `area_work_order.v1` must declare:

- `work_order_id` and deterministic `work_order_hash`;
- `area_id`, `source` (`finding`|`inbox_item`), `source_ref` (`finding_hash`);
- `route` and `lane`;
- `risk_level`, `severity`, `confidence`, `priority_score`;
- `status` (`emitted`|`blocked`) and `block_reason`;
- `requires_operator_review: true` and `execution_performed: false`.

## Acceptance

- Small/local/low-risk finding routes to `atlas_dev`.
- Cross-system finding routes to `forge`.
- Gap without a spec routes to `self_directed_evolution`.
- High-risk / sensitive / destructive / secrets / deploy / merge / missing-owner-doc
  finding routes to `operator_review`.
- A finding over the lane budget is blocked with `budget_exhausted`.
- A finding over the WIP limit is blocked with `wip_limit_reached`.
- The work order plan carries a deterministic `report_hash` for the same input.
- No Dev/Forge execution, no merge, deploy, secrets or destructive change.
- No parallel runtime; the finding route classification is reused.
- docs-health and architecture-validate stay green.
