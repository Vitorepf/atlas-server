---
id: AP-713-area-stewardship-layer-contract
type: architecture_proposal
title: AP-713 Atlas Area Stewardship Layer Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Canonizes Atlas Area Stewardship Layer as the layer above Area Focus Loop: Atlas continuously owns the health, roadmap, prioritization, Dev/Forge routing, evidence and operator decision inbox for a chosen area without creating a new OS or bypassing Night Shift/Product Mode safety gates.
related_paths:
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
requires_evidence: true
risk_level: critical
---
# AP-713 Atlas Area Stewardship Layer Contract

## Decision

Atlas Area Stewardship Layer is the canonical next layer above Area Focus Loop.
Area Focus Loop runs improvement cycles. Area Stewardship makes Atlas
responsible for continuous health, roadmap, prioritization, execution routing,
outcome measurement and operator decisions for a chosen area.

It is not a new OS. It is a stewardship/control layer over Night Shift Product
Mode, Self-Directed Evolution, Atlas Dev, Forge, Self-Construction, Evidence and
Morning Inbox.

## Core Contract

The operator assigns an `area_id`. Atlas maintains:

- area health model;
- live finding backlog;
- roadmap candidates;
- Dev/Forge routing queue;
- evidence and trust signals;
- operator decision inbox;
- improvement memory.

## Non-Negotiables

- no merge without operator;
- no deploy without operator;
- no secrets;
- no destructive change;
- no parallel runtime or proposal registry;
- all implementation routes through existing Dev, Forge or Self-Construction;
- all specs route through Self-Directed Evolution or existing Spec OS owners;
- all claims require evidence.

## Acceptance

- A canonical doc defines Area Stewardship.
- Area Focus Loop remains the execution cycle.
- Product Mode remains the cockpit/control surface.
- Area Stewardship owns continuous responsibility, not permissionless autonomy.
- Agentic Engineering OS is the first target area.
