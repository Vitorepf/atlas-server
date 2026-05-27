---
id: AP-739-product-mode-cockpit-stewardship-review-contract
type: architecture_proposal
title: AP-739 Product Mode Cockpit Stewardship Review Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Integrates AP-736 Executive Decision Inbox, AP-737 New Area Proposal Gate, AP-738 Self-Expanding Software Company v0, AP-740/AP-748 outcome history, AP-741 handoff packets, AP-743 Area Stewardship active handoff packets, AP-744 active operation projections, AP-745 Continuous Stewardship Loop status, AP-746 recurring scheduler state, AP-747 release outcomes, AP-749 owner-consumption controls, AP-759 owner sandbox runtime visibility, AP-750 owner-runtime result review, AP-752 executive allocation handoff visibility, AP-754 Product Mode operational controls and AP-761 Desktop end-to-end console rendering into the existing Night Shift Product Mode/Cockpit surface for the Atlas Software Company Stewardship Stack. The cockpit is read-only: it aggregates review queues, counters, health, operator commands and handoff boundaries without recording decisions, invoking Dev/Forge, executing AP-759, opening branches, creating domains or promoting runtimes.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/ap/AP-736-executive-decision-inbox-surface-contract.md
  - docs/ap/AP-737-new-area-proposal-gate-contract.md
  - docs/ap/AP-738-self-expanding-software-company-v0-contract.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-760-product-mode-owner-sandbox-runtime-visibility-contract.md
  - docs/ap/AP-761-product-mode-desktop-end-to-end-stewardship-console-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-752-autonomous-executive-allocation-handoff-contract.md
  - docs/ap/AP-753-product-mode-cockpit-executive-allocation-handoff-visibility-contract.md
  - docs/ap/AP-754-product-mode-operational-controls-read-model-contract.md
  - docs/ap/AP-741-self-expanding-domain-runtime-creation-handoff-contract.md
  - docs/ap/AP-742-product-mode-cockpit-stewardship-history-contract.md
  - docs/ap/AP-743-area-stewardship-active-handoff-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - docs/ap/AP-745-continuous-stewardship-loop-scheduler-safe-contract.md
  - docs/ap/AP-746-continuous-stewardship-recurring-scheduler-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipRecurringSchedulerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelService.php
  - app/Http/Controllers/Ai/SoftwareCompanyStewardship/ProductModeCockpitController.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - routes/api.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php
  - tests/Feature/Ai/SoftwareCompany/ProductModeCockpitControllerTest.php
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/StewardshipSurface.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/types.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/model.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/stewardship.css
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/__tests__/stewardshipCockpitContract.test.ts
requires_evidence: true
risk_level: critical
---
# AP-739 Product Mode Cockpit Stewardship Review Contract

## Decision

AP-739 makes **Night Shift Product Mode** the visual review cockpit for the
upper Atlas Software Company Stewardship Stack.

It composes:

- AP-721 Area Focus Product Mode;
- AP-736 Executive Decision Inbox;
- AP-737 New Area Proposal Gate;
- AP-738 Self-Expanding Software Company v0;
- AP-740/AP-748 Stewardship Outcome Evidence, Morning Inbox and release outcome history;
- AP-749 owner-specific Dev/Forge queue consumption controls;
- AP-759 owner sandbox runtime runner visibility through AP-760;
- AP-750 owner-runtime result review controls;
- AP-752 executive allocation handoff packets;
- AP-754 Product Mode operational controls;
- AP-741 Domain Runtime Creation Gate handoff packets;
- AP-743 Area Stewardship active handoff packets.
- AP-744 Area Stewardship active operation projections.
- AP-745 Continuous Stewardship Loop scheduler-safe tick status.
- AP-746 Continuous Stewardship recurring scheduler state.

It exists because AP-736/AP-737/AP-738/AP-740/AP-741/AP-743/AP-744/AP-745/AP-746/AP-747/AP-748/AP-749/AP-759/AP-750/AP-752/AP-754 are
service models, but still need one Product Mode/Cockpit surface so the operator
can see the executive recommendation, expansion blockers, self-expanding inbox,
outcome history, handoff packets, Continuous Stewardship tick state and
recurring scheduler state and operational control state in one governed review
view.

