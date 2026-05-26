---
id: atlas-parallel-multi-agent-execution-spec
type: engineering_knowledge
title: Atlas Parallel Multi-Agent Execution Spec
status: active
category: atlas-ai
priority: 101
summary: Spec canonica que consolida em UM runbook executavel o paralelismo durable real do Atlas: durable reservation ledger, worktree-per-agent, scope validator, collision guard, merge review promotion. Substitui ~30 contratos parts dispersos em `self-construction/` por uma autoridade unica.
tags:
  - atlas-ai
  - multi-agent
  - parallel-execution
  - durable-reservation
  - worktree-isolation
  - scope-validator
  - collision-guard
capabilities:
  - parallel_multi_agent_runtime
  - durable_reservation_ledger
  - worktree_isolation
  - scope_validation
  - collision_detection
  - merge_review_promotion
decisions:
  - Paralelismo real exige 5 invariantes simultaneas: durable reservation, worktree, scope guard, collision guard, merge review. Faltar uma e perdeu paralelismo seguro.
  - Esta spec consolida ~30 contratos parts dispersos em UMA autoridade canonica.
  - Reservation Ledger e append-only; lease lifecycle exige TTL e renew explicito.
  - Worktree-per-agent e mandatorio para R3+; R1-R2 podem rodar single tree.
maintenance:
  - Atualize antes de adicionar invariante, mudar TTL default, alterar collision detection.
related_paths:
  - docs/engineering-knowledge-base/atlas-multi-agent-unified-architecture.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-parallel-multi-agent-execution-spec
graph_title: Atlas Parallel Multi-Agent Execution Spec
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-multi-agent-unified-architecture
graph_status: active
graph_source: repo
human_name: Atlas Parallel Multi-Agent Execution Spec
canonical_name: Atlas Parallel Multi-Agent Execution Spec
technical_name: atlas-parallel-multi-agent-execution-spec
cartography_type: spec
canonical_source: docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
owner: atlas-ai
product_name: Atlas Parallel Multi-Agent Execution Spec
internal_product_name: AAEOS Parallel Multi-Agent Execution
runtime_acronym: AAEOS-PMAE
technical_runtime: atlas.multi_agent.parallel_execution
repo_paths:
  - docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
allowed_changes:
  - Refinar TTL, gates, schemas.
forbidden_changes:
  - Permitir paralelismo R3+ sem worktree isolation.
  - Reservation que nao seja append-only.
depends_on:
  - atlas-multi-agent-unified-architecture
  - atlas-forge-continuum-os
flows_to:
  - atlas-aaeos-obra-replay-spec
unlocks:
  - parallel-multi-agent-runtime
  - durable-reservation-canonical
governs:
  - atlas_ai.multi_agent.parallel
evidence:
  - docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - spec
  - parallel
  - multi-agent
quality_gates:
  - five-invariants-declared
  - reservation-ledger-append-only
  - worktree-mandatory-r3-plus
  - collision-guard-enforced
  - merge-review-promotion-defined
failure_modes:
  - Paralelismo sem reservation ledger.
  - Worktree compartilhado em R3+.
  - Collision nao detectada.
  - Merge sem review.
  - Lease lifecycle sem TTL.
observability_signals:
  - parallel_active_agents
  - parallel_reservation_count
  - parallel_collision_detected_count
  - parallel_lease_expired_count
next_actions:
  - Implementar `AtlasReservationLedgerService` append-only.
  - Implementar `AtlasWorktreeIsolationService` com git worktree por agente.
  - Implementar `AtlasScopeValidatorService` por packet.
  - Implementar `AtlasCollisionGuardService` cross-agent.
  - Implementar `AtlasMergeReviewPromotionService`.
---
# Atlas Parallel Multi-Agent Execution Spec

## Resumo

Consolida em UM runbook as 5 invariantes do paralelismo multi-agente seguro: durable reservation ledger, worktree-per-agent, scope validator, collision guard, merge review promotion.

## Papel no Atlas

`self-construction/` tem ~30 contratos parts (durable-reservation-*, collision-guard-*, lease-lifecycle-*, merge-review-*). Sao corretos individualmente mas nao formam autoridade unica. **Este doc e a autoridade unica**.

## Onde Se Encaixa

```text
atlas-multi-agent-unified-architecture (4 camadas)
  +-- camada 4 Forge Multi-Agent Scheduler
       +-- atlas-parallel-multi-agent-execution-spec (este doc)
            +-- 5 invariantes
```

## Contratos

### As 5 invariantes simultaneas

| # | Invariante | Schema | Failure se faltar |
|---|------------|--------|-------------------|
| 1 | Durable Reservation Ledger | `atlas.reservation.entry.v1` | dois agentes editam mesmo arquivo simultaneamente |
| 2 | Worktree-per-agent (R3+) | `atlas.worktree.v1` | cross-pollution de mudancas WIP |
| 3 | Scope Validator (per packet) | `atlas.scope.guard.v1` | agente edita fora do scope |
| 4 | Collision Guard | `atlas.collision.report.v1` | merge produz conflito silencioso |
| 5 | Merge Review Promotion | `atlas.merge.review.v1` | merge sem review humano/architect em R4+ |

