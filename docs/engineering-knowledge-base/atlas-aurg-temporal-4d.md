---
id: atlas-aurg-temporal-4d
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: AURG Temporal 4D Extension
status: active
implementation_state: runtime_scorecard_ready
category: reality_graph
priority: 94
summary: Extensao temporal append-only do AURG para registrar ticks, replay historico e cadeia de hash de snapshots de realidade.
tags: [atlas-ai, acos, aurg, reality-graph, temporal-replay]
capabilities: [temporal_tick_recording, timeline_replay, hash_chain_verification, reality_snapshot_history]
decisions:
  - AURG Temporal estende o AURG 3D sem substituir o snapshot canonico.
  - Ticks temporais sao append-only e nao autorizam mutacao retroativa.
  - Counterfactual e replay devem referenciar hashes/snapshots, nao reescrever fatos.
maintenance:
  - Atualizar antes de mudar schema temporal, retention, replay ou integracao TEOS.
  - Manter scorecard ACOS sincronizado com service e testes reais.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-reality-graph.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - app/Services/Ai/Reality/AtlasUnifiedRealityGraphTemporalService.php
  - tests/Unit/Ai/Reality/AtlasUnifiedRealityGraphTemporalServiceTest.php
owner: atlas-ai
graph_id: atlas-aurg-temporal-4d
graph_title: AURG Temporal 4D Extension
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-reality-graph
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-aurg-temporal-4d.md
  - app/Services/Ai/Reality/AtlasUnifiedRealityGraphTemporalService.php
depends_on: [atlas-unified-reality-graph, atlas-cognition-operating-system]
flows_to: [atlas-teos-i3-counterfactual]
unlocks: [temporal_reality_replay, reality_hash_chain]
governs: [aurg_temporal_ticks, temporal_snapshot_replay]
evidence:
  - docs/engineering-knowledge-base/atlas-aurg-temporal-4d.md
  - app/Services/Ai/Reality/AtlasUnifiedRealityGraphTemporalService.php
  - tests/Unit/Ai/Reality/AtlasUnifiedRealityGraphTemporalServiceTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:cognition:scorecard --strict --json"
  - "php artisan test tests/Unit/Ai/Reality/AtlasUnifiedRealityGraphTemporalServiceTest.php"
next_actions:
  - Manter builder AURG 3D como fonte do snapshot e AURG Temporal como trilha append-only.
  - Integrar replay temporal com TEOS-I3 somente com testes e owner decision.
allowed_changes:
  - Evoluir schema temporal com testes e scorecard.
  - Integrar replay temporal com TEOS-I3 ou Cartografia com owner decision.
forbidden_changes:
  - Substituir AURG 3D ou reescrever ticks historicos.
  - Declarar counterfactual como fato operacional.
requires_evidence: true
risk_level: high
line_limit: 520
---

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

## Resumo

AURG Temporal 4D registra ticks append-only de snapshots AURG para replay historico e cadeia verificavel de hash.

## Papel no Atlas

Ele adiciona tempo ao grafo de realidade sem virar nova fonte de verdade acima do AURG 3D, Evidence Ledger ou docs canonicos.

## Onde Se Encaixa

Fica depois do builder AURG 3D e antes de TEOS-I3, Cartografia temporal e replay cognitivo.

## Contratos

Schemas `atlas.aurg.temporal_tick.v1` e `atlas.aurg.temporal_snapshot.v1`; snapshots continuam vindos do builder AURG.

## Fluxo

Caller fornece nodes/edges, o builder AURG gera snapshot 3D, o runtime temporal grava um tick com `snapshot_hash`.

## Regras para IA

Nao reescrever ticks antigos, nao declarar counterfactual como fato e nao substituir AURG 3D por timeline.

## Escopo de Implementacao

Runtime local, append-only, provider-safe por referencia de hash e coberto por teste unitario real.

## Dependencias

- `AtlasRealityGraphSnapshotBuilderService`
- `AtlasUnifiedRealityGraphTemporalService`
- `AtlasTeosI3CounterfactualService`

## Evidencias

- `php artisan test tests/Unit/Ai/Reality/AtlasUnifiedRealityGraphTemporalServiceTest.php`
- `php artisan atlas:cognition:scorecard --strict --json`

## Riscos

Confundir replay temporal com verdade autoral ou usar branch futura para apagar evidencias passadas.

## Exemplos

Registrar snapshot atual como tick e consultar `stateAt()` para recuperar a linha factual naquele horario.

## Proximas Acoes

Integrar visualizacao temporal e retention somente depois de owner decision e novos testes.
