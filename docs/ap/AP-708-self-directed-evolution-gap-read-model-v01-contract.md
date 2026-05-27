---
id: AP-708-self-directed-evolution-gap-read-model-v01-contract
type: architecture_proposal
title: AP-708 Self-Directed Evolution Gap Read Model v0.1 Contract
status: accepted
owner: atlas-ai
created_at: 2026-05-26
summary: Defines the first operational slice of the Self-Directed Evolution Layer as a read-only gap read model that composes the existing Self-Construction Subsystem Builder, Self-Improvement Proposal Backlog and AAEL control-plane owners into normalized gap candidates, without writing state, invoking providers or auto-approving anything.
related_paths:
  - docs/ap/AP-707-self-directed-evolution-reuse-boundary-contract.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedEvolutionGapReadModelService.php
  - app/Console/Commands/AtlasSelfDirectedEvolutionCommand.php
  - tests/Unit/Ai/SelfDirectedEvolution/SelfDirectedEvolutionGapReadModelServiceTest.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php
  - app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php
requires_evidence: true
risk_level: high
---
# AP-708 Self-Directed Evolution Gap Read Model v0.1 Contract

## Context

AP-707 decided that Self-Directed Evolution is a composition/read-model layer and
must reuse existing owners before adding new detectors or proposal registries.
This AP applies that decision to the first concrete slice.

## Decision

Self-Directed Evolution v0.1 ships **only** a read model:
`SelfDirectedEvolutionGapReadModelService`.

- It is read-only. It does not write JSONL, runtime state, proposals, approvals,
  backlog items or AAEL cycles.
- It does not invoke providers.
- It never auto-approves and never enables external side effects.
- It is not a new OS, runtime or authority. It composes existing owners and
  normalizes their output into one operator-facing inbox shape.

## Placement

`php artisan atlas:ai:place-feature` placed this in `layer=domain`,
`domain=self_improvement`. The service is filed under
`app/Services/Ai/SelfDirectedEvolution/` to match the existing per-subsystem
namespace convention already used by `SelfConstruction/`, `SelfImprovement/` and
`AutonomousEvolution/`, keeping the composition layer visibly separate from the
owners it reads. This is a namespace choice, not a new authority: the owners
below remain canonical.

## Required Reuse (no parallel authority)

| Source | Existing owner | Method reused (read-only) | Never invoked here |
|---|---|---|---|
| subsystem gaps | `AtlasSelfConstructionSubsystemBuilderService` | `detectGaps()` | `propose()`, `approve()` |
| improvement backlog | `AtlasSelfImprovementProposalBacklogService` | `listBacklog()` | `createProposal()`, `evaluateProposal()`, `prioritize()` |
| evolution portfolio | `AtlasAutonomousEvolutionLoopService` | `controlPlane()` | `runCycle()`, `observeOpportunities()` |

## Schemas

- Report: `atlas.self_directed_evolution.gap_read_model.v1`
- Candidate: `atlas.evolution.gap_candidate.v1`

Each candidate carries a `duplicate_authority_guard` block naming the real owner
and asserting `parallel_authority_created=false`, plus
`requires_operator_curation=true`, `autoapproval_allowed=false` and
`external_side_effect_allowed=false`.

## Acceptance

- Service composes the three owners above and normalizes them to
  `atlas.evolution.gap_candidate.v1`.
- `$input` overrides allow side-effect-free unit testing.
- A missing/failing source produces a `source_unavailable` blocker without
  breaking the report.
- Candidates are deterministically deduplicated and ordered (priority, risk,
  source).
- Report hash is deterministic for the same input.
- Tests prove `propose()`/`approve()` are never called and that all candidates
  require operator curation with auto-approval and external side effects off.
- `docs-health`, `architecture-validate` and the focused test suite pass.

## Forbidden in this slice

- Calling `propose()`, `approve()`, creating a backlog proposal or an AAEL cycle.
- Calling any provider.
- Writing JSONL/runtime state or creating a parallel registry.
- Changing existing productive behavior of the reused owners.
- Treating this read model as an approval authority.
