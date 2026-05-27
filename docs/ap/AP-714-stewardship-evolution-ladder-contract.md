---
id: AP-714-stewardship-evolution-ladder-contract
type: architecture_proposal
title: AP-714 Atlas Stewardship Evolution Ladder Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Canonizes the evolution beyond Area Stewardship as a governed ladder: Area Focus Loop, Area Stewardship, Portfolio Stewardship, Autonomous Executive Layer and Self-Expanding Software Company. The ladder is future-facing, reuses existing owners and forbids new OS/runtime duplication or permissionless autonomy.
related_paths:
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
requires_evidence: true
risk_level: critical
---
# AP-714 Atlas Stewardship Evolution Ladder Contract

## Decision

The next evolution beyond Area Stewardship is a ladder, not a new OS:

```text
Area Focus Loop
-> Area Stewardship Layer
-> Portfolio Stewardship Layer
-> Autonomous Executive Layer
-> Self-Expanding Software Company
```

Each step increases scope of responsibility while preserving operator approval,
evidence, branch isolation, budget, WIP limits, kill switch and no-merge/no-deploy
defaults.

## Boundary

The ladder reuses:

- Night Shift and Product Mode for cycles and cockpit;
- Area Stewardship for area ownership;
- Self-Directed Evolution for gaps/specs;
- Dev and Forge for implementation routing;
- Evidence for proof;
- Autonomous Software Company Runtime for organizational coordination.

No layer creates a parallel executor, proposal registry or authority surface.

## Acceptance

- Canonical ladder doc exists.
- Area Stewardship remains the first stewardship layer.
- Portfolio Stewardship owns multiple areas.
- Autonomous Executive owns strategy and resource allocation.
- Self-Expanding Software Company proposes new areas only through governed gates.
- All stages remain future targets until runtime evidence exists.
