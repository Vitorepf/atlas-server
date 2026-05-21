---
id: atlas-graph-retrieval-network
type: engineering_knowledge
title: Atlas Graph Retrieval Network
status: planned
implementation_state: planned_child_architecture_not_current_runtime
blocker: AGRN global ainda e future-governed; apenas Graph RAG bounded/local de Programming existe.
category: intelligence-runtime
priority: 99
summary: Doc filha AUCRI para Graph RAG global governado sobre semantic graph, codebase world model, reality graph, evidence edges e temporal truth.
tags: [atlas-ai, aucri, agrn, graph-rag, graph-retrieval]
capabilities: [graph_rag, graph_traversal, relation_retrieval, temporal_edges]
decisions:
  - AGRN nao pode ativar external/global Graph RAG sem AP, privacy, replay e rollback.
maintenance:
  - Atualizar quando graph_retrieval sair de future_governed.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/atlas-world-model.md
  - app/Services/Ai/Programming/ProgrammingGraphRagRuntime.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Graph Retrieval Network
runtime_acronym: AGRN
internal_product_name: Atlas Graph RAG
technical_runtime: AtlasGraphRetrievalNetworkService
graph_id: atlas-graph-retrieval-network
graph_title: Atlas Graph Retrieval Network
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-graph-retrieval-network.md
allowed_changes:
  - Definir graph traversal bounded, AP gates e rollback.
forbidden_changes:
  - Promover graph_retrieval global sem review.
depends_on: [atlas-context-freshness-quality-gate, atlas-unified-reality-graph]
flows_to: [atlas-hybrid-retrieval-infrastructure, atlas-context-ranking-system]
unlocks: [global_graph_rag, relation_aware_context]
governs: [graph_rag, graph_retrieval]
evidence:
  - docs/engineering-knowledge-base/atlas-graph-retrieval-network.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Criar AP/plan para mover graph_retrieval de future_governed para bounded.
---

# Atlas Graph Retrieval Network

## Resumo

AGRN e o bloco 7 da AUCRI. Ele faz retrieval por relacoes: edges, dependencias,
causalidade, documentos governantes, testes, entidades e temporal truth.

## Papel no Atlas

Ir alem de similaridade textual. Recuperar contexto porque ele e relacionado,
causal, dependente ou governante.

## Onde Se Encaixa

```text
graph stores -> traversal -> graph evidence set -> ACRS
```

## Contratos

- `atlas.aucri.graph_query.v1`
- `atlas.aucri.graph_evidence_set.v1`
- `atlas.aucri.graph_traversal_receipt.v1`

## Fluxo

1. Receber seeds.
2. Selecionar grafo permitido.
3. Travessar edges com budget.
4. Aplicar temporal/freshness.
5. Emitir graph evidence.

## Regras para IA

- Nao ativar global graph sem AP.
- Nao inventar edge.
- Nao ignorar valid_until/superseded_by.

## Escopo de Implementacao

Bounded graph retrieval, graph adapters, traversal budget, tests e rollback.

## Dependencias

AURG, Semantic Graph, Codebase World Model, TimeAwareWorldModel.

## Evidencias

Graph traversal receipt com seeds, edges, reasons e hash.

## Riscos

Grafo ruidoso, edges falsas, queries caras, privacy em nodes.

## Exemplos

Arquivo -> simbolo -> teste -> doc canonico -> decision receipt.

## Proximas Acoes

1. Comecar bounded global.
2. Provar com golden set antes de active.
