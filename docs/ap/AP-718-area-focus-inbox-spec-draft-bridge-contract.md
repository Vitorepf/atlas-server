---
id: AP-718-area-focus-inbox-spec-draft-bridge-contract
type: architecture_proposal
title: AP-718 Area Focus Inbox and Self-Directed Evolution Spec Draft Bridge
status: accepted
owner: programming
created_at: 2026-05-26
summary: Turns Area Focus Loop findings into operator-reviewable inbox items and proposal-only spec drafts inside the Atlas Software Company Stewardship Stack (Night Shift Product Mode), by reusing the Self-Directed Evolution Curation Inbox and Spec Proposal Adapter. Read-only and proposal-only — no canonical writes, no auto-approval, no auto-implementation, no parallel proposal registry.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusInboxService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusSpecDraftBridge.php
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedEvolutionCurationInboxService.php
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedSpecProposalAdapter.php
requires_evidence: true
risk_level: high
---
# AP-718 Area Focus Inbox and Self-Directed Evolution Spec Draft Bridge

## Decision

The Area Focus Loop (AP-712) produces findings. AP-718 adds the next governed
step: those findings become operator-reviewable **inbox items** and, on demand,
proposal-only **spec drafts**.

Atlas Software Company Stewardship Stack é stack/capability family dentro do
Atlas Autonomous Software Company Runtime, não OS novo. AP-718 is a read-only /
proposal-only bridge slice inside that stack. It is not a new OS, not a parallel
runtime and not a new proposal registry.

## Reuse Contract

AP-718 must reuse existing owners and create no parallel authority:

- operator-review inbox semantics reuse
  `SelfDirectedEvolutionCurationInboxService` (dedupe, sort, pending review,
  operator actions, receipt building);
- spec drafting reuses `SelfDirectedSpecProposalAdapter::draft()`;
- a finding is mapped onto the existing `atlas.evolution.gap_candidate.v1` shape
  (`candidate_hash = finding_hash`) so both owners are reused natively;
- no new proposal/backlog registry is created and nothing is persisted.

## Schemas

```text
atlas.software_company_stewardship.area_focus_inbox.v1
atlas.software_company_stewardship.area_focus_inbox_item.v1
atlas.software_company_stewardship.area_focus_spec_draft.v1
```

## Inbox Item Contract

Every `area_focus_inbox_item.v1` must declare:

- `finding_hash`;
- `area_id`;
- `route_hint`;
- `spec_draftable`;
- `risk`;
- `operator_decision_required: true`.

Items are deduplicated by `finding_hash` and the inbox carries a deterministic
`inbox_hash`.

## Spec Draft Bridge Contract

`AreaFocusSpecDraftBridge::draftFromFinding()` must:

- require a valid finding (a missing `finding_hash` is blocked);
- produce a draft payload via the reused Spec Proposal Adapter;
- never write a canonical doc, AP or owner service;
- keep `operator_approval_required = true`, `canonical_doc_write_allowed = false`,
  `autoapproval_allowed = false`, `autoimplementation_allowed = false`,
  `provider_invoked = false`.

## Acceptance

- Inbox projects `area_focus_inbox.v1` with the mandated item fields.
- Deterministic `inbox_hash` for the same findings.
- Dedupe by `finding_hash`.
- Every item is `operator_decision_required = true` with `autoapproval_allowed = false`.
- Spec draft is produced from a finding and is proposal-only.
- Invalid finding (no `finding_hash`) is blocked.
- No parallel proposal registry; both owners reused.
- docs-health and architecture-validate stay green.
