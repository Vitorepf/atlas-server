---
id: atlas-context-ranking-system
type: engineering_knowledge
title: Atlas Context Ranking System
status: planned
implementation_state: planned_child_architecture_not_current_runtime
blocker: ACRS ainda nao possui reranker global unificado; existem rerankers parciais em Programming.
category: intelligence-runtime
priority: 98
summary: Doc filha AUCRI para ranking de contexto por relevancia, autoridade, freshness, graph distance, outcome history, risco e intencao.
tags: [atlas-ai, aucri, acrs, reranking, context-ranking]
capabilities: [context_ranking, authority_scoring, graph_distance, outcome_aware_ranking]
decisions:
  - Ranking final combina vector, lexical, graph, authority, freshness e outcomes.
  - Similaridade semantica nunca basta para entrar no context pack final.
maintenance:
  - Atualizar quando score, pesos ou feedback loop mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php
  - app/Services/Ai/Programming/ProgrammingProfessionalReranker.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Context Ranking System
runtime_acronym: ACRS
internal_product_name: Atlas Context Ranker
technical_runtime: AtlasContextRankingSystemService
graph_id: atlas-context-ranking-system
graph_title: Atlas Context Ranking System
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-context-ranking-system.md
allowed_changes:
  - Definir scoring, explainability e tests de ranking.
forbidden_changes:
  - Ranking opaco sem reasons.
depends_on: [atlas-agentic-rag-framework, atlas-hybrid-retrieval-infrastructure]
flows_to: [atlas-context-freshness-quality-gate, atlas-retrieval-feedback-loop]
unlocks: [low_noise_context_pack, outcome_aware_retrieval]
governs: [context_ranking, reranking]
evidence:
  - docs/engineering-knowledge-base/atlas-context-ranking-system.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Criar ranking spec com pesos iniciais e replay hash.
---

# Atlas Context Ranking System

## Resumo

ACRS e o bloco 4 da AUCRI. Ele decide quais candidatos entram no context pack
final e por que.

## Papel no Atlas

Reduzir ruido. O Atlas deve recuperar o contexto certo, nao apenas contexto
parecido.

## Onde Se Encaixa

```text
AHRI candidates -> ACRS rerank -> ACFQ gate -> context pack
```

## Contratos

- `atlas.aucri.rerank_result.v1`
- `atlas.aucri.context_score.v1`
- `atlas.aucri.excluded_ref.v1`

## Fluxo

1. Receber candidatos normalizados.
2. Calcular semantic, lexical, authority, graph, freshness e outcome scores.
3. Deduplicar por papel.
4. Excluir ruido.
5. Emitir reasons e hash.

## Regras para IA

- Nao incluir ref sem reason.
- Nao esconder excluded refs relevantes.
- Nao otimizar apenas similarity.

## Escopo de Implementacao

Reranker global, score explainable, tests com golden sets e integração gradual.

## Dependencias

AHRI, AARF, AEMOR/ARFL, WorldModelGraphRanker.

## Evidencias

Ranking com scores, reasons, excluded refs e deterministic hash.

## Riscos

Pesos ruins, feedback enviesado, fonte popular superar fonte correta.

## Exemplos

Doc canonico recente pode ganhar de memoria antiga semanticamente parecida.

## Proximas Acoes

1. Reusar ProgrammingProfessionalReranker.
2. Integrar WorldModelGraphRanker.
3. Criar golden set de ranking.
