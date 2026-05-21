---
id: atlas-retrieval-evaluation-benchmark-arena
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Retrieval Evaluation & Benchmark Arena
status: planned
implementation_state: planned_child_architecture_not_current_runtime
blocker: AREBA ainda nao possui service, golden sets, command, persistence ou testes; e bloco 10 da AUCRI.
category: context_retrieval_intelligence
priority: 96
summary: "Arena canonica para medir qualidade de retrieval, contexto, groundedness, regressao e ROI antes de promover mudancas em AUCRI."
tags: [atlas-ai, aucri, areba, retrieval-evaluation, benchmark, golden-set]
capabilities: [retrieval_eval, golden_sets, regression_gate, context_roi, groundedness]
decisions:
  - AREBA mede qualidade antes de qualquer claim de melhoria.
  - Golden sets internos sao obrigatorios antes de benchmark externo.
  - Contexto maior nao e melhoria se aumenta ruido ou remove fonte critica.
maintenance:
  - Atualizar antes de mudar metricas, golden sets, regression gates ou benchmark policy.
product_name: Atlas Retrieval Evaluation & Benchmark Arena
runtime_acronym: AREBA
internal_product_name: Atlas Context Arena
technical_runtime: AtlasRetrievalEvaluationBenchmarkArenaService
macro_layer: true
graph_id: atlas-retrieval-evaluation-benchmark-arena
graph_title: Atlas Retrieval Evaluation & Benchmark Arena
graph_world: atlas
graph_parent: atlas-unified-context-retrieval-intelligence
graph_layer: module
graph_kind: module
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md
  - docs/engineering-knowledge-base/atlas-context-ranking-system.md
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
allowed_changes:
  - Criar golden sets internos por dominio.
  - Medir recall, precision, groundedness, context ROI e regressao.
forbidden_changes:
  - Declarar superioridade externa sem benchmark aprovado.
  - Rodar rivals externos sem gate humano.
depends_on:
  - atlas-retrieval-feedback-loop
  - atlas-context-ranking-system
flows_to:
  - atlas-retrieval-cost-latency-governor
  - atlas-context-observability-plane
unlocks: [retrieval_quality_eval, golden_set_regression]
governs: [retrieval_eval, context_quality_claims]
evidence:
  - docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:retrieval:arena run --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Criar golden sets internos por dominio e command de avaliacao local.
---

# Atlas Retrieval Evaluation & Benchmark Arena

## Resumo

AREBA e a arena de avaliacao de AUCRI. Ela impede que embeddings, rerankers,
retrieval plans, graph traversal ou ingestion sejam promovidos por intuicao.
Toda mudanca relevante precisa passar por golden sets, replay e metricas.

## Papel no Atlas

AREBA responde se o Atlas esta recuperando o contexto certo. Ela mede qualidade
antes de custo, beleza ou velocidade. Sem ela, AUCRI pode parecer melhor e
entregar contexto errado.

## Onde Se Encaixa

Fica depois de AHRI, AARF, ACRS e ARFL, e antes de ARCLG. O ranking gera
candidatos, o feedback mostra uso real, e AREBA mede se a combinacao melhorou.

## Contratos

- `atlas.retrieval.eval_case.v1`
- `atlas.retrieval.eval_run.v1`
- `atlas.retrieval.golden_set.v1`
- `atlas.retrieval.regression_report.v1`
- `atlas.retrieval.context_roi_score.v1`

Campos minimos: `query`, `domain`, `required_refs`, `forbidden_refs`,
`retrieved_refs`, `used_refs`, `missed_refs`, `noise_refs`, `groundedness`,
`latency_ms`, `cost_units`, `outcome_ref`, `run_hash`.

## Fluxo

1. Selecionar golden set por dominio e risco.
2. Rodar retrieval com configuracao atual.
3. Comparar fontes recuperadas contra fontes obrigatorias.
4. Medir ruido, fonte ausente, groundedness e ROI.
5. Detectar regressao contra baseline anterior.
6. Emitir report e bloquear promocao quando piorar criterio critico.

## Regras para IA

- Nao declarar melhoria por exemplo unico.
- Nao aceitar contexto maior como melhoria automatica.
- Nao esconder misses em media agregada.
- Separar avaliacao local de claim contra Claude, Codex ou Gemini.

## Escopo de Implementacao

Implementar service de avaliacao, storage de golden sets, runner local,
regression gate, command `atlas:retrieval:arena run --json` e tests com
fixtures deterministicas.

## Dependencias

Depende de ARFL para saber fontes usadas de fato e de ACRS para comparar
estrategias de ranking.

## Evidencias

Evidencia minima: golden set versionado, run hash, report JSON, diff contra
baseline e teste que bloqueia promocao quando required ref fica ausente.

## Riscos

- Golden set pequeno demais vira teatro.
- Benchmark externo prematuro cria claim falso.
- Otimizar so recall pode aumentar ruido.
- Otimizar so latencia pode remover fonte critica.

## Exemplos

Caso: pergunta de debugging exige `service`, `migration`, `test` e `doc`.
AREBA deve falhar se recuperar so `service` e ignorar o teste quebrado.

## Proximas Acoes

1. Criar inventario de casos reais por Programming, Forge, Research e Finance.
2. Definir score minimo por risco.
3. Implementar runner local sem provider externo.
4. Integrar resultado no Control Plane.
