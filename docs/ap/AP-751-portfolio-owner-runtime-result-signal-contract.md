---
id: AP-751-portfolio-owner-runtime-result-signal-contract
type: ap_contract
title: AP-751 Portfolio Owner Runtime Result Signal Contract
status: active
summary: Extends the AP-733 Portfolio Stewardship Health Model so AP-750 owner runtime result bridge signals become first-class Portfolio health, risk and rebalance inputs, then preserves those signals through AP-734 Portfolio Inbox, AP-735 Autonomous Executive recommendations and AP-752 executive allocation handoffs. It creates no executor, runtime, provider path, branch/worktree, merge/deploy path, scheduler or autonomous mutation path.
owner: programming
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/ap/AP-733-portfolio-stewardship-health-model-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-734-portfolio-steward-inbox-contract.md
  - docs/ap/AP-735-autonomous-executive-recommendation-contract.md
  - docs/ap/AP-752-autonomous-executive-allocation-handoff-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffServiceTest.php
---
# AP-751 Portfolio Owner Runtime Result Signal Contract

## Decision

AP-751 makes Portfolio Stewardship consume the real owner-runtime result signal
emitted by AP-750.

```text
AP-749 owner queue consumption
-> owner runtime executes under Atlas Dev / Forge authority
-> AP-750 owner runtime result bridge
-> AP-751 Portfolio owner-runtime result signal intake
-> AP-733 health/risk/rebalance projection
-> AP-734 Portfolio Inbox item
-> AP-735 Autonomous Executive recommendation
-> AP-752 accepted executive allocation handoff
```

This closes the Portfolio feedback loop before Autonomous Executive can make
higher-level tradeoff recommendations.

## Boundary

AP-751 primarily extends AP-733. AP-734 and AP-735 may pass through the same
input and source contracts so the result signal survives the complete Portfolio
and Executive review/allocation chain.

It must not:

- invoke Atlas Dev or Forge;
- call providers;
- create branches or worktrees;
- mutate target repos;
- merge, deploy or push externally;
- touch secrets;
- create a scheduler, executor, runtime or OS;
- bypass Morning Inbox or operator review.

## Input

The health model may receive the AP-750 feed through either:

```text
owner_runtime_result_portfolio_feed
owner_runtime_result_bridge.portfolio_feed
```

Each area signal should contain:

- `area_id`;
- `owner_runtime_result_count`;
- `completed_result_count`;
- `failed_result_count`;
- `partial_result_count`;
- optional `result_ids`;
- `result_health_signal`;
- `recommended_portfolio_action`.

## Output

AP-733 must expose:

- `owner_runtime_result_summary`;
- per-area `owner_runtime_result_signal`;
- Portfolio health counts for completed/failed/partial owner-runtime results;
- risk summary fields for owner-runtime review/follow-up;
- rebalance candidates that prioritize AP-750 review or follow-up before new
  allocation.
- AP-734 inbox items preserving the candidate action/rationale/evidence.
- AP-735 recommendations and AP-752 handoffs preserving AP-751 in
  `source_ap_contracts`.

## Acceptance

- Completed AP-750 result feed produces `review_owner_runtime_result`.
- Failed or partial AP-750 result feed produces `route_owner_runtime_followup`.
- The model includes AP-751 in `source_ap_contracts` and `evidence_refs`.
- AP-734 and AP-735 preserve AP-751 when projecting from raw AP-750 feed input.
- AP-752 routes AP-751 `review_owner_runtime_result` and
  `route_owner_runtime_followup` recommendations to owner review/follow-up,
  not autonomous execution.
- The claim policy stays no-provider, no-Dev/Forge invocation, no branch,
  no merge/deploy, no secrets and operator-review-required.
- Focused Portfolio Health Model, Portfolio Inbox and Autonomous Executive tests
  cover completed/failed owner-runtime result signals and pass-through.
