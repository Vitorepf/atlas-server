---
id: AP-748-stewardship-release-outcome-bridge-contract
type: architecture_proposal
title: AP-748 Stewardship Release Outcome Bridge Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Extends the existing AP-740 outcome bridge so AP-747 Dev/Forge operator-owned release records become canonical Evidence Ledger items, Morning Inbox review items and Portfolio Stewardship input signals. AP-749 consumes those signals before owner-specific Dev/Forge runtime input; AP-750 later bridges the owner runtime result back into Evidence, Morning Inbox and Portfolio. AP-748 reuses AP-740, AP-747, Product Mode, Evidence, Morning Inbox and Portfolio owners; it creates no new runtime, executor, OS, branch, provider call, merge, deploy or secret access.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-748 Stewardship Release Outcome Bridge Contract

## Decision

AP-748 closes the loop immediately after AP-747.

AP-747 releases an AP-726 branch-sandbox handoff into a real Atlas Dev or Forge
owner queue only after an explicit operator receipt. AP-748 makes that release
visible to the rest of the Stewardship Stack by extending AP-740:

```text
AP-747 owner queue release record
-> AP-748 release outcome bridge
-> AP-740 Evidence Ledger / Morning Inbox owners
-> Portfolio Stewardship release signal
-> Product Mode review before owner-specific Dev/Forge execution
-> AP-749 owner-specific queue consumption gate
-> AP-750 owner runtime result bridge
```

This is not Dev/Forge execution. It is outcome accounting and operator review
visibility for a release that already crossed the AP-747 gate.

## Anti-Duplication Resolution

Feature placement reports high overlap with AP-740, AP-747, Product Mode,
Evidence, Morning Inbox, Portfolio and several release gates. The overlap is
intentional. AP-748 resolves it by extending the existing AP-740 bridge and
Portfolio Health Model instead of creating a new ledger, inbox, runtime,
executor, surface or OS.

## Boundary

AP-748 may:

- read AP-747 projected or recorded release reports;
- emit deterministic `ap747_owner_queue_release` evidence projections;
- write Evidence Ledger events only when AP-740 `record_evidence` is explicit;
- emit Morning Inbox items only when AP-740 `emit_inbox` is explicit;
- produce `release_outcome_summary`;
- produce `portfolio_feed` for AP-733 Portfolio Health Model;
- expose the input through `atlas:software-company-stewardship outcome-evidence --release-file=<json-or-jsonl>`.

AP-748 must not:

- invoke Atlas Dev or Forge runtime execution;
- create branches, worktrees, commits or patches;
- call providers;
- merge, deploy, push externally, access secrets or perform destructive changes;
- create a new runtime, scheduler, ledger, inbox or OS;
- promote a release without Product Mode / operator review.

## Schemas

```text
atlas.software_company.stewardship_release_outcome_summary.v1
atlas.software_company.stewardship_release_portfolio_feed.v1
atlas.portfolio_stewardship.release_outcome_summary.v1
```

AP-748 reuses:

```text
atlas.software_company.stewardship_outcome_bridge.v1
atlas.software_company.stewardship_outcome_evidence.v1
atlas.software_company.stewardship_morning_inbox_item.v1
atlas.software_company_stewardship.area_focus_dev_forge_release_record.v1
```

## CLI

Projection:

```text
php artisan atlas:software-company-stewardship outcome-evidence \
  --release-file=/path/to/ap747-release.jsonl \
  --json
```

Record Evidence Ledger events:

```text
php artisan atlas:software-company-stewardship outcome-evidence \
  --release-file=/path/to/ap747-release.jsonl \
  --record-evidence \
  --actor=<operator> \
  --json
```

Emit Morning Inbox items:

```text
php artisan atlas:software-company-stewardship outcome-evidence \
  --release-file=/path/to/ap747-release.jsonl \
  --emit-inbox \
  --json
```

## Acceptance

- AP-740 report includes AP-747/AP-748 in `source_ap_contracts` when release
  reports are supplied.
- AP-747 release reports become deterministic AP-748 evidence items.
- AP-747 release reports become Morning Inbox items requiring owner-specific
  execution review.
- AP-740 produces `portfolio_feed` with owner queue pending counts.
- AP-733 Portfolio Health consumes the feed and prioritizes owner queue review.
- AP-749 consumes the AP-748 report before any owner runtime input is accepted.
- AP-750 consumes AP-749 plus owner runtime result receipts before completed
  execution outcomes feed Portfolio follow-up decisions.
- Default mode remains projection-only.
- `record_evidence` and `emit_inbox` remain explicit and idempotent.
- Claim policy proves no Dev/Forge invocation, provider call, branch, merge,
  deploy, secret access, destructive change, new OS or parallel runtime.
