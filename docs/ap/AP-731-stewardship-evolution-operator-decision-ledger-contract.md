---
id: AP-731-stewardship-evolution-operator-decision-ledger-contract
type: architecture_proposal
title: AP-731 Stewardship Evolution Operator Decision Ledger
status: accepted
owner: programming
created_at: 2026-05-27
summary: Adds persistent, replayable operator decision receipts for AP-730 Stewardship Evolution outputs: Area Stewardship, Portfolio Stewardship, Autonomous Executive and Self-Expanding Software Company proposals. AP-735 uses this ledger for Autonomous Executive recommendation decisions, AP-736 projects those receipts into the read-only Executive Decision Inbox surface, AP-737 uses it for New Area Proposal Gate decisions, AP-738 composes the Self-Expanding v0 review state, AP-740 bridges outcomes into canonical Evidence Ledger and Morning Inbox, and AP-755 reuses this ledger for Product Mode operational control receipts. This is an inbox/ledger bridge only; it creates no OS, no executor, no branch, no provider call, no Dev/Forge dispatch and no auto-promotion.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-730-stewardship-evolution-read-model-contract.md
  - docs/ap/AP-735-autonomous-executive-recommendation-contract.md
  - docs/ap/AP-736-executive-decision-inbox-surface-contract.md
  - docs/ap/AP-737-new-area-proposal-gate-contract.md
  - docs/ap/AP-738-self-expanding-software-company-v0-contract.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-755-product-mode-operational-control-receipts-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionOperatorDecisionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionOperatorDecisionServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-731 Stewardship Evolution Operator Decision Ledger

## Decision

AP-730 made the upper Stewardship Ladder inspectable. AP-731 makes it
reviewable over time by persisting explicit operator decisions as append-only
JSONL receipts.

Targets:

```text
area_stewardship
portfolio_stewardship
autonomous_executive
self_expanding_software_company
new_area_proposal
product_mode_control
```

Decisions:

```text
accept | reject | defer | request_changes
```

An `accept` does not execute. It only records that the operator approved the
next governed owner step.

## Boundary

AP-731 must:

- reuse AP-730/AP-714/AP-715 names and boundaries;
- create deterministic operator decision receipts;
- persist them append-only under local storage;
- support list/replay through `atlas:software-company-stewardship`;
- require target anchors (`target_id`, `target_hash` or `target_payload`);
- require rationale for high/critical risk accepts;
- never call providers, mutate repo state, create branches, dispatch Dev/Forge,
  merge, deploy, access secrets, auto-approve, auto-implement, auto-promote or
  create a parallel OS/runtime.

## Schemas

```text
atlas.software_company_stewardship.evolution_operator_decision_receipt.v1
atlas.software_company_stewardship.evolution_decision_ledger.v1
```

## Acceptance

- Receipt service builds deterministic decisions for all target types.
- Ledger service records, lists and replays decisions idempotently.
- Corrupted JSONL lines are counted and skipped.
- Secret-like extra inputs are not persisted.
- CLI exposes `evolution-decision`, `evolution-decisions` and
  `evolution-replay`.
- AP-740 exposes AP-731 decision outcomes to canonical Evidence Ledger and
  Morning Inbox without creating another ledger or inbox.
- AP-755 uses `target_type=product_mode_control` for Product Mode controls
  instead of creating a parallel Product Mode ledger.
- Focused tests prove no execution, no provider, no branch, no repo mutation and
  no auto-promotion.