### Reservation Ledger entry schema

```text
{
  "schema": "atlas.reservation.entry.v1",
  "reservation_id": "<uuid>",
  "agent_id": "<id>",
  "intent_id": "<id>",
  "scope": {"paths": ["..."], "operations": ["read","write","delete"]},
  "lease": {
    "acquired_at": "<iso8601>",
    "ttl_seconds": <int>,
    "renew_at": "<iso8601>",
    "released_at": "<iso8601_or_null>"
  },
  "state": "active|expired|released|preempted",
  "evidence_hash": "sha256:..."
}
```

Append-only: nunca update, sempre new entry com `state` transition.

### Worktree schema

```text
{
  "schema": "atlas.worktree.v1",
  "worktree_id": "<id>",
  "agent_id": "<id>",
  "base_branch": "main",
  "worktree_branch": "atlas/parallel/agent-<agent_id>/<intent_id>",
  "path": "/var/atlas/worktrees/<id>",
  "created_at": "<iso8601>",
  "merged_at": "<iso8601_or_null>",
  "deleted_at": "<iso8601_or_null>"
}
```

### Collision Guard

Pre-merge check obrigatorio. Detecta:
- Dois worktrees mexeram no mesmo arquivo? -> collision_kind=path
- Mesma funcao/classe? -> collision_kind=symbol
- Mesma migration index? -> collision_kind=migration

```text
{
  "schema": "atlas.collision.report.v1",
  "report_id": "<uuid>",
  "checked_worktrees": ["<id>"],
  "collisions": [{"kind": "path|symbol|migration", "ref": "<file_or_symbol>", "agents": ["<id>"]}],
  "auto_resolvable": <bool>,
  "blocking": <bool>,
  "checked_at": "<iso8601>"
}
```

### Merge Review Promotion

R3 e abaixo: auto-merge se collision=0 e gates verdes.
R4 e acima: review humano ou architect agent obrigatorio antes de merge.

```text
{
  "schema": "atlas.merge.review.v1",
  "review_id": "<uuid>",
  "worktree_id": "<id>",
  "intent_id": "<id>",
  "reviewer": {"kind": "human|architect_agent", "id": "<id>"},
  "decision": "approve|reject|request_changes",
  "evidence_hashes_seen": ["sha256:..."],
  "rationale": "<string>",
  "signed_at": "<iso8601>"
}
```

## Fluxo

```mermaid
sequenceDiagram
  participant Sched as Scheduler
  participant Ledger as Reservation Ledger
  participant WT as Worktree
  participant SG as Scope Guard
  participant CG as Collision Guard
  participant MR as Merge Review
  participant Main as main branch

  Sched->>Ledger: claim reservation
  Ledger-->>Sched: lease (TTL)
  Sched->>WT: create worktree
  WT-->>Sched: path ready
  Sched->>Agent: execute in worktree
  Agent->>SG: validate scope per edit
  SG-->>Agent: ok|blocked
  Agent->>WT: commit
  WT->>CG: pre-merge check
  CG-->>WT: collision report
  WT->>MR: request review (if R4+)
  MR-->>WT: approve|reject
  WT->>Main: merge (if approved + collision=0)
  Sched->>Ledger: release reservation
```

## Regras para IA

- Antes de iniciar paralelismo, declarar `topology` + `parallelism_mode=parallel_durable` em `atlas.multi_agent.decision.v1`.
- Cada agente recebe UMA reservation; subleasing proibido.
- TTL default 30min; renew obrigatorio antes de expirar.
- Collision detectada bloqueia merge ate resolver.
- R4+ sempre review.

## Escopo de Implementacao

`AtlasReservationLedgerService`, `AtlasWorktreeIsolationService`, `AtlasScopeValidatorService`, `AtlasCollisionGuardService`, `AtlasMergeReviewPromotionService`. Cada um implementa UMA invariante.

## Dependencias

Multi-Agent Unified (T1.3), Forge Continuum OS.

## Evidencias

Comandos esperados: `atlas:parallel:reservations --json`, `atlas:parallel:collision-check --json`, `atlas:parallel:merge-review --json`.

## Riscos

Lease leak (TTL nao renovado), worktree zombie, collision falso-negativo. Mitigacoes: watchdog, GC, regressao test suite.

## O que este doc NAO e

Nao e topologia (T1.3 camada 1) nem provider profile (T1.3 camada 2). E exclusivamente camada 4 paralelismo durable.

## Exemplos

3 agentes paralelos em Obra R4: 3 reservations distintas, 3 worktrees, 0 collision, 3 review approvals, 3 merges sequenciados.

## Proximas Acoes

1. Implementar 5 servicos.
2. Telemetria.
3. Suite paralelismo regressao.
