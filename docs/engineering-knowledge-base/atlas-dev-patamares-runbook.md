---
id: atlas-dev-patamares-runbook
type: engineering_knowledge
title: Atlas Dev Patamares Runbook
status: active
category: atlas-ai
priority: 101
summary: Runbook detalhado por patamar (A0-A7) do Atlas Dev. Cada patamar tem fatias canonicas, DTOs, gates de promocao, telemetria e mapeamento para servicos PHP. Substitui a definicao prosa atual por checklist executavel.
tags:
  - atlas-ai
  - atlas-dev
  - patamares
  - runbook
  - a0-a7
  - slice-planning
capabilities:
  - atlas_dev_patamar_governance
  - slice_planning
  - patamar_promotion_runtime
decisions:
  - Cada patamar A0-A7 tem fatias atomicas com DTO declarado.
  - Promocao A<n> -> A<n+1> exige todas as fatias do A<n> verdes.
maintenance:
  - Atualize antes de mudar fatia, adicionar DTO ou alterar gate de promocao.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-patamares-runbook
graph_title: Atlas Dev Patamares Runbook
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-dev-index
graph_status: active
graph_source: repo
human_name: Atlas Dev Patamares Runbook
canonical_name: Atlas Dev Patamares Runbook
technical_name: atlas-dev-patamares-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-dev-patamares-runbook.md
owner: atlas-ai
product_name: Atlas Dev Patamares Runbook
internal_product_name: Atlas Dev Patamares
runtime_acronym: ADEV-PR
technical_runtime: atlas.dev.patamares
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-patamares-runbook.md
allowed_changes:
  - Refinar fatias, DTOs, gates.
forbidden_changes:
  - Promover patamar sem fatias verdes.
depends_on:
  - atlas-dev-patamares
  - atlas-aaeos-http-path-integration-spec
flows_to:
  - atlas-aaeos-department-maturity-matrix
unlocks:
  - atlas-dev-patamar-runtime
governs:
  - atlas_ai.dev.patamares
evidence:
  - docs/engineering-knowledge-base/atlas-dev-patamares-runbook.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - runbook
  - patamar
quality_gates:
  - all-patamares-have-slices
  - all-slices-have-dto
  - promotion-gates-defined
failure_modes:
  - Patamar sem fatia atomica.
  - DTO ausente.
observability_signals:
  - dev_current_patamar
  - dev_slice_completion_rate
next_actions:
  - Implementar `AtlasDevPatamarRuntimeService`.
---
# Atlas Dev Patamares Runbook

## Resumo

Runbook detalhado por patamar A0-A7.

## Papel no Atlas

`atlas-dev-patamares.md` define os 8 patamares. Este doc define COMO promover entre eles.

## Onde Se Encaixa

```text
atlas-dev-index
  +-- atlas-dev-patamares (conceito)
  +-- atlas-dev-patamares-runbook (este doc, runbook)
```

## Contratos

### 8 patamares com fatias canonicas

| Patamar | Nome | Fatias atomicas | DTO principal | Gate promocao |
|---------|------|-----------------|---------------|---------------|
| A0 | Pre-Foundation | esquema dos schemas | n/a | schemas declarados |
| A1 | Foundation | discovery + prompt projection + telemetry + persistence | `atlas.dev.run.v1` | 50 runs verdes A1 |
| A2 | Plan-Visible | desktop SSE + plan stream + receipt visible | `atlas.dev.plan_stream.v1` | 30 sessions plan-visible |
| A3 | Provider-Verified | scope guard runtime + verification receipt + provider attestation | `atlas.dev.scope_guard.v1` | 20 scope_violation=0 |
| A4 | Self-Healing | repair loop runtime + retry policy + drift detection | `atlas.dev.repair_loop.v1` | 15 self-healing recoveries |
| A5 | Surface-Parity | desktop + cli + app + api parity | `atlas.dev.surface.v1` | 4 surfaces feature-equal |
| A6 | Multi-Slice Coordination | parallel slices durable + collision guard local | `atlas.dev.parallel_slice.v1` | 10 multi-slice obras |
| A7 | Self-Improving Dev | propoe own optimization, gates aprovam | `atlas.dev.self_improvement.v1` | 5 self-improvements aprovados |

### DTO principal A1 (`atlas.dev.run.v1`)

```text
{
  "schema": "atlas.dev.run.v1",
  "run_id": "<uuid>",
  "intent_id": "<id>",
  "scope": {"paths": ["..."], "max_files": <int>},
  "discovery": {"context_pack_hash": "sha256:..."},
  "prompt_projection": {"hash": "sha256:..."},
  "telemetry": {"latency_ms_p95": <int>, "tokens_used": <int>},
  "persistence": {"primary_table": "atlas_dev_runs", "evidence_ledger": "..."}
}
```

### DTO A3 (`atlas.dev.scope_guard.v1`)

```text
{
  "schema": "atlas.dev.scope_guard.v1",
  "guard_id": "<uuid>",
  "run_id": "<id>",
  "allowed_paths": ["..."],
  "violations": [{"path":"...","reason":"..."}],
  "verification_receipt_hash": "sha256:..."
}
```

## Fluxo

```mermaid
flowchart LR
  A0 --> A1 --> A2 --> A3 --> A4 --> A5 --> A6 --> A7
  A1 -->|bloqueado| A1Block[falta fatia]
  A3 -->|scope violation| A3Block[bloqueia promocao]
```

## Regras para IA

- Atlas Dev fast-path atual e A1; A2 em construcao.
- Promover patamar exige TODAS as fatias verdes do anterior.
- HTTP path integration spec (T1.4) destrava A2 plan-visible.

## Escopo de Implementacao

`AtlasDevRuntimeService`, `AtlasDevPatamarPromotionWatchdog`.

## Dependencias

`atlas-dev-patamares`, HTTP Path Integration Spec (T1.4).

## Evidencias

Comando: `atlas:dev:patamar-status --json`.

## Riscos

Promover prematuramente, fatias mal definidas, DTO drift.

## O que este doc NAO e

Nao e definicao conceitual de patamares (`atlas-dev-patamares.md`); e runbook executavel.

## Exemplos

A1 -> A2: 50 runs A1 verdes registrados, plan stream pronto, desktop SSE pronto. Promocao receipt assinado.

## Proximas Acoes

1. Implementar promotion watchdog.
2. Telemetria por patamar.
3. Integrar com cockpit.
