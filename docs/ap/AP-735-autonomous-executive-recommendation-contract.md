---
id: AP-735-autonomous-executive-recommendation-contract
type: architecture_proposal
title: AP-735 Autonomous Executive Recommendation Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Adds the first concrete Autonomous Executive recommendation pack on top of AP-734 Portfolio Steward Inbox. It converts portfolio rebalance inbox items, including AP-751 owner-runtime result review/follow-up items, into strategy, capacity, budget, regret and risk recommendations, records packs as append-only JSONL, and records operator decisions through AP-731. AP-736 projects these packs into a read-only Executive Decision Inbox surface, and AP-752 turns accepted recommendations into governed owner allocation handoff packets. It creates no CEO agent, executor, scheduler, brancher, Dev/Forge dispatch or autonomous mutation path.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-734-portfolio-steward-inbox-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - docs/ap/AP-736-executive-decision-inbox-surface-contract.md
  - docs/ap/AP-752-autonomous-executive-allocation-handoff-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-735 Autonomous Executive Recommendation Contract

## Decision

AP-735 materializes the Autonomous Executive Layer as a governed
recommendation pack.

It answers:

```text
Given the current Portfolio Steward Inbox, what should the operator approve,
defer or send back so Atlas allocates software-company energy correctly?
```

## Boundary

This AP is an executive recommendation adapter, not an autonomous CEO.

It must reuse:

- AP-734 Portfolio Steward Inbox as the portfolio decision source;
- AP-751 source contracts when AP-734 items are based on owner-runtime results;
- AP-731 Stewardship Evolution Decision Ledger for operator decisions;
- the Atlas Software Company Stewardship Stack and Evolution Ladder docs as
  naming/authority owners.

It must not create a new Executive OS, scheduler, company runtime, brancher,
Dev/Forge dispatcher, merge/deploy path, secret access path, auto-approval path
or permissionless promotion.

## Schemas

```text
atlas.autonomous_executive.recommendation_pack.v1
atlas.executive.recommendation.v1
atlas.autonomous_executive.recommendation_ledger.v1
```

## Required Output

The recommendation pack must include:

- `portfolio_id`;
- AP-734 source inbox anchors (`source_inbox_id`, `source_inbox_hash`);
- reviewable `recommendations`;
- each recommendation target anchored as `target_type=autonomous_executive`;
- `target_id`, `target_hash` and cold `target_payload`;
- strategy recommendation;
- capacity allocation;
- budget policy;
- regret analysis;
- risk analysis;
- source AP contracts, including AP-751 when the recommendation is based on
  owner-runtime result review/follow-up;
- operator decision options;
- no-mutation claim policy;
- deterministic `pack_hash`.

## Persistence And Decisions

`executive-recommendation-record` may write only append-only JSONL under:

```text
storage/atlas/software_company_stewardship/executive_recommendations/<portfolio_id>.jsonl
```

`executive-recommendation-decision` must record through AP-731 with:

```text
target_type: autonomous_executive
decision: accept | reject | defer | request_changes
```

For recorded packs, decisions should prefer the stable anchor pair
`--pack-id` + `--recommendation-id`. The service must replay the recorded pack
before resolving the recommendation so operator decisions cannot drift when the
current AP-734 inbox changes after the recommendation was shown.

An accepted decision unlocks only the next governed owner step. It does not
allocate agents, create branches, invoke Forge, merge, deploy or spend budget.
AP-752 is the required handoff gate for that next owner step.

## Acceptance

- `AutonomousExecutiveRecommendationService` emits pack and recommendation
  schemas.
- Recommendations are deterministic and anchored to AP-734 inbox evidence.
- AP-751 result-review/follow-up actions survive into executive recommendations.
- Accepted AP-735 recommendations can be handed to AP-752 without bypassing
  operator review or owner gates.
- Recommendation record/list/replay is idempotent and append-only.
- Decisions use AP-731, support stable recorded-pack anchors, and never
  execute.
- CLI exposes `executive-recommendations`,
  `executive-recommendation-record`, `executive-recommendation-list`,
  `executive-recommendation-replay` and
  `executive-recommendation-decision`.
- Tests prove no repo/provider/Dev/Forge/branch/merge/deploy/secrets behavior.
