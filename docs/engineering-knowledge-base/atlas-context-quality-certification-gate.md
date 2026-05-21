---
id: atlas-context-quality-certification-gate
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Context Quality Certification Gate
status: active
implementation_state: runtime_surface_synthetic_certification_ready
category: context_retrieval_intelligence
priority: 99
summary: Gate read-only que usa stress lab, corpus sintetico long-horizon, replay massivo, golden benchmark, adversarial eval, AUCRI e AEMOR feed simulado para certificar qualidade de contexto em alvo 9.8.
tags: [atlas-ai, aucri, context-quality, stress-lab, synthetic-corpus, replay, adversarial, certification]
capabilities: [context_quality_certification, stress_lab, synthetic_long_horizon_corpus, adversarial_context_eval, replay_harness]
decisions:
  - Context Quality Certification mede readiness sintetica interna, nao claim externo.
  - O harness MiroFish-like existente e Scenario Simulation Harness; este gate reaproveita a ideia de simulacao, mas foca contexto/memoria/AUCRI.
  - Score 9.8 so vale para synthetic readiness local, sem provider, sem rivals e sem benchmark externo.
maintenance:
  - Atualizar antes de mudar metricas, thresholds, stressors, corpus ou score formula.
product_name: Atlas Context Quality Certification Gate
runtime_acronym: ACQCG
internal_product_name: Atlas Context 9.8 Readiness Gate
technical_runtime: AtlasContextQualityCertificationService
macro_layer: false
graph_id: atlas-context-quality-certification-gate
graph_title: Atlas Context Quality Certification Gate
graph_world: atlas
graph_parent: atlas-unified-context-retrieval-intelligence
graph_layer: module
graph_kind: module
graph_status: active
graph_source: repo
human_name: Atlas Context Quality Certification Gate
canonical_name: Atlas Context Quality Certification Gate
technical_name: AtlasContextQualityCertificationService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-context-quality-certification-gate.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-context-quality-certification-gate.md
  - app/Services/Ai/Context/AtlasContextQualityCertificationService.php
  - app/Console/Commands/AtlasContextQualityCertifyCommand.php
  - tests/Feature/Ai/Context/ContextQualityCertificationTest.php
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md
  - docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md
  - docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
allowed_changes:
  - Ajustar corpus sintetico, metricas, adversarial categories e thresholds com testes.
forbidden_changes:
  - Declarar superioridade externa.
  - Rodar provider, rivals ou benchmark externo dentro deste gate.
  - Tratar score sintetico como prova de producao real.
depends_on:
  - atlas-unified-context-retrieval-intelligence
  - atlas-retrieval-evaluation-benchmark-arena
  - atlas-context-pareto-frontier-runtime
  - atlas-ai-scenario-simulation-harness
flows_to:
  - atlas-context-observability-plane
  - atlas-aucri-continuous-optimization-protocol
unlocks:
  - context_quality_9_8_readiness_gate
  - synthetic_context_regression_suite
governs:
  - context_quality_certification
  - aucri_synthetic_readiness
evidence:
  - app/Services/Ai/Context/AtlasContextQualityCertificationService.php
  - app/Console/Commands/AtlasContextQualityCertifyCommand.php
  - tests/Feature/Ai/Context/ContextQualityCertificationTest.php
required_tests:
  - "php artisan atlas:context:quality-certify --json --strict"
  - "php artisan test tests/Feature/Ai/Context/ContextQualityCertificationTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Persistir historico longitudinal depois de ACOP.
  - Conectar score com AEMOR real quando houver outcomes suficientes.
---
# Atlas Context Quality Certification Gate

## Resumo

ACQCG e o gate que certifica se a area de contexto/memoria/AUCRI esta no nivel
9.8 de readiness sintetica interna. Ele nao substitui benchmark real. Ele
impede autopromocao por opiniao.

## Papel no Atlas

O papel e testar a camada de contexto antes de ela alimentar Atlas Dev, Forge e
outros flows. Ele mede se o Atlas consegue selecionar contexto certo, remover
ruido, preservar must-keep, resistir contexto adversarial e sustentar replay.

## Onde Se Encaixa

```text
AUCRI 18 blocos
-> AREBA golden retrieval
-> ACPFR quality/token frontier
-> Scenario Simulation pattern
-> ACQCG synthetic certification
-> ACOP/ACOPRO longitudinal observation
```

O harness inspirado em MiroFish/OASIS ja existe em
`atlas-ai-scenario-simulation-harness.md`. ACQCG e mais especifico: stress de
contexto, memoria, retrieval, replay e token economy.

## Contratos

- `atlas.context.quality_certification.v1`
- `atlas.context.stress_lab.v1`
- `atlas.context.synthetic_long_horizon_corpus.v1`
- `atlas.context.massive_replay_harness.v1`
- `atlas.context.golden_context_benchmark.v1`
- `atlas.context.adversarial_evaluation.v1`
- `atlas.context.embedding_graph_readiness.v1`
- `atlas.context.aemor_synthetic_feed.v1`

## Fluxo

1. Gerar corpus sintetico long-horizon com pelo menos 1000 casos.
2. Injetar stressors: stale docs, decisoes superseded, ruido, contradicao,
   must-keep loss attempt e confusao Dev/Forge.
3. Rodar AUCRI enforcement e exigir 18 block refs executados.
4. Rodar AREBA para golden context benchmark.
5. Rodar ACPFR para frontier qualidade/token.
6. Avaliar adversarial context e replay route/resume.
7. Gerar AEMOR feed simulado com outcomes positivos e negativos.
8. Calcular score e bloquear se ficar abaixo de 9.8.

## Regras para IA

- Nao chamar provider.
- Nao chamar rivals.
- Nao chamar benchmark externo.
- Nao expor raw prompt no payload.
- Nao promover score sintetico como superioridade real.
- Nao criar dominio novo; isto fica dentro de AUCRI.
- Se o placement parecer ambiguo, tratar como extensao de Context/AUCRI.

## Escopo de Implementacao

Implementado:

- `AtlasContextQualityCertificationService`
- `atlas:context:quality-certify --json --strict`
- `ContextQualityCertificationTest`

O service e read-only e retorna `writes=false`. Ele usa hashes e samples
deterministicos para nao despejar corpus bruto em JSON.

## Dependencias

Depende de AUCRI runtime enforcement, AREBA, ACPFR, AEMOR como modelo de
outcome e Scenario Simulation Harness como padrao conceitual de simulacao.

## Evidencias

Evidencia minima:

- score `>= 9.8`;
- 8 componentes prontos;
- pelo menos 1000 casos sinteticos;
- 18 blocos AUCRI executados;
- must_keep_coverage `1.0`;
- adversarial_detection_rate `>= 0.98`;
- claim policy com provider/rivals/benchmark externo falsos.

## Riscos

- Synthetic readiness virar teatro se nao for comparado com outcomes reais.
- Score alto esconder caso extremo fora do corpus.
- Otimizar token demais reduzir qualidade high-risk.
- Confundir readiness interna com claim contra Claude/Codex/Gemini.

## Exemplos

Comando canon:

```bash
php artisan atlas:context:quality-certify --json --strict
```

Falha esperada para alvo impossivel:

```bash
php artisan atlas:context:quality-certify --target=10 --json --strict
```

## Proximas Acoes

1. Persistir historico longitudinal em ACOP.
2. Alimentar AEMOR com outcomes reais.
3. Expandir corpus por Dev, Forge, Research e Finance.
4. Criar regressao por release antes de qualquer benchmark externo.
