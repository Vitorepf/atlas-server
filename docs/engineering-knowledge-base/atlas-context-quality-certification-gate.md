---
id: atlas-context-quality-certification-gate
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Context Quality Certification Gate
status: active
implementation_state: runtime_surface_synthetic_certification_ready
category: context_retrieval_intelligence
priority: 99
summary: Gate read-only de qualidade de contexto/memoria. O score numerico deriva EXCLUSIVAMENTE do harness real LocalRagBenchmarkService (precisao real do router + recall pgvector real); quando nao ha medicao real, NAO emite numero e marca status synthetic_readiness_only. Stress lab, corpus sintetico, replay e AEMOR feed sao apenas estrutura de readiness declarada, nunca viram numero fabricado.
tags: [atlas-ai, aucri, context-quality, stress-lab, synthetic-corpus, replay, adversarial, certification]
capabilities: [context_quality_certification, stress_lab, synthetic_long_horizon_corpus, adversarial_context_eval, replay_harness]
decisions:
  - Context Quality Certification mede readiness sintetica interna, nao claim externo.
  - O harness MiroFish-like existente e Scenario Simulation Harness; este gate reaproveita a ideia de simulacao, mas foca contexto/memoria/AUCRI.
  - R2 anti-over-claim: o score deriva SO do LocalRagBenchmarkService real (sem literais hardcoded, sem cap artificial, sem floor max(.,real)). Uma falha real de retrieval (queda de precisao, violacao provider-safe, stale-context, readiness blocked) ABAIXA/bloqueia o score. Sem medicao real -> sem numero (synthetic_readiness_only).
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
evidence_refs:
  - symbol: AtlasContextQualityCertificationService
  - command: atlas:context:quality-certify
  - test: ContextQualityCertificationTest
required_tests:
  - "php artisan atlas:context:quality-certify --json --strict"
  - "php artisan test tests/Feature/Ai/Context/ContextQualityCertificationTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Persistir historico longitudinal depois de ACOP.
  - Ampliar o corpus real de recall provider-safe (Dev/Forge/Research/Finance)
    para que a medicao real cubra mais casos (o score ja deriva do harness real).
---
# Atlas Context Quality Certification Gate

## Resumo

ACQCG e o gate que certifica a area de contexto/memoria/AUCRI. O score numerico
deriva EXCLUSIVAMENTE da unica medicao real do codebase: `LocalRagBenchmarkService`
(precisao de governanca do router + precisao@k real de recall via pgvector).
Quando essa medicao real nao existe (sem corpus provider-safe de recall), o gate
NAO emite numero e reporta `status=synthetic_readiness_only`, em vez de inventar
um valor. Ele nao substitui benchmark externo e impede autopromocao por opiniao.

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

1. Declarar a estrutura de readiness sintetica (corpus long-horizon, stressors,
   replay, adversarial, AEMOR) — tudo marcado `is_real_measurement=false`.
   Estrutura declarada NAO vira numero.
2. Rodar AUCRI enforcement e exigir 18 block refs executados.
3. Rodar AREBA para golden context benchmark e ACPFR para frontier.
4. Rodar o harness REAL `LocalRagBenchmarkService::report()` (router governanca +
   recall pgvector real).
5. Se houver medicao real (corpus de recall provider-safe `status=passed`):
   derivar o score 0..10 SO desses sinais reais (router precision, quality-corpus
   min-score, precision@3, precision@5) com penalidade direta por violacao
   provider-safe / contaminacao / stale-context / missed-critical. Sem cap
   artificial; sem floor protegendo o numero.
6. Se NAO houver medicao real: emitir `quality_score=null` e
   `status=synthetic_readiness_only` (nunca um numero fabricado).
7. Bloquear se o score real ficar abaixo do alvo, ou se houver violacao
   provider-safe real, ou se nao houver medicao real.

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

- `score_basis = real_local_rag_benchmark_measurement` quando `status=ready`
  (numero so com medicao real);
- `quality_score=null` + `status=synthetic_readiness_only` quando nao ha medicao real;
- uma falha real de retrieval ABAIXA/bloqueia o score (provado em
  `ContextQualityCertificationTest::test_real_retrieval_failure_lowers_and_blocks_the_score`);
- nenhum literal de score fabricado, nenhum cap artificial, nenhum floor
  `max(.,real)` no service (provado por teste de inspecao de source);
- 18 blocos AUCRI executados;
- claim policy com `score_from_real_measurement_only=true` e
  provider/rivals/benchmark externo falsos.

## Riscos

- (MITIGADO por R2) O score nao e mais teatro sintetico: deriva so de medicao
  real; sem medicao, nao ha numero.
- O corpus de recall real ainda e pequeno (poucos casos provider-safe); ampliar
  cobertura por Dev/Forge/Research/Finance e a proxima alavanca de qualidade.
- Confundir readiness interna declarada com claim contra Claude/Codex/Gemini.

## Exemplos

Comando canon:

```bash
php artisan atlas:context:quality-certify --json --strict
```

Alvo maximo (`target=10`). Sem o cap artificial de 9.95, ele e alcancavel
honestamente apenas quando TODOS os sinais reais sao perfeitos; com qualquer
queda real, o gate fica abaixo do alvo e o strict falha:

```bash
php artisan atlas:context:quality-certify --target=10 --json --strict
```

## Proximas Acoes

1. Persistir historico longitudinal em ACOP.
2. Expandir o corpus REAL de recall provider-safe por Dev, Forge, Research e
   Finance (o score ja deriva do harness real; falta cobertura).
3. Criar regressao por release antes de qualquer benchmark externo.
