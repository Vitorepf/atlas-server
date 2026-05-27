---
id: AP-711-night-shift-product-mode-contract
type: architecture_proposal
title: AP-711 Night Shift Product Mode Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Elevates Atlas Autonomous Software Company Night Shift from an operational loop into a final product target with Cockpit, repository onboarding, autonomy tiers, continuous mode, budget controls, kill switch, team inbox and product-grade trust surfaces.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
requires_evidence: true
risk_level: critical
---
# AP-711 Night Shift Product Mode Contract

## Decision

Night Shift Product Mode is the final product target for the Night Shift
contract. It does not create a new OS. It productizes the existing Night Shift
loop with a Cockpit, repository onboarding, autonomy tiers, live controls,
continuous operation and review surfaces.

## Product Target

The final product is not just a background job. It is an operator-facing product
where a human can authorize repos, configure risk, watch active work, review
branches, inspect evidence and approve or reject changes.

## Mandatory Progression

```text
NS-v1 internal Atlas proof
-> NS-v2 authorized external company proof
-> NS-v3 continuous 24h loop
-> NS-v4 product-grade cockpit
-> NS-v5 multi-company software company product
```

No product mode may bypass v1/v2 evidence, branch isolation or operator review.

## Acceptance

- Product Mode doc exists as child of Night Shift.
- It defines Cockpit, onboarding, autonomy tiers, controls, continuous loop and
  trust surfaces.
- It preserves no-merge/no-deploy/no-secrets defaults.
- It defines final user experience without claiming current implementation.
