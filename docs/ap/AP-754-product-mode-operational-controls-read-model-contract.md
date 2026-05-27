---
id: AP-754-product-mode-operational-controls-read-model-contract
type: architecture_proposal
title: AP-754 Product Mode Operational Controls Read Model Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Adds the first Product Mode operational controls read model to the existing AP-739 cockpit: repository onboarding state, autonomy tier policy, budget/rate limits, kill switch/pause/lock state, branch review center, evidence inspector and risk policy. AP-755 can now feed this projection with accepted AP-731 Product Mode control receipts. It is read-only/projection-only and creates no new runtime, scheduler, provider path, branch/worktree, repo authorization mutation, tier mutation, merge, deploy or secret access.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/ap/AP-711-night-shift-product-mode-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-755-product-mode-operational-control-receipts-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php
  - tests/Feature/Ai/SoftwareCompany/ProductModeCockpitControllerTest.php
requires_evidence: true
risk_level: critical
---
# AP-754 Product Mode Operational Controls Read Model Contract

## Decision

AP-754 makes Night Shift Product Mode more product-like without turning the
cockpit into an executor.

It extends the existing AP-739 Product Mode/Cockpit with a read-only operational
controls projection:

- repository onboarding state;
- autonomy tier status;
- budget and WIP limits;
- kill switch, pause, lock and rate-limit state;
- branch review center;
- evidence pack inspector;
- risk policy summary.

## Boundary

AP-754 may:

- project whether a repository is authorized for Product Mode;
- show the requested and max allowed autonomy tiers;
- show budget, WIP, provider-call and cadence blockers;
- show branch review counts and evidence gaps;
- add AP-754 review items to AP-739;
- expose CLI/HTTP/cockpit read models.
- consume AP-755 accepted control receipt projections.

AP-754 must not:

- authorize repositories;
- change autonomy tiers;
- update budgets or risk policy;
- toggle kill switches;
- invoke providers, Dev or Forge;
- create branches or worktrees;
- merge, deploy or touch secrets;
- install schedulers;
- mutate target repos.

## Schema

```text
atlas.software_company.product_mode_operational_controls.v1
atlas.software_company.product_mode.repo_onboarding.v1
atlas.software_company.product_mode.autonomy_tiers.v1
atlas.software_company.product_mode.budget_policy.v1
atlas.software_company.product_mode.safety_controls.v1
atlas.software_company.product_mode.branch_review_center.v1
atlas.software_company.product_mode.branch_review_item.v1
atlas.software_company.product_mode.evidence_inspector.v1
atlas.software_company.product_mode.risk_policy.v1
atlas.software_company.product_mode_control_policy_projection.v1
```

## AP-780 Branch Review Packets

The branch review center may consume AP-780
`atlas.software_company_stewardship.branch_review_packet.v1` packets through
`branch_review_packets[]`. Product Mode projects them as read-only
`branch_review_item` entries with:

- branch/base refs and commits;
- changed files and reviewable commits for GitKraken;
- cycle traceability (`finding_id`, `spec_id`, `receipt_id`, `handoff_id`,
  `sandbox_id` when present);
- risk summary;
- safe decision options;
- blockers.

Product Mode still cannot merge. Merge execution remains owned by AP-769/AP-772
and their ff-only policies.

## Flow

```text
AP-711 Product Mode target
-> AP-754 operational controls read model
-> AP-739 Product Mode/Cockpit aggregate
-> operator review
-> owner runtimes perform any real execution under their own gates
```

## CLI

```text
php artisan atlas:software-company-stewardship product-mode-controls --json
php artisan atlas:software-company-stewardship product-mode-controls --use-recorded-controls --json
php artisan atlas:software-company-stewardship product-mode-cockpit --json
```

The `product-mode-controls` action is read-only. It can project flags supplied
by an operator, scheduler wrapper or accepted AP-755 receipts, but it does not
persist them.

## Acceptance

- `ProductModeOperationalControlsReadModelService` emits
  `atlas.software_company.product_mode_operational_controls.v1`.
- AP-739 includes `product_mode_operational_controls` and AP-754 in
  `source_ap_contracts`.
- AP-739 review queue includes AP-754 items when controls are blocked or review
  is required.
- CLI exposes `product-mode-controls`.
- CLI can apply accepted AP-755 receipts through `--use-recorded-controls`.
- HTTP Product Mode/Cockpit returns the AP-754 section.
- Claim policy proves no repo mutation, no tier mutation, no branch creation,
  no provider call, no Dev/Forge invocation, no merge, no deploy and no secret
  access.
- Focused unit/feature tests cover ready, review and blocked control states.
