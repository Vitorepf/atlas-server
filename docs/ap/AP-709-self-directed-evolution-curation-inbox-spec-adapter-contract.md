---
id: AP-709-self-directed-evolution-curation-inbox-spec-adapter-contract
type: architecture_proposal
title: AP-709 Self-Directed Evolution Curation Inbox + Spec Proposal Adapter v0.2 Contract
status: accepted
owner: atlas-ai
created_at: 2026-05-26
summary: Defines v0.2 of the Self-Directed Evolution Layer — an operator Curation Inbox (read-only projection over the v0.1 gap read model) and a proposal-only Spec Proposal Adapter that turns a gap candidate into an AP/spec/doc draft. Neither writes canonical docs, invokes a provider, nor auto-approves; the operator reviews, approves or vetoes and execution routes back to the canonical owner.
related_paths:
  - docs/ap/AP-707-self-directed-evolution-reuse-boundary-contract.md
  - docs/ap/AP-708-self-directed-evolution-gap-read-model-v01-contract.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedEvolutionGapReadModelService.php
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedEvolutionCurationInboxService.php
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedSpecProposalAdapter.php
  - app/Console/Commands/AtlasSelfDirectedEvolutionCommand.php
  - tests/Unit/Ai/SelfDirectedEvolution/SelfDirectedEvolutionCurationInboxServiceTest.php
  - tests/Unit/Ai/SelfDirectedEvolution/SelfDirectedSpecProposalAdapterTest.php
requires_evidence: true
risk_level: high
---
# AP-709 Self-Directed Evolution Curation Inbox + Spec Proposal Adapter v0.2 Contract

## Context

AP-707 fixed the reuse boundary; AP-708 shipped the v0.1 read-only gap read
model. This AP turns those normalized gap candidates into an operator-facing
curation surface and proposal-only spec drafts, still without granting any new
authority.

## Decision

v0.2 ships two read-only / proposal-only composition pieces:

1. `SelfDirectedEvolutionCurationInboxService` — a **read-only projection** over
   the v0.1 gap read model. It turns each `atlas.evolution.gap_candidate.v1`
   into a deterministic `atlas.self_directed_evolution.curation_item.v1`
   (`status=pending_operator_review`), deduplicated by `candidate_hash` and
   classified by source / risk / priority. It persists nothing and creates no
   parallel registry.

2. `SelfDirectedSpecProposalAdapter` — a **proposal-only** adapter that turns one
   gap candidate into an `atlas.self_directed_evolution.spec_proposal_draft.v1`
   draft (owner docs, evidence, scope, non-goals, acceptance gates, rollback,
   required tests, forbidden paths). It never writes a canonical doc and never
   materializes a file in this slice.

## Schemas

- `atlas.self_directed_evolution.curation_inbox.v1` (report)
- `atlas.self_directed_evolution.curation_item.v1` (one item)
- `atlas.self_directed_evolution.spec_proposal_draft.v1` (one draft) — concrete,
  proposal-only realization of the layer doc's illustrative
  `atlas.evolution.spec_proposal.v1`.
- `atlas.self_directed_evolution.operator_curation_receipt.v1` (receipt
  envelope) — built **only** from an explicit operator decision; it is not
  persisted and not executed here. It records the decision and the canonical
  owner the decision must be routed to.

## Reuse (no parallel authority)

| Concern | Canonical owner | This slice |
|---|---|---|
| gap candidates | `SelfDirectedEvolutionGapReadModelService` (v0.1) | consumed read-only |
| subsystem proposal/approval | `AtlasSelfConstructionSubsystemBuilderService` | receipt routes here; never invoked |
| improvement backlog | `AtlasSelfImprovementProposalBacklogService` | receipt routes here; never invoked |
| evolution portfolio | `AtlasAutonomousEvolutionLoopService` | receipt routes here; never invoked |
| spec compilation | Atlas Spec OS (SDD) | referenced as owner; never invoked |
| canonical doc publishing | Documentation Governance + operator | never auto-written |

## Invariants

- Every curation item: `requires_operator_review=true`,
  `autoapproval_allowed=false`, `external_side_effect_allowed=false`.
- Every spec draft: `operator_approval_required=true`,
  `canonical_doc_write_allowed=false`, `autoimplementation_allowed=false`,
  `autoapproval_allowed=false`, `written=false`.
- Deterministic `item_id`/`draft_id`/`*_hash` and a deterministic report hash
  (excludes `generated_at`).
- No provider call, no JSONL/runtime write, no parallel registry.
- `propose()`, `approve()`, `createProposal()`, `runCycle()` are never called.

## Operator workflow (review / approve / veto)

1. `php artisan atlas:self-directed-evolution curation-inbox --json` — operator
   reads the inbox; every item is `pending_operator_review`.
2. `php artisan atlas:self-directed-evolution spec-draft --candidate=<hash>
   --json` — operator inspects a proposal-only draft for one candidate.
3. Operator decides. An `operator_curation_receipt.v1` is built from the
   explicit decision (`approve` | `veto` | `needs_revision`) with actor and
   rationale. The receipt names `routes_to_owner`; **execution stays with that
   canonical owner** (e.g. SubsystemBuilder `propose()`/`approve()`), never with
   this layer. Nothing is approved or implemented automatically.

## Acceptance

- Inbox emits `curation_inbox.v1`; items emit `curation_item.v1`; drafts emit
  `spec_proposal_draft.v1`.
- Dedupe deterministic; hashes deterministic for the same input.
- Tests prove curation-only, proposal-only, no canonical write, no
  auto-approval/auto-implementation, and that no owner write method is called.
- `docs-health`, `architecture-validate` and the focused suite pass.

## Forbidden in this slice

- Writing canonical docs or APs automatically.
- Persisting a parallel proposal/approval registry.
- Auto-approving or auto-implementing any candidate.
- Invoking Spec OS, a provider, or any owner write method.
- Treating the inbox or adapter as an authority above the canonical owners.
