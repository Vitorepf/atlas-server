---
id: AP-738-self-expanding-software-company-v0-contract
type: architecture_proposal
title: AP-738 Self-Expanding Software Company v0 Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Materializes the ceiling of the Atlas Software Company Stewardship Stack as a proposal-only v0 read model. It composes AP-737 New Area Proposal Gate into a Self-Expanding Software Company report that classifies new-domain candidates, existing-capability handoffs, sensitive candidates, operator inbox items and ready-for-Domain-Runtime-Creation-Gate handoffs. AP-739 exposes this top-level operator inbox, AP-740 records outcomes, AP-741 creates gated handoff packets and AP-742 shows AP-740/AP-741 history in the same cockpit. It creates no domain, department, OS, executor, branch, scheduler, Dev/Forge dispatch or autonomous mutation path.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md
  - docs/ap/AP-737-new-area-proposal-gate-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-741-self-expanding-domain-runtime-creation-handoff-contract.md
  - docs/ap/AP-742-product-mode-cockpit-stewardship-history-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-738 Self-Expanding Software Company v0 Contract

## Decision

AP-738 materializes **Self-Expanding Software Company v0** as the top-level,
proposal-only read model for the Atlas Software Company Stewardship Stack.

It answers:

```text
What could the software company expand into next, what is blocked as duplicate
or sensitive, what needs operator review, and what can be handed to the existing
Domain Runtime Creation Gate?
```

It does not create the expansion.

## Boundary

AP-738 must:

- compose AP-737 New Area Proposal Gate;
- preserve AP-730/AP-731/AP-737 anchors and decisions;
- classify proposals into new-domain candidates, existing-capability handoffs,
  sensitive candidates and ready-for-Domain-Runtime-Creation-Gate handoffs;
- expose a self-expansion operator inbox;
- document the expansion loop;
- prove that Self-Expanding Software Company is the ceiling of this stack, not a
  new OS or Holding-level runtime;
- never create domains, departments, branches, worktrees, provider calls,
  Dev/Forge dispatches, merges, deploys or secret access.

## Schemas

```text
atlas.software_company.self_expanding.v0
atlas.software_company.self_expanding.operator_inbox.v1
atlas.software_company.self_expanding.operator_inbox_item.v1
```

## Expansion Loop

```text
detect_portfolio_gap
-> dedupe_existing_capability_owner
-> draft_new_area_proposal
-> apply_ap737_new_area_proposal_gate
-> collect_ap731_operator_decision
-> handoff_to_domain_runtime_creation_gate_or_area_stewardship
-> record_evidence_before_any_promotion
```

## Classes

| Class | Meaning |
|---|---|
| `new_domain_candidates` | Candidate does not match a known owner and may proceed to AP-737 review. |
| `existing_capability_handoffs` | Candidate already exists and should become Area/Portfolio Stewardship, not a new domain. |
| `sensitive_candidates` | Candidate requires safety/sovereignty review before any accept can be meaningful. |
| `ready_for_domain_runtime_creation_gate` | Operator accepted via AP-731 and AP-737 blockers are clear. |

## CLI

```text
php artisan atlas:software-company-stewardship self-expanding-v0 --json
php artisan atlas:software-company-stewardship self-expanding-v0 --proposal-id=<proposal> --json
```

## Acceptance

- `SelfExpandingSoftwareCompanyService` emits
  `atlas.software_company.self_expanding.v0`.
- Report composes AP-737 and keeps proposal-only boundaries.
- Existing Atlas capabilities route to Area/Portfolio Stewardship, not domain
  creation.
- Sensitive candidates are separated for explicit safety/sovereignty review.
- Accepted AP-737 proposals appear as ready for Domain Runtime Creation Gate
  handoff only.
- CLI exposes `self-expanding-v0`.
- AP-739 exposes the Self-Expanding operator inbox in Product Mode/Cockpit
  without creating or promoting any expansion.
- AP-740 bridges AP-738 outcomes to Evidence Ledger and Morning Inbox without
  creating or promoting any expansion.
- AP-741 turns accepted/evidenced AP-738 proposals into handoff packets without
  creating or promoting any expansion.
- AP-742 exposes AP-738/AP-740/AP-741 history in the existing Product Mode
  Cockpit without moving write authority into the cockpit.
- Focused tests prove classification, no mutation/runtime creation, deterministic
  hash and AP-731/AP-737 decision composition.
