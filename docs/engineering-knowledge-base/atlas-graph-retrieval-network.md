---
id: atlas-graph-retrieval-network
type: engineering_knowledge
title: Atlas Graph Retrieval Network
status: building
implementation_state: building_bounded_codebase_world_model_retrieval
blocker: AGRN bounded sobre Codebase World Model existe; global/external graph retrieval continua future-governed ate AP, privacy, replay, rollback e golden set.
category: intelligence-runtime
priority: 99
summary: Doc filha AUCRI para Graph RAG global governado sobre semantic graph, codebase world model, reality graph, evidence edges e temporal truth.
tags: [atlas-ai, aucri, agrn, graph-rag, graph-retrieval]
capabilities: [graph_rag, graph_traversal, relation_retrieval, temporal_edges]
decisions:
  - AGRN nao pode ativar external/global Graph RAG sem AP, privacy, replay e rollback.
  - AGRN bounded atual e read-only, provider-safe e sem writes; apenas consulta Codebase World Model.
maintenance:
  - Atualizar quando graph_retrieval global sair de future_governed.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/atlas-world-model.md
  - app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php
  - app/Console/Commands/AtlasGraphRetrievalNetworkCommand.php
  - tests/Feature/Ai/Context/GraphRetrievalNetworkTest.php
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
graph_status: building
graph_source: repo
human_name: Atlas Graph Retrieval Network
canonical_name: Atlas Graph Retrieval Network
technical_name: AtlasGraphRetrievalNetworkService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-graph-retrieval-network.md
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
  - app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php
  - app/Console/Commands/AtlasGraphRetrievalNetworkCommand.php
  - tests/Feature/Ai/Context/GraphRetrievalNetworkTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/Context/GraphRetrievalNetworkTest.php"
  - "php artisan atlas:context:graph-retrieval --query='router tests' --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Integrar AGRN como source opcional em AHRI/ACRS depois de ACOP/AREBA.
  - Criar AP/plan para mover graph_retrieval global de future_governed para bounded.
---
# Atlas Graph Retrieval Network

## Resumo

AGRN e o bloco 7 da AUCRI. Ele faz retrieval por relacoes: edges, dependencias,
causalidade, documentos governantes, testes, entidades e temporal truth.
O runtime atual e bounded e read-only sobre Codebase World Model.

## Papel no Atlas

Ir alem de similaridade textual. Recuperar contexto porque ele e relacionado,
causal, dependente ou governante.

## Onde Se Encaixa

```text
graph stores -> traversal -> graph evidence set -> ACRS
```

Atual:

```text
Codebase World Model -> WorldModelGraphRanker -> AGRN receipt -> AUCRI audit
```

## Contratos

- `atlas.aucri.graph_query.v1`
- `atlas.aucri.graph_evidence_set.v1`
- `atlas.aucri.graph_traversal_receipt.v1`
- `atlas.aucri.graph_retrieval_network.v1`

## Fluxo

1. Receber seeds.
2. Selecionar grafo permitido e bounded.
3. Travessar edges com budget.
4. Aplicar temporal/freshness.
5. Emitir graph evidence.

## Regras para IA

- Nao ativar global/external graph sem AP.
- Nao inventar edge.
- Nao ignorar valid_until/superseded_by.
- Nao expor texto cru da query; usar hashes/signatures.
- Em risco alto sem grafo, bloquear em vez de seguir.

## Escopo de Implementacao

Implementado: bounded graph retrieval sobre Codebase World Model, traversal
budget, hashes, receipt, comando e testes. Fora do escopo atual: global graph,
external graph runtime, writes, provider calls e promocao sem AP.

## Dependencias

AURG, Semantic Graph, Codebase World Model, TimeAwareWorldModel.

## Evidencias

Graph traversal receipt com seed hashes, edges, reasons, query signature e hash.

Comando:

```bash
php artisan atlas:context:graph-retrieval --query='router tests' --json
```

## Riscos

Grafo ruidoso, edges falsas, queries caras, privacy em nodes. Mitigacao atual:
bounded traversal, no writes, no providers, no raw query e fail-closed por risco.

## Exemplos

Arquivo -> simbolo -> teste -> doc canonico -> decision receipt.

## Proximas Acoes

1. Conectar AGRN em AHRI/ACRS como source opcional governada.
2. Provar com golden set antes de active/global.
