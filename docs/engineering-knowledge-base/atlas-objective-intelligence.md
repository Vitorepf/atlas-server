---
id: atlas-objective-intelligence
type: engineering_knowledge
title: Atlas Objective Intelligence
status: active
category: atlas-ai
priority: 100
summary: Camada que transforma prompts grandes, ambiguos ou aspiracionais em objetivos mensuraveis, restricoes, metricas, Definition of Done, riscos e criterios de sucesso para o Autonomous Intelligence OS.
tags:
  - atlas-ai
  - objective-intelligence
  - mission-mode
  - measurable-goals
capabilities:
  - objective_extraction
  - metric_design
  - constraint_mapping
  - success_criteria
  - ambiguity_resolution
decisions:
  - Prompt grande nao pode ir direto para execucao sem objetivo operacional.
  - Objetivo bom precisa conter metricas, restricoes, prazo, budget, riscos e DoD.
  - Quando informacao faltar, o Atlas deve assumir conservadoramente ou pedir decisao se o risco for alto.
maintenance:
  - Atualize este doc antes de mudar como missoes viram objetivos mensuraveis.
related_paths:
  - docs/engineering-knowledge-base/atlas-mission-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-objective-intelligence
graph_title: Atlas Objective Intelligence
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-objective-intelligence.md
allowed_changes:
  - Refinar heuristicas de objetivo, metrica, restricao e DoD.
forbidden_changes:
  - Executar missao complexa sem objetivo operacional.
  - Trocar objetivo mensuravel por resumo generico.
depends_on:
  - atlas-mission-mode
flows_to:
  - atlas-domain-company-runtimes
  - atlas-experimentation-engine
unlocks:
  - measurable-autonomous-missions
governs:
  - atlas_ai.objective_definition
evidence:
  - docs/engineering-knowledge-base/atlas-objective-intelligence.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Resumo, Fluxo, Regras para IA, Evidencias, Riscos e Definition of Done.
quality_gates:
  - objective-defined
  - metrics-defined
  - constraints-defined
  - dod-defined
failure_modes:
  - Objetivo ambiguo demais.
  - Metrica errada otimiza o comportamento errado.
  - Budget/prazo ignorado.
observability_signals:
  - objective_id
  - primary_metric
  - constraints
  - dod_hash
next_actions:
  - Implementar builder de objective record.
line_limit: 520
---
# Atlas Objective Intelligence

## Resumo

Atlas Objective Intelligence transforma pedidos humanos amplos em objetivos
operacionais. Ele impede que o Atlas execute uma missao grande sem saber o que
significa sucesso.

## Papel no Atlas

Fica entre Mission Mode e os runtimes de dominio. Mission Mode decide que ha
uma missao; Objective Intelligence define o alvo que os runtimes precisam bater.

## Onde Se Encaixa

```text
Prompt -> Mission Mode -> Objective Intelligence -> Domain Runtime -> Evidence
```

## Contratos

- `atlas.ai.objective.v1`
- `atlas.ai.objective.metric.v1`
- `atlas.ai.objective.constraint.v1`
- `atlas.ai.objective.definition_of_done.v1`

Campos minimos: `objective_id`, `mission_id`, `objective`, `primary_metric`,
`secondary_metrics`, `constraints`, `assumptions`, `risks`, `definition_of_done`,
`blockers`, `receipt_hash`.

## Fluxo

1. Receber mission.
2. Extrair objetivo principal.
3. Separar subobjetivos.
4. Definir metricas de sucesso.
5. Mapear prazo, budget, risco, credenciais, jurisdicao e restricoes.
6. Criar Definition of Done.
7. Identificar informacoes faltantes.
8. Decidir assumir, pesquisar ou pedir decisao.
9. Emitir objective receipt.

## Regras para IA

- Nao executar meta complexa sem objetivo e DoD.
- Nao inventar budget, prazo ou permissao como fato.
- Quando a meta for "vender", separar criar infraestrutura de gerar receita.
- Quando a metrica for incerta, declarar metricas candidatas e escolher a mais
  operacional.
- Nao usar vaidade como metrica principal quando negocio exige resultado.

## Escopo de Implementacao

Criar service que recebe prompt/mission e retorna objective record com metrica,
restricoes, assumptions, DoD e blockers. Integrar com Mission Mode, Domain
Router e Experimentation Engine.

## Dependencias

- Mission Mode.
- Autonomous Intelligence OS.
- Evidence & Truth Layer.
- Permission, Budget & Safety Layer.

## Evidencias

Objective receipt, DoD hash, assumptions list, fontes usadas para restricoes,
decisoes pendentes e blockers.

## Riscos

- Objetivo mal definido pode fazer o Atlas trabalhar muito no problema errado.
- Metricas podem induzir spam, scraping indevido ou gasto excessivo se safety
  nao estiver acoplado.

## Exemplos

Prompt: "crie um ecommerce e realize vendas".

Objetivo operacional: lancar ecommerce funcional, publicar catalogo, configurar
pagamento, criar campanha inicial, medir visitas/conversao e registrar primeira
venda ou blocker real de produto/orcamento/trafego.

## Proximas Acoes

1. Criar persistencia `ai_objectives`.
2. Criar builder de DoD.
3. Criar testes de prompts ambiguos.

## Definition of Done

Esta pronto quando toda mission nao trivial possui objetivo mensuravel, DoD,
restricoes, assumptions, blockers e hash antes de executar.

