---
id: AP-724-area-focus-operator-decision-receipts-contract
type: architecture_proposal
title: AP-724 Area Focus Operator Decision Inbox Receipts
status: accepted
owner: programming
created_at: 2026-05-26
summary: Lets the operator accept, reject, defer or request changes on Area Focus Loop findings, specs, work orders and evidence packs, captured as deterministic operator decision receipts. Read-only / decision-oriented — an accept never executes a branch or fix; it only declares the next allowed action for future slices. No auto-approval, no auto-implementation, no parallel registry.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-718-area-focus-inbox-spec-draft-bridge-contract.md
  - docs/ap/AP-722-area-focus-loop-operational-certification-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOperatorDecisionService.php
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedEvolutionCurationInboxService.php
requires_evidence: true
risk_level: high
---
# AP-724 Area Focus Operator Decision Inbox Receipts

## Decision

The Area Focus operator inbox (AP-718) surfaces findings, specs (AP-718 bridge),
work orders (AP-719) and evidence packs (AP-720). AP-724 lets the operator make
an explicit decision on any of them, captured as a deterministic **operator
decision receipt**.

Atlas Software Company Stewardship Stack é stack/capability family dentro do
Atlas Autonomous Software Company Runtime, não OS novo. AP-724 is a
decision-receipt builder, not a new OS, runtime or parallel registry. It mirrors
the established `SelfDirectedEvolutionCurationInboxService::buildOperatorCuration`
`Receipt()` pattern (operator-owned, never auto-decided) for the Area Focus
domain.

## Allowed Decisions

```text
accept | reject | defer | request_changes
```

An `accept` NEVER executes a branch or fix. It only declares the
`next_allowed_action` so a future slice can act under explicit operator review.

## Inputs

| Field | Required | Notes |
|---|---|---|
| `inbox_item_id` | recommended | AP-718 inbox item id |
| `finding_hash` | yes | deterministic anchor; absent -> blocked |
| `work_order_id` | optional | AP-719 work order |
| `evidence_pack_hash` | optional | AP-720 evidence pack |
| `operator_actor` | yes | empty -> blocked |
| `decision` | yes | must be an allowed decision |
| `rationale` | conditional | required for a high-risk `accept` |
| `risk` / `risk_level` | optional | drives the high-risk rationale gate |

## Receipt Contract

The receipt declares: `schema_version`, `decision_id`, the anchors above,
`operator_actor`, `decision`, `rationale`, `next_allowed_action`, a deterministic
`decision_hash`, a `decided_at` timestamp, and the hard guarantees
`autoapproval_allowed=false`, `autoimplementation_allowed=false`,
`atlas_auto_decided=false`, `executed=false`.

Blocking rules (raise, never silently pass):

- empty `operator_actor`;
- invalid `decision`;
- item without a hash anchor (`finding_hash`);
- high-risk `accept` with no `rationale`.

Schema:

```text
atlas.software_company_stewardship.area_focus_operator_decision_receipt.v1
```

## Acceptance

- Builds a receipt for accept/reject/defer/request_changes.
- Deterministic `decision_hash` + `decision_id` for the same inputs.
- `accept` sets `requires_owner_execution=true`, `executed=false` (no execution).
- Blocks empty actor, invalid decision, missing hash, high-risk accept w/o rationale.
- No auto-approval, no auto-implementation, no parallel registry.
- docs-health and architecture-validate stay green.
