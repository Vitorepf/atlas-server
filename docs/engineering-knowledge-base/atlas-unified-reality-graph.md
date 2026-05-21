---
id: atlas-unified-reality-graph
type: engineering_knowledge
title: Atlas Unified Reality Graph
status: planned
implementation_state: planned_child_architecture_not_current_runtime
blocker: AURG ainda nao possui runtime/persistencia unificada; existem World Model e Semantic Graph parciais.
category: intelligence-runtime
priority: 100
summary: Doc filha AUCRI para grafo unificado da realidade: empresas, projetos, pessoas, documentos, codigo, decisoes, metas, riscos, oportunidades, outcomes e evidencias.
tags: [atlas-ai, aucri, aurg, reality-graph, world-model]
capabilities: [reality_graph, world_model, entity_edges, strategic_context]
decisions:
  - AURG e grafo de realidade, nao substitui memoria, evidence ou ASRE.
  - Toda edge precisa fonte, confidence, freshness e authority.
maintenance:
  - Atualizar quando entity/edge schemas forem definidos.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-world-model.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Unified Reality Graph
runtime_acronym: AURG
internal_product_name: Atlas Reality Map
technical_runtime: AtlasUnifiedRealityGraphService
graph_id: atlas-unified-reality-graph
graph_title: Atlas Unified Reality Graph
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-unified-reality-graph.md
allowed_changes:
  - Definir entities, edges, projections e snapshots.
forbidden_changes:
  - Transformar inferencia em fato.
  - Criar truth graph sem evidence.
depends_on: [atlas-context-freshness-quality-gate, atlas-world-model]
flows_to: [atlas-graph-retrieval-network, atlas-strategic-reality-engine, atlas-autonomous-reality-sandbox]
unlocks: [reality_aware_context, strategic_graph_decision]
governs: [reality_graph, world_context]
evidence:
  - docs/engineering-knowledge-base/atlas-unified-reality-graph.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Criar AURG-I1 schema de entities/edges/sources/snapshots.
---

# Atlas Unified Reality Graph

## Resumo

AURG e o bloco 8 da AUCRI. Ele conecta a realidade operacional do Atlas:
empresas, projetos, decisoes, pessoas, docs, codigo, metas, riscos,
oportunidades e outcomes.

## Papel no Atlas

Dar contexto relacional global para ASRE, AARS, Research, Finance, Marketing,
Dev e Forge.

## Onde Se Encaixa

```text
memory/docs/evidence/world/code -> entities/edges -> graph snapshots -> AGRN
```

## Contratos

- `atlas.aucri.reality_entity.v1`
- `atlas.aucri.reality_edge.v1`
- `atlas.aucri.reality_source.v1`
- `atlas.aucri.reality_snapshot.v1`

## Fluxo

1. Ingerir fontes governadas.
2. Criar entities.
3. Criar edges com evidence.
4. Classificar confidence/freshness.
5. Gerar snapshots.
6. Servir AGRN/ASRE/AARS.

## Regras para IA

- Nao criar fato sem fonte.
- Nao misturar desejo, plano e realidade.
- Nao usar dado stale em decisao sensivel.

## Escopo de Implementacao

Schemas, projections, read model, query service e cert.

## Dependencias

World Model, Semantic Graph, Evidence, Memory, AEMOR.

## Evidencias

Entities/edges com source refs, confidence, freshness e hashes.

## Riscos

Grafo virar lixo, causalidade falsa, privacidade empresarial.

## Exemplos

Empresa -> projeto -> decisao -> outcome -> risco -> proxima acao.

## Proximas Acoes

1. Definir entity taxonomy.
2. Criar source authority model.
