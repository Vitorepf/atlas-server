# AURG — Temporal 4D Extension

> **Status**: canonical
> **Authority**: ACOS · Patamar 3 (Sovereign Cognitive Substrate)
> **Schema**: `atlas.aurg.temporal_snapshot.v1` · `atlas.aurg.temporal_tick.v1`
> **Service**: `App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService`
> **Owner**: this doc is the **source of truth**.

## 1. Why this exists

Today AURG (`AtlasUnifiedRealityGraphService`) produces a 3D snapshot:
nodes + edges + canonical hash. It's a still photograph. **Replay cognitivo
completo** requires a 4th axis: time. Every change to the reality graph
becomes a tick; the operator can ask "what was the state of Atlas reality
at 2026-03-15 14:23?" and traverse from there.

This subsystem **extends** AURG without replacing it: it records ticks of
arbitrary 3D snapshots and folds them deterministically into a temporal
timeline.

## 2. Hard invariants

- **No mutation of past ticks** — append-only.
- **No automatic capture** — ticks are recorded explicitly by callers
  (operator, Cognitive Immune G6 replay, or future Self-Construction).
- **Provider-safe by inheritance** — a tick references a 3D AURG snapshot
  hash; sensitive content stays in the source-of-truth snapshot.
- **Deterministic ordering** — ticks sorted by `(at, tick_id)` for replay.
- **Bounded query** — `traverseTime()` always caps at `max_ticks` to
  prevent runaway scans.
- **Hash chain** — each tick carries `prev_tick_hash`, building a verifiable
  chain.

## 3. Tick schema

`atlas.aurg.temporal_tick.v1`:

```json
{
  "schema_version": "atlas.aurg.temporal_tick.v1",
  "tick_id": "tick_<sha8>",
  "at": "ISO-8601",
  "actor": "operator | atlas | cognitive_immune | self_construction",
  "kind": "snapshot_recorded | node_added | edge_added | node_removed | edge_removed | rationale_event",
  "snapshot_hash": "sha256:..." ,
  "delta_summary": {
    "nodes_added": ["..."],
    "nodes_removed": ["..."],
    "edges_added": ["..."],
    "edges_removed": ["..."]
  },
  "rationale": "free-text",
  "prev_tick_hash": "sha256:... | null",
  "tick_hash": "sha256:..."
}
```

## 4. Temporal snapshot schema

`atlas.aurg.temporal_snapshot.v1`:

```json
{
  "schema_version": "atlas.aurg.temporal_snapshot.v1",
  "generated_at": "ISO-8601",
  "tick_count": 47,
  "first_tick_at": "ISO-8601",
  "last_tick_at": "ISO-8601",
  "ticks": [ /* ordered ticks */ ],
  "chain_intact": true,
  "chain_break_at": null,
  "temporal_hash": "sha256:..."
}
```

## 5. Public API

```php
$svc->recordTick(array $tick): array        // append-only; returns persisted tick
$svc->timeline(int $limit = 100): array     // full envelope
$svc->stateAt(string $iso): array           // most-recent snapshot at-or-before iso
$svc->traverseTime(string $from, string $to, int $maxTicks = 1000): array
$svc->verifyChain(): array                  // walks chain, reports break if any
```

## 6. Persistence

Append-only JSONL at
`storage/atlas/aurg/temporal_ticks.jsonl`. No DB table — same local-first
pattern as Meta-Learning + Self-Construction.

## 7. Operator workflow

```bash
# Record a tick (Doctor 3-Tier)
php artisan atlas:aurg:temporal:record \
  --snapshot-hash=<sha256> --kind=snapshot_recorded \
  --rationale="manual capture" \
  --mode=apply --check=aurg-temporal --confirm

# View timeline
php artisan atlas:aurg:temporal --json

# State at a specific moment
php artisan atlas:aurg:temporal --at=2026-05-25T10:00:00Z

# Verify chain integrity
php artisan atlas:aurg:temporal:verify --json
```

## 8. Test coverage requirements

- Unit: append, hash chain, chain break detection, stateAt before/after/between ticks, traverseTime bounded.
- Feature: artisan timeline + verify integration.
- Real-fixture: tests use real AURG snapshots produced by `AtlasUnifiedRealityGraphService` (no mocks).

## 9. ACOS scorecard integration

Registered as:

```
['AURG-4D', 'Unified Reality Graph Temporal (4D)', 'reality',
 AtlasUnifiedRealityGraphTemporalService::class, 'ready', 'ready']
```

## 10. Future evolution

- **Branching timelines** — counterfactual integration with TEOS-I3 (this
  subsystem is the prerequisite).
- **Garbage collection** — bounded retention windows per actor.
- **Replay UI** — Cartografia shows timeline scrubber.
