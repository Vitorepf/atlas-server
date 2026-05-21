---
id: atlas-unified-reality-graph
type: engineering_knowledge
title: Atlas Unified Reality Graph
status: building
implementation_state: building_snapshot_over_asre_reality_entities
blocker: AURG snapshot read-only existe sobre entidades/relacionamentos ASRE; grafo unificado completo ainda depende de ingestion/trust/freshness ampliados.
category: intelligence-runtime
priority: 100
summary: Doc filha AUCRI para grafo unificado da realidade: empresas, projetos, pessoas, documentos, codigo, decisoes, metas, riscos, oportunidades, outcomes e evidencias.
tags: [atlas-ai, aucri, aurg, reality-graph, world-model]
capabilities: [reality_graph, world_model, entity_edges, strategic_context]
decisions:
  - AURG e grafo de realidade, nao substitui memoria, evidence ou ASRE.
  - Toda edge precisa fonte, confidence, freshness e authority.
  - Runtime atual reusa `atlas_reality_entities` e `atlas_reality_relationships`; nao cria store paralelo.
maintenance:
  - Atualizar quando entity/edge schemas forem definidos.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-world-model.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
  - app/Services/Ai/Context/AtlasUnifiedRealityGraphService.php
  - app/Console/Commands/AtlasUnifiedRealityGraphCommand.php
  - tests/Feature/Ai/Context/UnifiedRealityGraphTest.php
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
graph_status: building
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
  - app/Services/Ai/Context/AtlasUnifiedRealityGraphService.php
  - app/Console/Commands/AtlasUnifiedRealityGraphCommand.php
  - tests/Feature/Ai/Context/UnifiedRealityGraphTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/Context/UnifiedRealityGraphTest.php"
  - "php artisan atlas:context:reality-graph --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Conectar ingestion/trust/freshness ampliados ao snapshot AURG.
---

# Atlas Unified Reality Graph

## Resumo

AURG e o bloco 8 da AUCRI. Ele conecta a realidade operacional do Atlas:
empresas, projetos, decisoes, pessoas, docs, codigo, metas, riscos,
oportunidades e outcomes. O runtime atual e snapshot read-only sobre o grafo
ASRE existente.

## Papel no Atlas

Dar contexto relacional global para ASRE, AARS, Research, Finance, Marketing,
Dev e Forge.

## Onde Se Encaixa

```text
memory/docs/evidence/world/code -> entities/edges -> graph snapshots -> AGRN
```

Atual:

```text
atlas_reality_entities + atlas_reality_relationships -> AURG snapshot -> AGRN/ASRE
```

## Contratos

- `atlas.aucri.reality_entity.v1`
- `atlas.aucri.reality_edge.v1`
- `atlas.aucri.reality_source.v1`
- `atlas.aucri.reality_snapshot.v1`

## Fluxo

1. Ler entities/edges governadas existentes.
2. Projetar entity/edge refs com hashes.
3. Classificar confidence/freshness.
4. Emitir sources e quality gate.
5. Gerar snapshot.
6. Servir AGRN/ASRE/AARS.

## Regras para IA

- Nao criar fato sem fonte.
- Nao misturar desejo, plano e realidade.
- Nao usar dado stale em decisao sensivel.
- Nao expor atributos crus sensiveis no snapshot.
- Nao criar store paralelo ao ASRE.

## Escopo de Implementacao

Implementado: schemas, projections, read model, comando e testes sobre
persistencia ASRE. Fora do escopo atual: ingestion massivo, trust scoring
profundo e graph global completo.

## Dependencias

World Model, Semantic Graph, Evidence, Memory, AEMOR.

## Evidencias

Entities/edges com source refs, confidence, freshness e hashes.

Comando:

```bash
php artisan atlas:context:reality-graph --json
```

## Riscos

Grafo virar lixo, causalidade falsa, privacidade empresarial. Mitigacao atual:
snapshot read-only, no providers, no writes, no raw attributes e fail-closed por
risco.

## Exemplos

Empresa -> projeto -> decisao -> outcome -> risco -> proxima acao.

## Proximas Acoes

1. Integrar AKIF/ARPTL para lineage e trust ampliados.
2. Conectar snapshot AURG em AGRN/ASRE com quality gate.
