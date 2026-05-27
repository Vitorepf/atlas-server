---
id: AP-742-product-mode-cockpit-stewardship-history-contract
type: architecture_proposal
title: AP-742 Product Mode Cockpit Stewardship History Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Extends the existing AP-739 Product Mode Cockpit with AP-740 outcome history and AP-741 Domain Runtime Creation Gate handoff packets. It resolves the AP-740/AP-741 visibility gap by reusing ProductModeCockpitSurfaceService, StewardshipOutcomeEvidenceBridgeService and SelfExpandingDomainRuntimeCreationHandoffService; it creates no new cockpit, ledger, inbox, runtime, domain, branch, provider run or executor.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-741-self-expanding-domain-runtime-creation-handoff-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Http/Controllers/Ai/SoftwareCompanyStewardship/ProductModeCockpitController.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php
  - tests/Feature/Ai/SoftwareCompany/ProductModeCockpitControllerTest.php
requires_evidence: true
risk_level: critical
---
# AP-742 Product Mode Cockpit Stewardship History Contract

## Decision

AP-742 closes the Product Mode visibility gap for the upper Atlas Software
Company Stewardship Stack.

Before AP-742, AP-739 showed Area Focus, Executive Decision Inbox, New Area
Proposal Gate and Self-Expanding Software Company review state. AP-740 and
AP-741 existed, but the operator still had to inspect them through separate CLI
commands.

AP-742 keeps AP-739 as the single cockpit and adds two read-only sections:

- `stewardship_outcome_history`, sourced from AP-740;
- `domain_runtime_creation_handoff`, sourced from AP-741.

It also brings AP-740 Morning Inbox projections and AP-741 handoff packets into
the existing `review_queue`, so accepted proposals cannot disappear between
operator decision, evidence, handoff and Domain Runtime Creation Gate review.

## Anti-Duplication Resolution

The feature placement gate reported high overlap with existing product/cockpit
and history services. AP-742 resolves that overlap by reuse:

- no new Product Mode cockpit;
- no new Evidence Ledger;
- no new Morning Inbox;
- no new handoff registry;
- no new domain registry;
- no new runtime, OS, executor, provider flow, Dev flow or Forge flow.

AP-742 is an extension of AP-739. AP-740 remains the outcome/evidence owner.
AP-741 remains the Domain Runtime Creation Gate handoff owner.

## Boundary

AP-742 may:

- read AP-740 projections in default projection-only mode;
- read AP-741 handoff reports in default projection-only mode;
- expose AP-740/AP-741 counters, hashes, claim policies and next actions inside
  the Product Mode Cockpit;
- place AP-740 Morning Inbox projection items into the combined review queue;
- place AP-741 handoff packets into the combined review queue;
- expose operator command anchors for AP-740 `--record-evidence`,
  AP-740 `--emit-inbox` and AP-741 `--record-handoff`.

AP-742 must not:

- record AP-731 decisions;
- write Evidence Ledger events;
- emit Morning Inbox items;
- append AP-741 handoff JSONL packets;
- invoke providers, Atlas Dev or Forge;
- open branches or worktrees;
- create domains, departments, manifests, runtimes or OSes;
- promote L0/L1;
- bypass Domain Runtime Creation Gate;
- merge, deploy, touch secrets or perform destructive actions.

All writes stay behind the explicit AP-740/AP-741 CLI flags and operator
commands. The cockpit is a review surface, not an execution surface.

## Schemas

```text
atlas.software_company.product_mode_cockpit.v1
atlas.software_company.product_mode_cockpit.review_item.v1
atlas.software_company.stewardship_outcome_bridge.v1
atlas.software_company.self_expanding.domain_runtime_creation_handoff.v1
```

## Flow

```text
AP-731 operator decisions
+ AP-738 Self-Expanding Software Company v0
-> AP-740 outcome/evidence projection
-> AP-741 handoff projection
-> AP-739/AP-742 Product Mode Cockpit
-> operator review
-> explicit AP-740/AP-741 commands when approved
-> Domain Runtime Creation Gate review
```

## HTTP

AP-742 does not create a new endpoint. It extends the existing AP-739 endpoint:

```text
GET /ai/software-company-stewardship/product-mode-cockpit/{portfolio}
```

The response includes:

```text
stewardship_outcome_history
domain_runtime_creation_handoff
counters.outcome_evidence_items
counters.outcome_morning_inbox_items
counters.domain_handoff_packets
counters.ready_domain_handoffs
counters.blocked_domain_handoffs
```

The endpoint remains token-protected, read-only and ETag-backed.

## CLI

AP-742 does not create a new command. It extends:

```text
php artisan atlas:software-company-stewardship product-mode-cockpit --json
```

The cockpit now reports AP-740/AP-741 history counters and command anchors.
Side effects remain explicit and owned elsewhere:

```text
php artisan atlas:software-company-stewardship outcome-evidence --record-evidence --actor=<operator> --json
php artisan atlas:software-company-stewardship outcome-evidence --emit-inbox --json
php artisan atlas:software-company-stewardship domain-runtime-creation-handoff --record-handoff --proposal-id=<proposal> --json
```

## Acceptance

- `ProductModeCockpitSurfaceService` includes AP-740/AP-741 in
  `source_ap_contracts`.
- The cockpit emits `stewardship_outcome_history` with AP-740 schema, counters,
  evidence items, Morning Inbox items, bridge hash and claim policy.
- The cockpit emits `domain_runtime_creation_handoff` with AP-741 schema,
  handoff packets, handoff hash, next actions and claim policy.
- AP-740 Morning Inbox projection items appear in the combined review queue
  with `source_ap = AP-740`.
- AP-741 handoff packets appear in the combined review queue with
  `source_ap = AP-741`.
- Operator controls expose AP-740/AP-741 command anchors but do not execute
  them.
- HTTP and CLI reuse the existing Product Mode Cockpit surface.
- Focused tests prove the endpoint and service remain read-only, operator-gated
  and non-executing.
- Claim policy proves no provider, no Dev/Forge, no branch, no domain creation,
  no manifest registration, no merge/deploy/secrets and no auto-promotion.

