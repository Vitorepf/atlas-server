---
id: AP-740-stewardship-outcome-evidence-and-morning-inbox-contract
type: architecture_proposal
title: AP-740 Stewardship Outcome Evidence And Morning Inbox Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Bridges AP-731 Stewardship Evolution operator decisions, AP-738 Self-Expanding Software Company v0 outcomes and AP-747 Dev/Forge release outcomes into the canonical Evidence Ledger and Morning Inbox. AP-740 explicitly reuses AtlasEvidenceLedger, atlas_ledger_events and ProposalInboxEmitter; AP-748 extends it with release outcome/portfolio feed consumed by AP-749; AP-750 later bridges owner runtime result receipts back into the same Evidence/Morning Inbox/Portfolio flow; AP-741 consumes recorded evidence to create Domain Runtime Creation handoff packets; AP-742 shows projected/recorded AP-740 history inside Product Mode/Cockpit. It does not create a new ledger, inbox, runtime, OS, domain, branch or executor.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-738-self-expanding-software-company-v0-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-741-self-expanding-domain-runtime-creation-handoff-contract.md
  - docs/ap/AP-742-product-mode-cockpit-stewardship-history-contract.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyService.php
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Services/Ai/Mobile/ProposalInboxEmitter.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-740 Stewardship Outcome Evidence And Morning Inbox Contract

## Decision

AP-740 closes the first real outcome loop for the upper Atlas Software Company
Stewardship Stack.

It takes:

- AP-731 operator decisions from the append-only Stewardship Evolution JSONL
  ledger;
- AP-738 Self-Expanding Software Company v0 review items;
- AP-747 Dev/Forge release queue records through AP-748;

and projects them into:

- canonical Evidence Ledger events through `AtlasEvidenceLedger`;
- Morning Inbox proposal items through `ProposalInboxEmitter`.
- Portfolio Stewardship input signals for AP-733.
- AP-749 owner-specific consumption gate input signals.
- AP-750 owner-runtime result bridge input/output semantics after Dev/Forge reports back.

This resolves the feature placement overlap by reuse. The bridge is not a new
ledger, inbox, runtime, OS or execution surface.

## Boundary

AP-740 may:

- read AP-731 decision summaries;
- read AP-738 self-expanding proposal-only outputs;
- read AP-747 projected or recorded release outcomes;
- project deterministic AP-740 evidence payloads;
- append idempotent `EVIDENCE_PACKED` events to `atlas_ledger_events` when the
  operator passes an explicit record flag;
- emit deduped Morning Inbox proposal items when the operator passes an
  explicit inbox flag;
- expose the bridge through the existing
  `atlas:software-company-stewardship` CLI.

AP-740 must not:

- create a parallel Evidence Ledger;
- create a parallel Morning Inbox;
- create domains, departments, branches, worktrees or runtimes;
- invoke providers, Atlas Dev or Forge;
- auto-promote AP-738 proposals into Domain Runtime Creation Gate;
- merge, deploy, touch secrets or perform destructive actions.

## Schemas

```text
atlas.software_company.stewardship_outcome_bridge.v1
atlas.software_company.stewardship_outcome_evidence.v1
atlas.software_company.stewardship_morning_inbox_item.v1
```

## Flow

```text
AP-731 operator decision ledger
+ AP-738 self-expanding v0
-> AP-740 outcome bridge
-> Evidence Ledger EVIDENCE_PACKED events
-> Morning Inbox proposal items
-> operator review
-> AP-741 handoff to Domain Runtime Creation Gate only after gates pass
```

## CLI

Read-only projection:

```text
php artisan atlas:software-company-stewardship outcome-evidence --json
```

Append Evidence Ledger events:

```text
php artisan atlas:software-company-stewardship outcome-evidence --record-evidence --actor=<operator> --json
```

Emit Morning Inbox items:

```text
php artisan atlas:software-company-stewardship outcome-evidence --emit-inbox --json
```

Both side effects are explicit and idempotent.

## Acceptance

- `StewardshipOutcomeEvidenceBridgeService` emits
  `atlas.software_company.stewardship_outcome_bridge.v1`.
- AP-731 decisions become deterministic AP-740 evidence items.
- AP-738 self-expanding reports become deterministic AP-740 evidence items.
- Evidence Ledger writes reuse `AtlasEvidenceLedger` and `atlas_ledger_events`.
- Morning Inbox writes reuse `ProposalInboxEmitter`.
- Default mode is projection-only and has no side effects.
- `--record-evidence` is idempotent by deterministic `event_id`.
- `--emit-inbox` is deduped by deterministic inbox `dedupe_key`.
- AP-748 release outcomes produce Evidence, Morning Inbox and Portfolio feed
  projections without invoking Dev/Forge.
- AP-750 remains the post-consumption owner result bridge; AP-740/AP-748 do not
  claim completed Dev/Forge execution without an AP-750 result receipt.
- Claim policy proves no provider, no Dev/Forge, no branch, no domain creation,
  no merge/deploy/secrets and no auto-promotion.
- AP-741 consumes AP-740 recorded evidence and does not auto-promote any proposal.
- AP-742 exposes AP-740 history in the existing Product Mode/Cockpit without
  moving AP-740 write authority into the cockpit.
