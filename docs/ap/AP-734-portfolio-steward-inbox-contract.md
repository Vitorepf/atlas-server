---
id: AP-734-portfolio-steward-inbox-contract
type: architecture_proposal
title: AP-734 Portfolio Steward Inbox Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Adds a Portfolio Steward Inbox over AP-733 Portfolio Health snapshots. It turns portfolio rebalance candidates, including AP-751 owner-runtime result review/follow-up candidates, into operator-reviewable inbox items, persists inbox snapshots as append-only JSONL and records explicit operator decisions through AP-731. AP-735 consumes this inbox for Autonomous Executive recommendations. It creates no inbox system, executor, brancher, scheduler, Dev/Forge dispatch or autonomous mutation path.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-733-portfolio-stewardship-health-model-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - docs/ap/AP-735-autonomous-executive-recommendation-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-734 Portfolio Steward Inbox Contract

## Decision

AP-734 creates the governed review surface for Portfolio Stewardship.

It answers:

```text
Which portfolio rebalance candidates should the operator accept, reject, defer
or send back for changes?
```

## Boundary

This AP is an inbox adapter, not an executor.

It must reuse:

- AP-733 Portfolio Health Model as the evidence source;
- AP-751 owner-runtime result signals when present in the AP-733 projection;
- AP-731 Stewardship Evolution Decision Ledger for operator decisions;
- the Atlas Software Company Stewardship Stack and Evolution Ladder docs as
  naming/authority owners.

It must not create a new generic inbox system, Portfolio OS, scheduler,
brancher, Dev/Forge dispatcher, merge/deploy path, secret access path,
auto-approval path or permissionless promotion.

## Schemas

```text
atlas.portfolio_stewardship.inbox.v1
atlas.portfolio_stewardship.inbox_item.v1
atlas.portfolio_stewardship.inbox_ledger.v1
```

## Required Output

The inbox must include:

- `portfolio_id`;
- AP-733 `source_health_hash`;
- optional `source_snapshot_id`;
- reviewable `items`;
- each item target anchored as `target_type=portfolio_stewardship`;
- `target_id`, `target_hash` and cold `target_payload`;
- operator decision options;
- evidence refs;
- source AP contracts, including AP-751 when owner-runtime results are present;
- no-mutation claim policy;
- deterministic `inbox_hash`.

## Persistence And Decisions

`portfolio-inbox-record` may write only append-only JSONL under:

```text
storage/atlas/software_company_stewardship/portfolio_inbox/<portfolio_id>.jsonl
```

`portfolio-inbox-decision` must record through AP-731 with:

```text
target_type: portfolio_stewardship
decision: accept | reject | defer | request_changes
```

For recorded inboxes, decisions should prefer the stable anchor pair
`--inbox-id` + `--item-id`. The service must replay the recorded inbox before
resolving the item so operator decisions cannot drift when the current AP-733
health projection changes after the inbox was shown.

An accepted decision unlocks only the next governed owner step. It does not
execute a rebalance.

## Acceptance

- `PortfolioStewardshipInboxService` emits inbox and item schemas.
- Items are deterministic and anchored to AP-733 health evidence.
- AP-751 review/follow-up candidates preserve action, rationale and evidence.
- Inbox record/list/replay is idempotent and append-only.
- Decisions use AP-731, support stable recorded-inbox anchors, and never
  execute.
- CLI exposes `portfolio-inbox`, `portfolio-inbox-record`,
  `portfolio-inbox-list`, `portfolio-inbox-replay` and
  `portfolio-inbox-decision`.
- Tests prove no repo/provider/Dev/Forge/branch/merge/deploy/secrets behavior.
