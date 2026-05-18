---
id: atlas-world-model
type: engineering_knowledge
title: Atlas World Model
status: active
category: atlas-ai
priority: 100
summary: Modelo operacional do mundo digital que organiza mercados, plataformas, concorrentes, APIs, repositorios, custos, riscos, oportunidades, entidades e relacoes para orientar missoes autonomas.
tags:
  - atlas-ai
  - world-model
  - market-intelligence
  - context
capabilities:
  - entity_mapping
  - relationship_graph
  - market_context
  - platform_context
  - opportunity_mapping
decisions:
  - O Atlas precisa de world model alem do codebase model.
  - Pesquisa e automacao devem consultar entidades, relacoes, custos e riscos antes de agir.
maintenance:
  - Atualize este doc antes de mudar representacao de contexto mundial.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-world-model
graph_title: Atlas World Model
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-world-model.md
allowed_changes:
  - Adicionar novos tipos de entidade e edge.
forbidden_changes:
  - Tratar contexto temporario como verdade permanente sem validade.
depends_on:
  - atlas-evidence-truth-layer
flows_to:
  - atlas-objective-intelligence
  - atlas-experimentation-engine
unlocks:
  - world-aware-autonomous-execution
governs:
  - atlas_ai.world_context
evidence:
  - docs/engineering-knowledge-base/atlas-world-model.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Entidades, Edges e Regras de Validade.
quality_gates:
  - entities-mapped
  - sources-linked
  - freshness-known
  - confidence-set
failure_modes:
  - Contexto desatualizado.
  - Fonte fraca vira fato.
  - Relacao causal falsa.
observability_signals:
  - world_model_id
  - entity_count
  - source_count
  - freshness
next_actions:
  - Criar persistencia de world entities.
line_limit: 520
---
# Atlas World Model

## Resumo

Atlas World Model e o modelo operacional do mundo digital: mercados,
concorrentes, plataformas, APIs, repositorios, custos, riscos, oportunidades,
entidades e relacoes.

## Papel no Atlas

Ele permite que o Atlas decida com contexto real, nao apenas com texto do
prompt. Complementa o Codebase World Model usado em engenharia.

## Onde Se Encaixa

```text
Research/Tools/Web/GitHub -> World Model -> Objective/Plan/Execution
```

## Contratos

- `atlas.ai.world_model.v1`
- `atlas.ai.world_entity.v1`
- `atlas.ai.world_edge.v1`
- `atlas.ai.world_source.v1`
- `atlas.ai.world_snapshot.v1`

## Fluxo

1. Identificar entidades relevantes.
2. Coletar fontes.
3. Criar nodes e edges.
4. Classificar confianca e freshness.
5. Relacionar oportunidades, riscos, ferramentas e plataformas.
6. Consultar durante planejamento.
7. Atualizar com outcomes.

## Entidades

Mercado, empresa, produto, plataforma, API, repo, ferramenta, pessoa publica,
canal, audiencia, fornecedor, regulacao, custo, risco, oportunidade e metrica.

## Edges

`competes_with`, `uses`, `offers`, `requires`, `blocks`, `costs`,
`integrates_with`, `substitutes`, `depends_on`, `creates_opportunity`,
`creates_risk`.

## Regras para IA

- Sempre registrar fonte e data.
- Diferenciar fato, estimativa e inferencia.
- Nao usar contexto expirado para decisao sensivel.
- Nao transformar correlacao em causalidade.
- Atualizar world model apos experimento real.

## Escopo de Implementacao

Persistencia de entities/edges/sources/snapshots, query por dominio, freshness
policy, confidence score e integracao com Research e Tool Economy.

## Dependencias

- Evidence & Truth Layer.
- Deep Research Runtime.
- Tool Economy.

## Evidencias

Fonte, data, trecho/resumo permitido, confidence, hash, entidade afetada e edge.

## Riscos

- Dados de mercado mudam rapido.
- Fontes podem ser enviesadas.
- Scraping pode ter restricoes.

## Exemplos

Para ecommerce: mapear nichos, concorrentes, fornecedores, plataformas,
pagamentos, canais, custos, oportunidades e riscos.

## Proximas Acoes

1. Criar schema de entities/edges.
2. Criar source freshness policy.
3. Integrar com Research Runtime.

## Definition of Done

Esta pronto quando missoes consultam contexto estruturado com fontes, freshness,
confidence e relacoes antes de decisoes relevantes.

## Codebase Variant: Graph-Aware Ranking

O Codebase World Model (`ai_codebase_world_models` + `_nodes` + `_edges`,
populado por `AtlasAutonomousEngineeringService::buildWorldModel`) ja
materializa nodes (file/module/test/doc/command/service/migration) com
`flow_id`, `capabilities[]` e `risks[]`, alem de edges `tests`, `documents`,
`defines`, `depends_on`, `contains_symbol` e `invokes`.

`App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker`
consome essas tabelas e produz `atlas.ai.codebase_world_model.ranking.v1`:
combina textual seeds, target files/flows/capabilities/risks e edge weights
para ranquear nodes por **relacao**, nao apenas por substring de path.

Cada `ranked_node` carrega `score`, `text_score`, `graph_score`,
`confidence`, `reasons[]` (governing_doc_for_seed, test_covers_seed,
risk_match:*, capability_overlap:*, query_target_flow_match, etc) e
`relation_path[]` (`{from,to,edge_type,direction}`). O envelope expoe
`graph_version` (`model_hash`), `graph_hash` (sha256 sobre node+edge
signatures), `query_signature` e `result_hash` para replay deterministico.

Edge weights atuais (todas advisory, podem mover via AP):

- `documents` / `documented_by`: 0.50 — fonte governante.
- `tests`: 0.45 — cobertura validada.
- `defines`: 0.30 — fonte declara o seed.
- `depends_on`: 0.25 — impacto direto.
- `invokes`: 0.30 — chamada nominal.
- `contains_symbol`: 0.20 — pertinencia estrutural.

Integracao segura: por ora o ranker e standalone (consumivel por testes
e por callers que ja conhecem o World Model). Integracao com
`ProgrammingProfessionalReranker` permanece como missao futura.

