# AP-704 Atlas Forge Parallel Durable Coordinator Contract

Status: proposed
Owner: atlas-ai
Area: atlas-forge-parallel-durable
Risk: high

## Problem

T2.2 (Multi-Agent Parallel Durable) declares that Atlas Forge can run
N agents in parallel against a single Obra without collisions. The
SelfConstruction service tree has a minimal cross-agent reservation
ledger, but there is NO canonical coordinator that, given a set of
ready work tickets and a set of available agents, returns a durable
assignment that:
  - never assigns two agents to overlapping file scopes,
  - persists the reservation (durable across crashes),
  - releases reservations cleanly when the ticket finishes or fails.

Without this coordinator, Forge can only be safely single-track —
breaking the "N agentes paralelos durable" tese.

## Goal

Deliver `AtlasForgeParallelDurableCoordinatorService` as a pure
in-memory reservation evaluator (durability is delegated to caller's
persistence — Eloquent/JSONL/etc — so the service itself stays
testable). Schema: `atlas.forge.parallel_durable.v1`.

- `propose(array $tickets, array $agents, array $existingReservations): array`
  returns canonical assignment envelope with:
  - `assignments[]` → list of {agent_id, ticket_id, locked_paths[]}
  - `unassigned[]` → tickets that could not be assigned (collision or
    no available agent), with `reason`
  - `released[]` → existing reservations that are now stale
- Collision rule: two tickets collide if they share ANY locked path
  (prefix match against `locked_paths`).
- Conservative assignment: greedy first-fit by ticket priority, never
  preempt an existing reservation.
- Deterministic hash of the assignment envelope for Evidence Ledger.

## Non Goals

- Not the durable persistence mechanism (caller responsibility).
- Not the agent dispatcher.
- Not Obra-level scheduling.

## Overlap Decision

| Candidate | Decision |
|---|---|
| `app/Services/Ai/SelfConstruction/*Reservation*` | Reuse: coordinator is a sibling pure evaluator; persistence layer can call into existing ledger. |
| `app/Services/Ai/AtlasDecide/AtlasSwarmParallelDispatchService.php` | Reuse: dispatcher consumes the coordinator's assignment envelope. |

## Required Docs

- `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
- `docs/ap/AP-704-atlas-forge-parallel-durable-coordinator-contract.md` (this AP)

## Acceptance Criteria

- Service exists at
  `app/Services/Ai/AtlasForge/AtlasForgeParallelDurableCoordinatorService.php`.
- Returns canonical `atlas.forge.parallel_durable.v1` envelope.
- Collision detection works for shared path prefix.
- No agent assigned twice in a single proposal.
- Stale reservations (referencing missing tickets) are released.
- Unit tests cover: no collisions, simple collision, multi-agent fan-out,
  empty inputs, stale reservation cleanup, deterministic hash.

## Rollback

Service is pure; delete file and unregister binding.

## Risks

- **Medium**: greedy assignment may starve some tickets. Mitigation:
  priority ordering; future AP can add fairness. Acceptable for now.

## What This AP Is NOT

- Not the persistence layer.
- Not the agent runtime.
