---
id: AP-713-area-stewardship-layer-contract
type: architecture_proposal
title: AP-713 Atlas Area Stewardship Layer Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Canonizes Atlas Area Stewardship Layer as the layer above Area Focus Loop: Atlas continuously owns the health, roadmap, prioritization, Dev/Forge routing, evidence and operator decision inbox for a chosen area without creating a new OS or bypassing Night Shift/Product Mode safety gates. AP-743 adds the active handoff packet after AP-732 readiness; AP-744 consumes that packet and runs the first governed active operating slice without provider calls, branch creation, Dev/Forge dispatch, repo mutation, merge, deploy or secrets; AP-745 wraps the active slice in a disabled-by-default scheduler-safe tick; AP-746 wraps AP-745 in a recurring scheduler-safe runner; AP-747 releases AP-726 handoffs to Dev/Forge queues; AP-748 records visibility; AP-749 gates owner consumption; AP-758 adapts ready consumption into an AP-750-compatible owner result; AP-759 executes approved owner CLI commands inside AP-756 sandbox; AP-760/AP-761 make the run visible in Product Mode/Desktop; AP-750 bridges owner runtime results.
related_paths:
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/ap/AP-732-area-stewardship-promotion-readiness-gate-contract.md
  - docs/ap/AP-743-area-stewardship-active-handoff-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - docs/ap/AP-745-continuous-stewardship-loop-scheduler-safe-contract.md
  - docs/ap/AP-746-continuous-stewardship-recurring-scheduler-contract.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-760-product-mode-owner-sandbox-runtime-visibility-contract.md
  - docs/ap/AP-761-product-mode-desktop-end-to-end-stewardship-console-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipPromotionReadinessService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php
requires_evidence: true
risk_level: critical
---
# AP-713 Atlas Area Stewardship Layer Contract

## Decision

Atlas Area Stewardship Layer is the canonical next layer above Area Focus Loop.
Area Focus Loop runs improvement cycles. Area Stewardship makes Atlas
responsible for continuous health, roadmap, prioritization, execution routing,
outcome measurement and operator decisions for a chosen area.

It is not a new OS. It is a stewardship/control layer over Night Shift Product
Mode, Self-Directed Evolution, Atlas Dev, Forge, Self-Construction, Evidence and
Morning Inbox.

## Core Contract

The operator assigns an `area_id`. Atlas maintains:

- area health model;
- live finding backlog;
- roadmap candidates;
- Dev/Forge routing queue;
- evidence and trust signals;
- operator decision inbox;
- improvement memory.

## Non-Negotiables

- no merge without operator;
- no deploy without operator;
- no secrets;
- no destructive change;
- no parallel runtime or proposal registry;
- all implementation routes through existing Dev, Forge or Self-Construction;
- all specs route through Self-Directed Evolution or existing Spec OS owners;
- all claims require evidence.

## Acceptance

- A canonical doc defines Area Stewardship.
- Area Focus Loop remains the execution cycle.
- Product Mode remains the cockpit/control surface.
- Area Stewardship owns continuous responsibility, not permissionless autonomy.
- Agentic Engineering OS is the first target area.
- AP-743 creates a reviewable active handoff packet after AP-732 readiness, but
  it does not start execution, invoke Dev/Forge, create branches or mutate repos.
- AP-744 consumes the AP-743 packet and runs the first active operating slice by
  reusing AP-722, AP-718 and AP-726. It prepares operation queues, spec drafts
  and Dev/Forge preflight handoffs, but still performs no irreversible action
  without operator review.
- AP-745 wraps AP-744 in a scheduler-safe Continuous Stewardship Loop tick with
  disabled-by-default admission, kill switch, lock lease, rate limit and
  append-only JSONL recording. It does not install a scheduler or gain mutation
  authority.
- AP-746 wraps AP-745 in a recurring scheduler-safe runner with pause policy,
  idempotent scheduler evidence and no scheduler installation.
- AP-747 releases AP-726 handoffs to Atlas Dev/Forge owner queues only with a
  matching operator release receipt. It still does not create branches, invoke
  providers, mutate repos, merge, deploy or touch secrets.
- AP-749 gates owner-specific consumption only after AP-748 Evidence,
  Morning Inbox and Portfolio visibility plus an operator execution receipt.
- AP-758 is the governed adapter between ready AP-749 consumption and AP-750:
  it reuses existing Atlas Dev/Forge projections and emits an AP-750-compatible
  owner result without creating a runtime, provider path, branch/worktree,
  merge, deploy, push, secret access or destructive action.
- AP-759 is the governed runner for the first real owner CLI command: it runs
  only allowlisted Atlas Dev/Forge commands inside the AP-756 worktree under an
  explicit operator command receipt.
- AP-760/AP-761 make the AP-759/AP-750 path visible in Product Mode and Atlas
  Desktop without giving the cockpit execution authority.
- AP-750 bridges the eventual Atlas Dev/Forge owner runtime result back into
  Evidence, Morning Inbox and Portfolio before merge/deploy/follow-up review.
