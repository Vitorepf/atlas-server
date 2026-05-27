---
id: AP-733-portfolio-stewardship-health-model-contract
type: architecture_proposal
title: AP-733 Portfolio Stewardship Health Model Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Adds the first persistent/replayable Portfolio Stewardship health model inside Atlas Software Company Stewardship Stack. It reuses AP-730 Evolution Read Model, AP-731 Operator Decision Ledger, AP-732 Area Stewardship Promotion Readiness and AP-751 owner-runtime result signals from AP-750; it creates no OS, executor, brancher or autonomous mutation path.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-730-stewardship-evolution-read-model-contract.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-732-area-stewardship-promotion-readiness-gate-contract.md
  - docs/ap/AP-734-portfolio-steward-inbox-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-733 Portfolio Stewardship Health Model Contract

## Decision

AP-733 creates the first concrete Portfolio Stewardship health model.

It answers:

```text
What is the current health of the software company portfolio, which area is the
best next governed allocation target, and what evidence should the operator
review before accepting a portfolio rebalance?
```

## Boundary

This AP extends existing owners only:

- AP-730 provides the upper-ladder projection.
- AP-731 stores operator decisions.
- AP-732 proves whether the seed area can move toward active stewardship.
- This AP stores portfolio health snapshots as append-only JSONL.
- AP-751 lets this model consume AP-750 owner-runtime result feed signals.

It must not create a Portfolio OS, executor, scheduler, brancher, Dev/Forge
dispatcher, merge/deploy path, secret access path, auto-approval path or
permissionless promotion.

## Schemas

```text
atlas.portfolio_stewardship.health_model.v1
atlas.portfolio_stewardship.health_snapshot.v1
atlas.portfolio_stewardship.health_ledger.v1
```

## Required Output

The health model must include:

- `portfolio_id`;
- normalized `areas`;
- `dependency_graph`;
- `portfolio_health` with score, band and lowest-health area;
- `owner_runtime_result_summary` when AP-750 result feed is present;
- `risk_summary`;
- `rebalance_candidates`;
- `operator_inbox`;
- `promotion_boundary`;
- `evidence_refs`;
- no-mutation `claim_policy`;
- deterministic `health_hash`.

## Persistence

`portfolio-health-record` may write only append-only JSONL under:

```text
storage/atlas/software_company_stewardship/portfolio_health/<portfolio_id>.jsonl
```

The write is idempotent by deterministic `snapshot_id`. Replay must work from
the JSONL record and corrupted lines must be counted, not fatal.

## Acceptance

- `PortfolioStewardshipHealthModelService` emits the health model schema.
- The model prioritizes lowest-health/dependency-weighted areas.
- The model consumes AP-751 owner-runtime result signals from AP-750 and
  prioritizes review/follow-up before new allocation.
- The model includes operator inbox and promotion boundary.
- `record`, `listSnapshots` and `replay` are deterministic and append-only.
- CLI exposes `portfolio-health`, `portfolio-health-record`,
  `portfolio-health-snapshots` and `portfolio-health-replay`.
- Tests prove no repo/provider/Dev/Forge/branch/merge/deploy/secrets behavior.
