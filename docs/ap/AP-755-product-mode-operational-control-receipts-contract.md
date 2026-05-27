---
id: AP-755-product-mode-operational-control-receipts-contract
type: architecture_proposal
title: AP-755 Product Mode Operational Control Receipts Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Turns AP-754 Product Mode controls from transient CLI/cockpit inputs into append-only operator receipts by reusing the AP-731 Stewardship Evolution Decision Ledger with target_type=product_mode_control. AP-755 records repository authorization, autonomy tier, budget, safety, risk and evidence policy decisions, replays accepted receipts into AP-754 projections and keeps all execution, branch, provider, merge, deploy and secret authority outside the cockpit.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-754-product-mode-operational-controls-read-model-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionOperatorDecisionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-755 Product Mode Operational Control Receipts Contract

## Decision

AP-754 made Product Mode controls visible. AP-755 makes those controls governed
over time by recording explicit operator receipts in the existing AP-731 ledger.

This is deliberately not a new ledger, not a new cockpit and not an executor.
The canonical storage owner remains:

```text
AP-731 Stewardship Evolution Operator Decision Ledger
```

AP-755 adds only one AP-731 target type:

```text
product_mode_control
```

## Control Types

```text
repo_authorization
autonomy_tier
budget_policy
safety_control
risk_policy
evidence_policy
```

Accepted receipts may feed AP-754 projections. Rejected, deferred or
request_changes receipts remain audit history and must not change effective
controls.

## Flow

```text
operator control intent
-> AP-755 ProductModeOperationalControlReceiptService
-> AP-731 append-only decision receipt target_type=product_mode_control
-> AP-755 effective control policy projection
-> AP-754 Product Mode operational controls
-> AP-739 Product Mode/Cockpit
```

## Boundary

AP-755 may:

- record operator-owned control receipts append-only through AP-731;
- list and replay Product Mode control receipts;
- project accepted receipts into AP-754 read models;
- let Product Mode/Cockpit show that controls came from receipts.

AP-755 must not:

- create a Product Mode ledger parallel to AP-731;
- authorize repositories directly;
- change autonomy tiers directly;
- update budget/risk policy directly outside receipt projection;
- toggle kill switches directly;
- create branches or worktrees;
- invoke providers, Atlas Dev or Forge;
- merge, deploy, touch secrets or perform destructive changes;
- install or start schedulers.

## Schemas

```text
atlas.software_company.product_mode_control_receipts.v1
atlas.software_company.product_mode_control_receipt.v1
atlas.software_company.product_mode_control_policy.v1
atlas.software_company.product_mode_control_policy_projection.v1
```

AP-731 still owns the persisted receipt schema:

```text
atlas.software_company_stewardship.evolution_operator_decision_receipt.v1
atlas.software_company_stewardship.evolution_decision_ledger.v1
```

## CLI

```text
php artisan atlas:software-company-stewardship product-mode-control-receipt --control-type=<type> --actor=<operator> --decision=accept --json
php artisan atlas:software-company-stewardship product-mode-control-receipts --json
php artisan atlas:software-company-stewardship product-mode-control-replay --decision-id=<id> --json
php artisan atlas:software-company-stewardship product-mode-controls --use-recorded-controls --json
php artisan atlas:software-company-stewardship product-mode-cockpit --use-recorded-controls --json
```

## Acceptance

- AP-731 accepts `product_mode_control` as a governed target type.
- `ProductModeOperationalControlReceiptService` records, lists, replays and
  projects accepted control receipts without a parallel ledger.
- Product Mode controls can consume AP-755 policy projections.
- CLI exposes record, list, replay and `--use-recorded-controls`.
- Secret-like input is not persisted in control payloads.
- Focused tests prove accepted receipts affect AP-754 projections and rejected
  receipts do not.
- Claim policies prove no repo mutation, no tier mutation outside projection,
  no provider, no Dev/Forge, no branch, no merge, no deploy and no secrets.