## Boundary

AP-739 is visual aggregation only.

It may:

- read AP-721/AP-736/AP-737/AP-738/AP-740/AP-741/AP-743/AP-744/AP-745/AP-746/AP-747/AP-748/AP-749/AP-759/AP-750/AP-752/AP-754 projections;
- normalize a combined review queue;
- expose ETag-backed HTTP read models;
- render the review queue in Atlas Desktop;
- show the exact AP-731 command anchors that record decisions elsewhere.

It must not:

- record AP-731 decisions;
- invoke providers;
- invoke Atlas Dev or Forge;
- open branches or worktrees;
- create domains, departments or runtime owners;
- bypass Domain Runtime Creation Gate;
- merge, deploy, touch secrets or auto-promote anything.

## Schema

```text
atlas.software_company.product_mode_cockpit.v1
atlas.software_company.product_mode_cockpit.review_item.v1
```

## Flow

```text
Area Focus Product Mode
-> Executive Decision Inbox
-> New Area Proposal Gate
-> Self-Expanding Software Company v0
-> Outcome history + Domain Runtime Creation + Area Stewardship handoff packets
-> Product Mode Cockpit review queue
-> operator decision via AP-731-owned commands
```

## HTTP

```text
GET /ai/software-company-stewardship/product-mode-cockpit/{portfolio}
```

Query parameters:

| Parameter | Meaning |
|---|---|
| `area` / `area_id` | Area to focus; default `agentic_engineering_os`. |
| `pack_id` | Optional AP-735 executive recommendation pack replay anchor. |
| `proposal_id` | Optional AP-737 proposal filter. |

The endpoint returns `ETag` and supports `If-None-Match`.

## CLI

```text
php artisan atlas:software-company-stewardship product-mode-cockpit --json
php artisan atlas:software-company-stewardship product-mode-cockpit --area=agentic_engineering_os --portfolio=atlas_software_company --json
```

## Desktop Surface

Atlas Desktop registers the `stewardship` surface as the Product Mode cockpit
for this stack. It is slate-dark, token-only and read-only. It displays:

- stack status and no-new-OS boundary;
- Area Focus health and WIP;
- Executive review queue from AP-736;
- New Area Proposal Gate blockers from AP-737;
- Self-Expanding operator inbox from AP-738;
- Outcome history from AP-740/AP-748;
- Domain Runtime Creation Gate handoff packets from AP-741;
- Area Stewardship active handoff packets from AP-743;
- Area Stewardship active operation queues from AP-744;
- Continuous Stewardship Loop status and command anchors from AP-745;
- Continuous Stewardship recurring scheduler state and command anchors from AP-746;
- Owner sandbox runtime command plan/result visibility from AP-759/AP-760;
- Owner-runtime result bridge review from AP-750;
- Executive allocation handoff packets from AP-752;
- Product Mode operational controls from AP-754;
- End-to-end Desktop console rendering from AP-761;
- AP-731 command anchors and safety policy.

## Acceptance

- `ProductModeCockpitSurfaceService` emits
  `atlas.software_company.product_mode_cockpit.v1`.
- HTTP route is token-protected, read-only and ETag-backed.
- CLI exposes `product-mode-cockpit`.
- Atlas Desktop exposes a `stewardship` Product Mode/Cockpit surface.
- AP-761 makes the Desktop surface render the end-to-end runtime pipeline, not
  only the upper recommendation queue.
- Review items from AP-736/AP-737/AP-738/AP-740/AP-741/AP-743/AP-744/AP-745/AP-746/AP-747/AP-748/AP-759/AP-750/AP-752/AP-754 are visible in one queue.
- All claim policies prove no execution, no provider call, no branch, no domain
  creation, no merge/deploy/secrets and no auto-promotion.
- Focused PHP tests and Desktop build/typecheck cover the integration.
