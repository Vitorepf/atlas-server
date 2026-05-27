---
id: AP-736-executive-decision-inbox-surface-contract
type: architecture_proposal
title: AP-736 Executive Decision Inbox Surface Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Adds a read-only Executive Decision Inbox surface over AP-735 Autonomous Executive recommendation packs and AP-731 operator receipts. It gives Product Mode/Cockpit a stable review projection with pending/accepted/deferred/rejected states and decision command anchors. AP-739 now consumes this surface in the Stewardship Product Mode Cockpit; AP-752 consumes accepted AP-735/AP-731 anchors to prepare owner allocation handoffs. It creates no inbox system, executor, brancher, scheduler, Dev/Forge dispatch or autonomous mutation path.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-735-autonomous-executive-recommendation-contract.md
  - docs/ap/AP-752-autonomous-executive-allocation-handoff-contract.md
  - docs/ap/AP-737-new-area-proposal-gate-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffService.php
  - app/Http/Controllers/Ai/SoftwareCompanyStewardship/ExecutiveDecisionInboxController.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - routes/api.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceServiceTest.php
  - tests/Feature/Ai/SoftwareCompany/ExecutiveDecisionInboxControllerTest.php
requires_evidence: true
risk_level: critical
---
# AP-736 Executive Decision Inbox Surface Contract

## Decision

AP-736 creates the read-only cockpit-ready surface for Autonomous Executive
decisions.

It answers:

```text
Which executive recommendations are waiting for operator review, and which
ones already have AP-731 receipts?
```

## Boundary

This AP is a surface projection, not a decision executor.

It must reuse:

- AP-735 Autonomous Executive Recommendation packs;
- AP-731 Stewardship Evolution Decision Ledger;
- the Atlas Software Company Stewardship Stack and Evolution Ladder docs as
  naming/authority owners.

It must not create a new generic inbox system, Executive OS, scheduler,
brancher, Dev/Forge dispatcher, merge/deploy path, secret access path,
auto-approval path or permissionless promotion.

## Schemas

```text
atlas.autonomous_executive.decision_inbox_surface.v1
atlas.autonomous_executive.decision_inbox_item.v1
```

## Required Output

The surface must include:

- `portfolio_id`;
- AP-735 source pack anchors (`source_pack_id`, `source_pack_hash`);
- `decision_summary`;
- reviewable `items`;
- each item target anchored as `target_type=autonomous_executive`;
- each item stable decision anchors (`pack_id`, `recommendation_id`,
  `target_id`, `target_hash`);
- latest AP-731 decision state when present;
- operator decision options and command hint;
- no-mutation claim policy;
- deterministic `surface_hash`.

## HTTP Surface

The HTTP surface is:

```text
GET /ai/software-company-stewardship/executive-decision-inbox/{portfolio}
```

It must use canonical `atlas.token` middleware, support optional `pack_id`,
emit ETag and never write local state.

## Acceptance

- `ExecutiveDecisionInboxSurfaceService` emits surface and item schemas.
- Surface projects AP-735 recommendations and AP-731 decision state.
- HTTP controller returns a cockpit-ready read model with ETag.
- CLI exposes `executive-decision-inbox`.
- AP-739 consumes this projection in the combined Stewardship Product Mode
  Cockpit without recording decisions.
- AP-752 consumes only accepted AP-735/AP-731 anchors and still emits a
  handoff packet, not execution.
- Tests prove no repo/provider/Dev/Forge/branch/merge/deploy/secrets behavior.
