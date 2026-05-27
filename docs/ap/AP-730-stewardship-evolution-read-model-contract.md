---
id: AP-730-stewardship-evolution-read-model-contract
type: architecture_proposal
title: AP-730 Stewardship Evolution Read Model Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Implements the first read-only/proposal-only code surface for the levels above Area Focus Loop inside Atlas Software Company Stewardship Stack: Area Stewardship, Portfolio Stewardship, Autonomous Executive and Self-Expanding Software Company. AP-735 extends its Autonomous Executive concept with concrete recommendation packs, AP-736 projects those packs plus AP-731 receipts into the Executive Decision Inbox surface, AP-737 gates AP-730 new-area proposals before Domain Runtime Creation Gate review, and AP-738 composes the Self-Expanding v0 top-level report. It reuses AP-716 Area Focus Loop, AP-714 Ladder and AP-715 Stack; it creates no new OS, no executor, no proposal registry and no permissionless autonomy.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-714-stewardship-evolution-ladder-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-735-autonomous-executive-recommendation-contract.md
  - docs/ap/AP-736-executive-decision-inbox-surface-contract.md
  - docs/ap/AP-737-new-area-proposal-gate-contract.md
  - docs/ap/AP-738-self-expanding-software-company-v0-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionReadModelServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-730 Stewardship Evolution Read Model Contract

## Decision

Add one read-only/proposal-only read model for the future ladder above Area Focus
Loop:

```text
Area Stewardship
-> Portfolio Stewardship
-> Autonomous Executive
-> Self-Expanding Software Company
```

This does not complete active autonomous mutation. It makes the upper Stack
concrete, inspectable, testable and safe for operator review.

## Boundary

The implementation must:

- reuse AP-716 Area Focus Loop as the area signal source;
- reuse AP-714/AP-715 names and boundaries;
- emit canonical schemas for area, portfolio, executive and new-area proposals;
- remain read-only and proposal-only;
- expose CLI actions through `atlas:software-company-stewardship`;
- never call providers, mutate repo state, create branches, dispatch Dev/Forge,
  merge, deploy, access secrets, auto-promote or create a parallel OS/runtime.

## Acceptance

- `StewardshipEvolutionReadModelService` exists and emits
  `atlas.software_company_stewardship.evolution_ladder.v1`.
- The report proves Continuous Stewardship Loop is the 24h motor, not the stack
  ceiling.
- The report proves Self-Expanding Software Company is the stack ceiling and is
  proposal-only.
- CLI supports `evolution`, `area-stewardship`, `portfolio`, `executive` and
  `self-expanding`.
- Focused tests cover schemas, gates, deterministic hashes and no-mutation
  claim policy.
