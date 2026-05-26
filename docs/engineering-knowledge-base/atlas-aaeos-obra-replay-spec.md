---
id: atlas-aaeos-obra-replay-spec
type: engineering_knowledge
title: Atlas AAEOS Obra Replay Spec
status: active
category: atlas-ai
priority: 101
summary: Spec canonica para replay determinístico de Obras inteiras (nao apenas receipts isolados). Define hash chain de eventos, manifesto de replay, modos (read-only audit, simulation, what-if), garantias deterministicas e cenarios suportados (debug, regression, learning, audit).
tags:
  - atlas-ai
  - replay
  - determinism
  - audit
  - hash-chain
  - what-if-simulation
capabilities:
  - obra_deterministic_replay
  - hash_chain_audit
  - what_if_simulation
  - regression_detection_via_replay
decisions:
  - Replay e propriedade de toda Obra; sem replay nao ha audit completo.
  - Replay determinístico exige hash chain de eventos + freeze de inputs.
  - Modos suportados: read-only audit, simulation, what-if.
maintenance:
  - Atualize antes de adicionar modo, mudar hash chain format, alterar manifesto.
related_paths:
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
  - docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-aaeos-obra-replay-spec
graph_title: Atlas AAEOS Obra Replay Spec
graph_world: atlas
graph_layer: system
graph_kind: spec
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas AAEOS Obra Replay Spec
canonical_name: Atlas AAEOS Obra Replay Spec
technical_name: atlas-aaeos-obra-replay-spec
cartography_type: spec
canonical_source: docs/engineering-knowledge-base/atlas-aaeos-obra-replay-spec.md
owner: atlas-ai
runtime_acronym: AAEOS-OR
technical_runtime: atlas.aaeos.obra_replay
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-obra-replay-spec.md
allowed_changes:
  - Refinar manifesto, modos, hash chain.
forbidden_changes:
  - Replay nao-determinístico.
  - Replay sem hash chain.
depends_on:
  - atlas-evidence-certification-runtime
  - atlas-agentic-engineering-os-runbook
flows_to:
  - atlas-aaeos-worked-examples-library
unlocks:
  - obra-replay-runtime
  - what-if-simulation
governs:
  - atlas_ai.aaeos.obra_replay
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-obra-replay-spec.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - spec
  - replay
quality_gates:
  - hash-chain-defined
  - three-modes-defined
  - determinism-guaranteed
failure_modes:
  - Replay nao reproduz outputs.
  - Hash chain quebrado.
  - What-if afeta producao.
observability_signals:
  - replay_runs_count
  - replay_determinism_violation_count
next_actions:
  - Implementar `AtlasObraReplayService` com 3 modos.
---
# Atlas AAEOS Obra Replay Spec

## Resumo

Replay determinístico de Obras inteiras com 3 modos.

## Papel no Atlas

Receipts isolados ja existem; falta replay end-to-end de Obra inteira.

## Onde Se Encaixa

```text
atlas-evidence-certification-runtime (receipts isolados)
  +-- atlas-aaeos-obra-replay-spec (replay E2E)
```

## Contratos

### Manifesto canonico (`atlas.obra.replay_manifest.v1`)

```text
{
  "schema": "atlas.obra.replay_manifest.v1",
  "obra_id": "<id>",
  "hash_chain_root": "sha256:...",
  "events_count": <int>,
  "phases_traversed": ["P0",...,"P16"],
  "agents": [{"agent_id":"...","provider":"..."}],
  "frozen_inputs": [{"key":"...","hash":"sha256:..."}],
  "evidence_pack_hash": "sha256:...",
  "decision_receipts": ["sha256:..."],
  "operator_signatures": ["sha256:..."],
  "started_at": "<iso8601>",
  "ended_at": "<iso8601>",
  "deterministic_seed": "<hex>"
}
```

### 3 modos de replay

| Modo | Side effects | Uso |
|------|--------------|-----|
| `audit` | zero | re-validar eventos sem mexer em nada |
| `simulation` | sandboxed | rodar replay em ambiente isolado para QA |
| `what_if` | sandboxed + variacao | mudar 1 input, ver outcome alternativo |

### Hash chain canonico

Cada evento da Obra inclui:
- `event_hash`
- `prev_event_hash`
- `obra_root_hash`

Chain quebrada bloqueia replay e dispara alerta.

## Fluxo

```mermaid
flowchart LR
  Manifest[carrega manifesto]
  Manifest --> Mode{modo?}
  Mode -->|audit| Audit[re-validar hashes]
  Mode -->|simulation| Sim[run sandbox]
  Mode -->|what_if| WI[run sandbox + variacao]
  Audit --> Report[audit report]
  Sim --> Report
  WI --> Diff[diff vs original]
```

## Regras para IA

- Replay nunca toca producao.
- Replay registra novo `replay_run.v1` evento.
- What-if exige Operator Decision Receipt.

## Escopo de Implementacao

`AtlasObraReplayService`, `AtlasHashChainValidator`, `AtlasReplaySandboxRuntime`.

## Dependencias

Evidence Cert Runtime, Parallel Multi-Agent Spec (T2.2).

## Evidencias

Comando: `atlas:obra:replay --obra=<id> --mode=audit|simulation|what_if --json`.

## Riscos

Sandbox vaza para producao, hash chain quebrado nao detectado, what-if usado sem receipt.

## O que este doc NAO e

Nao e schema de receipt isolado (existe em Evidence Cert Runtime); e replay E2E.

## Exemplos

Obra de notif (5 dias, 18 eventos): replay audit re-valida 18 eventos em <30s; what-if substitui provider Claude por Codex e mostra diff de duration/quality.

## Proximas Acoes

1. Implementar `AtlasObraReplayService`.
2. Hash chain validator integrado a Evidence Ledger.
3. Sandbox isolado.
