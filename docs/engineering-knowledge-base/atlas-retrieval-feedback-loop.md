---
id: atlas-retrieval-feedback-loop
type: engineering_knowledge
title: Atlas Retrieval Feedback Loop
status: planned
implementation_state: planned_child_architecture_not_current_runtime
blocker: ARFL ainda nao existe como closed-loop global; ha compounding e RAG feedback parciais.
category: intelligence-runtime
priority: 98
summary: Doc filha AUCRI para feedback de uso de contexto: refs uteis, ruido, misses, wrong-context, stale-context e outcome-aware retrieval learning.
tags: [atlas-ai, aucri, arfl, retrieval-feedback, compounding]
capabilities: [retrieval_feedback, missed_refs, noise_refs, context_roi, learning_candidate]
decisions:
  - Feedback de retrieval vira candidato governado, nao policy automatica.
maintenance:
  - Atualizar quando AEMOR/Compounding ou Memory feedback mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - app/Services/Ai/Compounding/AtlasRagFeedbackService.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Retrieval Feedback Loop
runtime_acronym: ARFL
internal_product_name: Atlas Context Learning
technical_runtime: AtlasRetrievalFeedbackLoopService
graph_id: atlas-retrieval-feedback-loop
graph_title: Atlas Retrieval Feedback Loop
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md
allowed_changes:
  - Definir feedback events, candidates e promotion gates.
forbidden_changes:
  - Auto-promover aprendizado sem guard.
depends_on: [atlas-context-freshness-quality-gate, atlas-execution-memory-outcome-runtime]
flows_to: [atlas-context-ranking-system, atlas-semantic-embedding-foundation]
unlocks: [outcome_aware_retrieval, context_roi_learning]
governs: [retrieval_feedback, context_learning]
evidence:
  - docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Mapear ai_rag_feedback_events e AEMOR candidates.
---

# Atlas Retrieval Feedback Loop

## Resumo

ARFL e o bloco 6 da AUCRI. Ele fecha o ciclo: quais contextos ajudaram, quais
atrapalharam, quais faltaram e quais devem influenciar ranking futuro.

## Papel no Atlas

Transformar outcome em melhoria de retrieval sem aprendizado falso.

## Onde Se Encaixa

```text
execution outcome -> context usage feedback -> learning candidate -> ACRS/ASEF
```

## Contratos

- `atlas.aucri.feedback_event.v1`
- `atlas.aucri.context_roi.v1`
- `atlas.aucri.missed_ref_candidate.v1`
- `atlas.aucri.noise_ref_candidate.v1`

## Fluxo

1. Registrar refs usadas.
2. Comparar outcome.
3. Detectar missing/noise/stale/wrong context.
4. Criar candidato.
5. Exigir review/policy.

## Regras para IA

- Nao aprender com falha sem causalidade.
- Nao promover feedback automatico para memoria.
- Nao esconder context noise.

## Escopo de Implementacao

Feedback events, integration com AEMOR/Compounding, score updates e tests.

## Dependencias

AEMOR, Compounding, ACRS, Evidence.

## Evidencias

Feedback event com outcome refs e learning candidate.

## Riscos

Falso aprendizado, reinforcing bias, ranking piorar por feedback fraco.

## Exemplos

Se um patch falha por doc ausente, ARFL registra missed_required_doc.

## Proximas Acoes

1. Unificar RAG feedback existente.
2. Criar context ROI score.
