---
id: AP-752-autonomous-executive-allocation-handoff-contract
type: architecture_proposal
title: AP-752 Autonomous Executive Allocation Handoff Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Adds the governed handoff gate after AP-735 Autonomous Executive recommendations and AP-731 operator accept receipts. It converts an accepted executive recommendation into an operator-reviewable allocation handoff packet for the correct owner: owner-runtime result review, Area Stewardship follow-up, Area Focus/Dev/Forge release preparation, or Product Mode review. It creates no executor, scheduler, provider call, branch, worktree, merge, deploy, secret access, budget spend or autonomous mutation path.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-735-autonomous-executive-recommendation-contract.md
  - docs/ap/AP-736-executive-decision-inbox-surface-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - docs/ap/AP-753-product-mode-cockpit-executive-allocation-handoff-visibility-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-752 Autonomous Executive Allocation Handoff Contract

## Decision

AP-752 materializes the first governed step after an Autonomous Executive
recommendation is accepted by the operator.

It answers:

```text
Given an AP-735 executive recommendation and an AP-731 accept receipt, which
existing owner should receive the next reviewable allocation packet?
```

## Boundary

AP-752 is a handoff gate, not an executive executor.

It must reuse:

- AP-735 Autonomous Executive Recommendation packs as the recommendation source;
- AP-731 Stewardship Evolution Decision Ledger as the only accept authority;
- AP-736 Executive Decision Inbox as the review surface before acceptance;
- AP-751 owner-runtime result signals when the recommendation is based on
  completed/failed/partial owner work;
- Area Stewardship, Area Focus, Product Mode, Atlas Dev, Forge and Evidence
  owners as downstream authorities.

It must not create a new Executive OS, scheduler, company runtime, brancher,
worktree allocator, provider caller, Dev/Forge dispatcher, merge/deploy path,
secret access path, budget spend path, auto-approval path or permissionless
promotion.

## Schemas

```text
atlas.autonomous_executive.allocation_handoff.v1
atlas.autonomous_executive.allocation_handoff_packet.v1
atlas.autonomous_executive.allocation_handoff_ledger.v1
```

## Required Input

AP-752 must accept one of:

- a cold `executive_pack` already emitted by AP-735;
- a stable `pack_id` that can be replayed from the AP-735 ledger;
- normal AP-735 projection input when no recorded pack is available.

The caller must select one recommendation through:

- `recommendation_id`; or
- a pack containing exactly one recommendation.

The selected recommendation must have a latest AP-731 decision with:

```text
target_type: autonomous_executive
target_id: <recommendation.target_id>
target_hash: <recommendation.target_hash>
decision: accept
```

If the latest decision is missing, rejected, deferred or change-requested, the
handoff must block.

## Required Output

The handoff report must include:

- `portfolio_id`;
- `area_id`;
- AP-735 source pack anchors (`source_pack_id`, `source_pack_hash`);
- AP-735 recommendation anchors (`source_recommendation_id`, `target_id`,
  `target_hash`);
- AP-731 accept receipt anchor (`source_decision_id`, `decision_hash`);
- one `allocation_handoff_packet` when the accept gate is satisfied;
- `target_owner`;
- `target_owner_doc`;
- `recommended_action`;
- `target_area`;
- `handoff_status`;
- `required_gate_sequence`;
- `allowed_next_actions`;
- `forbidden_actions`;
- source AP contracts, including AP-751 when owner-runtime result signals drove
  the recommendation;
- no-mutation claim policy;
- deterministic `handoff_hash`.

## Routing

The selected recommendation routes as follows:

| Recommended action | Target owner |
| --- | --- |
| `review_owner_runtime_result` | Portfolio/Area owner-runtime result review |
| `route_owner_runtime_followup` | Area Stewardship follow-up |
| `allocate_next_governed_cycle` | Area Stewardship / Area Focus active cycle preparation |
| anything else | Product Mode/Cockpit operator review |

Routing is advisory. The target owner must run its own AP-specific gate before
any execution, branch creation, provider call, Dev/Forge queue consumption,
merge, deploy, secret access or destructive action.

## Persistence

`executive-allocation-handoff --record-allocation-handoff` may write only
append-only JSONL under:

```text
storage/atlas/software_company_stewardship/executive_allocation_handoffs/<portfolio_id>.jsonl
```

Recording must be idempotent by `handoff_packet_id`.

## Acceptance

- `AutonomousExecutiveAllocationHandoffService` emits the report and packet
  schemas.
- The service blocks when AP-735 is missing/blocked.
- The service blocks when the selected recommendation has no latest AP-731
  `accept` receipt.
- The service routes AP-751 owner-runtime result review/follow-up actions to
  owner review/follow-up, not to autonomous execution.
- Recording is append-only and idempotent.
- CLI exposes `executive-allocation-handoff`,
  `executive-allocation-handoff-list` and
  `executive-allocation-handoff-replay`.
- AP-753 exposes AP-752 packets in Product Mode/Cockpit without executing them.
- Tests prove no repo/provider/Dev/Forge/branch/worktree/merge/deploy/secrets
  behavior.
