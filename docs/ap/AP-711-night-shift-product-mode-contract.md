---
id: AP-711-night-shift-product-mode-contract
type: architecture_proposal
title: AP-711 Night Shift Product Mode Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Elevates Atlas Autonomous Software Company Night Shift from an operational loop into a final product target with Cockpit, repository onboarding, autonomy tiers, Atlas Continuous Stewardship Loop, budget controls, kill switch, team inbox and product-grade trust surfaces. AP-755 turns Product Mode control changes into AP-731 append-only operator receipts; AP-756 materializes branch sandboxes only with explicit operator receipt.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/ap/AP-745-continuous-stewardship-loop-scheduler-safe-contract.md
  - docs/ap/AP-754-product-mode-operational-controls-read-model-contract.md
  - docs/ap/AP-755-product-mode-operational-control-receipts-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php
requires_evidence: true
risk_level: critical
---
# AP-711 Night Shift Product Mode Contract

## Decision

Night Shift Product Mode is the final product target for the Night Shift
contract. It does not create a new OS. It productizes the existing Night Shift
loop with a Cockpit, repository onboarding, autonomy tiers, live controls,
continuous operation and review surfaces. The canonical name for 24h/always-on
operation is Atlas Continuous Stewardship Loop; NS-v3 Continuous Loop is only a
legacy/transitional alias.

## Product Target

The final product is not just a background job. It is an operator-facing product
where a human can authorize repos, configure risk, watch active work, review
branches, inspect evidence and approve or reject changes.

## Mandatory Progression

```text
NS-v1 internal Atlas proof
-> NS-v2 authorized external company proof
-> Atlas Continuous Stewardship Loop (legacy alias: NS-v3 Continuous Loop)
-> NS-v4 product-grade cockpit
-> NS-v5 multi-company software company product
```

No product mode may bypass v1/v2 evidence, branch isolation or operator review.

## Acceptance

- Product Mode doc exists as child of Night Shift.
- It defines Cockpit, onboarding, autonomy tiers, controls, Atlas Continuous
  Stewardship Loop and trust surfaces.
- It preserves no-merge/no-deploy/no-secrets defaults.
- It defines final user experience without claiming current implementation.
- AP-745 is the first implemented scheduler-safe Continuous Stewardship Loop
  tick: disabled by default, kill-switch guarded, lock/rate limited and still
  no provider, branch, Dev/Forge dispatch or repo mutation.
- AP-754 is the first implemented operational controls read model for Product
  Mode: repo onboarding state, autonomy tier, budget/rate limits, branch review,
  evidence inspector and kill switch are visible without mutating policy or
  executing work.
- AP-755 records Product Mode control changes as AP-731 append-only operator
  receipts and lets AP-754 consume accepted receipts without creating a Product
  Mode ledger or executor.
- AP-756 materializes branch/worktree sandboxes only after an explicit operator
  receipt and keeps Product Mode from becoming a Dev/Forge executor.
