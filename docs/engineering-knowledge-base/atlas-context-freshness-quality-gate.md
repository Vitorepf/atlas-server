---
id: atlas-context-freshness-quality-gate
type: engineering_knowledge
title: Atlas Context Freshness Quality Gate
status: planned
implementation_state: planned_child_architecture_not_current_runtime
blocker: ACFQ ainda nao existe como gate global; freshness existe em TEOS/Long Horizon.
category: intelligence-runtime
priority: 98
summary: Doc filha AUCRI para gate de freshness e qualidade: bloqueia contexto velho, contraditorio, sem autoridade, sem fonte obrigatoria ou com privacidade inadequada.
tags: [atlas-ai, aucri, acfq, freshness, quality-gate]
capabilities: [freshness_gate, context_quality, contradiction_detection, required_source_gate]
decisions:
  - Contexto stale deve bloquear ou exigir nova retrieval pass em tarefas de risco.
maintenance:
  - Atualizar quando policies de freshness ou autoridade mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - app/Services/Ai/LongHorizon/Gate/LongHorizonContextFreshnessGate.php
  - app/Services/Ai/LongHorizon/TimeAwareWorldModelService.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Context Freshness & Quality Gate
runtime_acronym: ACFQ
internal_product_name: Atlas Freshness Gate
technical_runtime: AtlasContextFreshnessQualityGateService
graph_id: atlas-context-freshness-quality-gate
graph_title: Atlas Context Freshness Quality Gate
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-context-freshness-quality-gate.md
allowed_changes:
  - Definir policies por dominio e risco.
forbidden_changes:
  - Permitir contexto expirado em decisao sensivel sem review.
depends_on: [atlas-context-ranking-system]
flows_to: [atlas-retrieval-feedback-loop]
unlocks: [fresh_context_execution, stale_context_blocking]
governs: [context_freshness, context_quality_gate]
evidence:
  - docs/engineering-knowledge-base/atlas-context-freshness-quality-gate.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Generalizar LongHorizon freshness para AUCRI.
---

# Atlas Context Freshness Quality Gate

## Resumo

ACFQ e o bloco 5 da AUCRI. Ele valida se o contexto e atual, confiavel,
autorizado e suficiente antes de execucao.

## Papel no Atlas

Evitar que o Atlas use memoria antiga, doc obsoleta, edge superseded ou fonte
sem autoridade.

## Onde Se Encaixa

```text
ranked context -> freshness/quality gate -> context pack final
```

## Contratos

- `atlas.aucri.freshness_report.v1`
- `atlas.aucri.context_quality_gate.v1`
- `atlas.aucri.contradiction_report.v1`

## Fluxo

1. Verificar data/validade.
2. Verificar autoridade.
3. Detectar contradicoes.
4. Verificar fonte obrigatoria.
5. Passar, degradar, pedir retrieval ou bloquear.

## Regras para IA

- Nao transformar unknown freshness em current.
- Nao ignorar contradiction.
- Nao desbloquear high risk sem evidence.

## Escopo de Implementacao

Gate global, policies por dominio, integration com Time-Aware World Model.

## Dependencias

ACRS, TEOS, TimeAwareWorldModel, APCR/ACIE.

## Evidencias

Freshness report com status e action.

## Riscos

Bloqueio demais, policy frouxa, datas inventadas.

## Exemplos

Regra fiscal/financeira velha bloqueia resposta ate nova retrieval.

## Proximas Acoes

1. Criar policies por dominio.
2. Integrar stale/superseded edges.
