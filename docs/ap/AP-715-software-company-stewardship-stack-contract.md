---
id: AP-715-software-company-stewardship-stack-contract
type: architecture_proposal
title: AP-715 Atlas Software Company Stewardship Stack Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Canonizes Atlas Software Company Stewardship Stack as the umbrella area containing Night Shift, Night Shift Product Mode, Area Focus Loop, Area Stewardship, Portfolio Stewardship, Autonomous Executive Layer and Self-Expanding Software Company. The stack is a governed capability stack inside Atlas Autonomous Software Company Runtime, not a new OS or runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
requires_evidence: true
risk_level: critical
---
# AP-715 Atlas Software Company Stewardship Stack Contract

## Decision

The canonical umbrella name for the entire Night Shift / Product Mode / Area
Focus / Stewardship family is:

```text
Atlas Software Company Stewardship Stack
```

This is a capability stack inside Atlas Autonomous Software Company Runtime. It
is not a new OS, not a parallel runtime and not a replacement for AAEOS,
Autonomous Software Company Runtime, Night Shift, Product Mode or Evidence.

## Stack Members

```text
Night Shift
-> Night Shift Product Mode
-> Area Focus Loop
-> Area Stewardship Layer
-> Portfolio Stewardship Layer
-> Autonomous Executive Layer
-> Self-Expanding Software Company
```

## AI Rule

If an AI sees any of these names, it must first load
`atlas-software-company-stewardship-stack.md` before creating a new doc, OS,
runtime, ladder or umbrella name.

## Acceptance

- Canonical stack doc exists.
- Architecture indexes point to it.
- Child docs point back to it.
- It declares aliases and forbidden duplicate names.
- It preserves operator review, evidence, branch isolation, budget, WIP and
  no-merge/no-deploy defaults.
