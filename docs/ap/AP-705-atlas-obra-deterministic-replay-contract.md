# AP-705 Atlas Obra Deterministic Replay Contract

Status: proposed
Owner: atlas-ai
Area: atlas-obra-replay
Risk: high

## Problem

Atlas Evidence Ledger captures `atlas.aaeos.phase.v1` envelopes and
Decision Receipt v2 entries during an Obra's life, but there is no
way to REPLAY them deterministically to answer "por que decisão X
foi tomada no minuto 47 da obra". Without replay, governance is
forward-only: events are recorded but the decision lineage cannot be
audited cleanly.

## Goal

Deliver `AtlasObraDeterministicReplayService` as a pure replay
evaluator. Input: ordered list of events (envelopes + receipts).
Output: canonical `atlas.obra.replay.v1` snapshot with:
  - `state_at_end`: final accumulated state (phases executed,
    decisions taken, blockers raised/cleared)
  - `state_at_index[i]`: snapshot at any event index (replay buffer)
  - `decision_lineage`: for each decision id, the ordered chain of
    events that produced it (input phase → policy → topology →
    decision)
  - `replay_hash`: deterministic sha256 of the full reconstruction
- Idempotent: running twice on same input produces identical output.
- Append-only consistent: adding a new event extends the snapshot
  without rewriting history.

## Non Goals

- Not persisting the replay output (caller responsibility).
- Not consuming the live Evidence Ledger directly (this service is
  pure; callers query the ledger and pass events).
- Not changing the canonical phase envelope schema.

## Overlap Decision

| Candidate | Decision |
|---|---|
| Evidence Ledger services | Reuse: caller queries the ledger and passes events to this replay service. |
| `AaeosPhaseHandoffService` envelope schema | Reuse: input format. |

## Required Docs

- `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
- `docs/ap/AP-705-atlas-obra-deterministic-replay-contract.md` (this AP)

## Acceptance Criteria

- Service exists at
  `app/Services/Ai/AtlasForge/AtlasObraDeterministicReplayService.php`.
- Returns canonical `atlas.obra.replay.v1` envelope.
- Replay of identical input is byte-identical (deterministic hash).
- `decision_lineage` traces a given decision id to all its input
  envelopes.
- Unit tests cover: empty replay, single-phase replay, full 17-phase
  obra replay, determinism, decision lineage extraction.

## Rollback

Service is pure; delete file.

## Risks

- **Low**: replay produces wrong state if event order is corrupted.
  Mitigation: service does NOT reorder events; it trusts the caller's
  order and exposes it in the snapshot.

## What This AP Is NOT

- Not the Evidence Ledger reader.
- Not the persistence layer.
