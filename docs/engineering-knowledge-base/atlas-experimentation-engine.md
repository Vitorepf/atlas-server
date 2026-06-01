---
id: atlas-experimentation-engine
type: engineering_knowledge
title: Atlas Experimentation Engine
status: active
category: atlas-ai
priority: 100
summary: Runtime para transformar incerteza em experimentos mensuraveis com hipotese, teste, metrica, resultado, decisao, aprendizado e proxima iteracao em growth, vendas, produto, pesquisa e automacao.
tags:
  - atlas-ai
  - experimentation
  - growth
  - validation
capabilities:
  - hypothesis_design
  - experiment_planning
  - metric_tracking
  - iteration_loop
  - learning_capture
decisions:
  - Quando resultado depende do mundo, o Atlas deve experimentar e medir em vez de apenas planejar.
  - Falha experimental e evidencia util, nao erro a esconder.
maintenance:
  - Atualize este doc antes de mudar ciclo de experimentos.
related_paths:
  - docs/engineering-knowledge-base/atlas-objective-intelligence.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-experimentation-engine
graph_title: Atlas Experimentation Engine
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Experimentation Engine
canonical_name: Atlas Experimentation Engine
technical_name: atlas-experimentation-engine
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-experimentation-engine.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-experimentation-engine.md
allowed_changes:
  - Adicionar tipos de experimento e metricas por dominio.
forbidden_changes:
  - Declarar sucesso sem metrica ou evidencia.
depends_on:
  - atlas-objective-intelligence
flows_to:
  - atlas-evidence-truth-layer
  - atlas-compounding-engineering-intelligence
unlocks:
  - measurable-growth-and-validation
governs:
  - atlas_ai.experiments
evidence:
  - docs/engineering-knowledge-base/atlas-experimentation-engine.md
evidence_refs:
  - symbol: AtlasExperimentationEngineService
  - command: atlas:aaeos:experimentation-engine
  - test: AtlasExperimentationEngineTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Fluxo e Regras para IA antes de growth, vendas ou validacao.
quality_gates:
  - hypothesis-written
  - metric-selected
  - sample-defined
  - result-recorded
failure_modes:
  - Teste sem hipotese.
  - Metrica de vaidade.
  - Conclusao sem amostra suficiente.
observability_signals:
  - experiment_id
  - metric
  - result
  - decision
next_actions:
  - Criar experiment records.
line_limit: 520
---
# Atlas Experimentation Engine

## Resumo

Experimentation Engine transforma incerteza em ciclos medidos: hipotese, teste,
metrica, resultado, decisao, aprendizado e proxima iteracao.

## Papel no Atlas

E essencial para ecommerce, vendas, marketing, produto, pesquisa aplicada e
automacao. O Atlas nao deve prometer resultado de mercado sem testar.

## Onde Se Encaixa

```text
Objective -> Hypothesis -> Experiment -> Measurement -> Decision -> Learning
```

## Contratos

- `atlas.ai.experiment.v1`
- `atlas.ai.experiment.hypothesis.v1`
- `atlas.ai.experiment.metric.v1`
- `atlas.ai.experiment.result.v1`
- `atlas.ai.experiment.decision.v1`

## Fluxo

1. Receber objetivo e metrica.
2. Escrever hipotese.
3. Definir teste minimo.
4. Definir amostra, canal e duracao.
5. Rodar safety/budget gate.
6. Executar.
7. Medir.
8. Decidir continuar, pivotar, escalar ou parar.
9. Registrar aprendizado.

## Regras para IA

- Nao chamar opiniao de validacao.
- Nao otimizar metrica de vaidade quando metrica de negocio existe.
- Nao escalar campanha sem resultado minimo e budget aprovado.
- Nao esconder experimento inconclusivo.

## Escopo de Implementacao

Experiment service, metrics registry, result store, decision policy,
integration com Control Plane e Compounding.

## Dependencias

- Objective Intelligence.
- Permission Budget Safety.
- Evidence & Truth Layer.
- World Model.

## Evidencias

Hipotese, setup, publico/amostra, metricas, resultado, screenshots/logs,
custos, decisao e learning outcome.

## Riscos

- Dados pequenos geram conclusao falsa.
- Custo pode escalar.
- Experimentos de marketing podem ter risco reputacional.

## Exemplos

Ecommerce: testar tres ofertas com landing pages pequenas antes de construir
operacao complexa.

## Proximas Acoes

1. Criar metrics registry.
2. Criar experiment command.
3. Criar tests de resultado inconclusivo.

## Definition of Done

Esta pronto quando missoes incertas geram experimento mensuravel e decisao
baseada em resultado, nao opiniao.

