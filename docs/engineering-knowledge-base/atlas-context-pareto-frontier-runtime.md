---
id: atlas-context-pareto-frontier-runtime
type: engineering_knowledge
title: Atlas Context Pareto Frontier Runtime
status: building
implementation_state: building_read_only_shadow_report_and_real_trace_metric_shadow_available
blocker: ACPFR possui report read-only inicial e sombra sobre ai_trace_metric_summaries; ainda falta receipts persistidos, safe exploration runtime e promocao governada.
category: intelligence-runtime
priority: 98
summary: Runtime AUCRI para escolher o melhor tradeoff entre qualidade, tokens, custo, latencia e risco, usando fronteira de Pareto e exploracao segura em shadow mode.
tags: [atlas-ai, aucri, acpfr, pareto, optimization, token-quality]
capabilities: [pareto_frontier, multi_objective_optimization, safe_exploration, marginal_utility_per_token]
decisions:
  - ACPFR nao comprime contexto; ele escolhe a melhor variante entre qualidade/token/custo/latencia.
  - Must-keep, privacy e sufficiency sao constraints duras, nao objetivos negociaveis.
  - Exploracao de novas estrategias deve rodar em shadow antes de promover.
maintenance:
  - Atualizar antes de mudar utility function, frontier policy, exploration ou promotion gate.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-token-economy-runtime.md
  - docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md
  - docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Context Pareto Frontier Runtime
runtime_acronym: ACPFR
internal_product_name: Atlas Quality-Cost Frontier
technical_runtime: AtlasContextParetoFrontierRuntimeService
graph_id: atlas-context-pareto-frontier-runtime
graph_title: Atlas Context Pareto Frontier Runtime
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md
allowed_changes:
  - Definir utility functions, frontier receipts, shadow exploration e promotion gates.
forbidden_changes:
  - Promover variante que piora caso high-risk.
  - Tratar must-keep, privacy ou sufficiency como tradeoff.
  - Rodar exploracao ativa sem shadow receipt.
depends_on: [atlas-token-economy-runtime, atlas-retrieval-evaluation-benchmark-arena]
flows_to: [atlas-context-observability-plane, atlas-aucri-continuous-optimization-protocol]
unlocks: [quality_token_pareto_frontier, safe_context_strategy_selection]
governs: [context_strategy_selection, provider_strategy_tradeoff, token_quality_frontier]
evidence:
  - docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md
evidence_refs:
  - symbol: AtlasContextParetoFrontierRuntimeService
  - command: atlas:context:pareto-frontier
  - test: AtlasContextParetoFrontierRuntimeServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:aucri:optimize-audit --json"
  - "php artisan atlas:context:pareto-frontier --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Persistir frontier receipts depois de validar shadow em traces reais.
---

# Atlas Context Pareto Frontier Runtime

## Resumo

ACPFR e o bloco 18 da AUCRI. Ele escolhe a melhor fronteira entre qualidade,
tokens, custo, latencia e risco. ATER reduz tokens; ACPFR decide qual variante
vale a pena promover sem criar regressao escondida.

## Papel no Atlas

O papel e evitar otimizacao local. Uma estrategia pode reduzir token e piorar
latencia, ou reduzir custo e perder qualidade. ACPFR calcula a fronteira de
Pareto e seleciona o melhor ponto seguro por flow, provider e risco.

## Onde Se Encaixa

```text
ACOPRO/AREBA geram metricas
ATER/ACCR/ACMF geram variantes
ACPFR escolhe frontier segura
ACOP observa promocao e regressao
```

## Contratos

- `atlas.context.pareto_candidate.v1`
- `atlas.context.pareto_frontier.v1`
- `atlas.context.utility_function.v1`
- `atlas.context.safe_exploration_receipt.v1`
- `atlas.context.frontier_promotion_decision.v1`

Campos minimos: `candidate_id`, `flow_id`, `risk_level`, `quality_score`,
`input_tokens`, `output_tokens`, `cost_units`, `latency_ms`,
`must_keep_coverage`, `privacy_status`, `pareto_dominated`, `promotion_status`.

## Fluxo

1. Receber variantes de ACCR/ATER/ACMF.
2. Filtrar qualquer variante que falha must-keep, privacy ou sufficiency.
3. Calcular qualidade, token, custo, latencia e risco.
4. Marcar candidatos dominados.
5. Selecionar frontier por utility function do flow.
6. Rodar exploracao nova em shadow mode.
7. Promover somente com receipt e rollback.

## Regras para IA

- Nao promover candidato Pareto-dominado.
- Nao trocar qualidade por token em high-risk.
- Nao rodar bandit ativo sem shadow e human policy.
- Nao usar media global para esconder regressao de dominio.
- Nao chamar benchmark externo.

## Escopo de Implementacao

Tecnicas:

- Pareto frontier search.
- Marginal utility per token.
- Constrained utility function.
- Shadow multi-armed bandit.
- Thompson sampling somente em shadow.
- Lagrangian token budget optimizer.
- Per-domain frontier registry.
- Promotion/rollback receipt.

Runtime atual:

- `AtlasContextParetoFrontierRuntimeService` emite frontier canary shadow.
- `atlas:context:pareto-frontier --json` aceita `--hours` e le
  `ai_trace_metric_summaries` quando disponivel.
- A secao `real_trace_shadow` estima variantes sobre traces reais sem chamar
  provider, sem benchmark, sem escrita e sem promocao.
- Traces com baixa qualidade, remediation ou baixa confianca sao bloqueados;
  high-risk nao troca must-keep por economia.

## Dependencias

Depende de ATER para variantes de token, AREBA para metricas, ACOPRO para
experimentos, ACOP para observabilidade e ARPTL para constraints de trust.

## Evidencias

Evidencia minima:

- frontier report com candidatos dominados;
- secao `real_trace_shadow` quando metric summaries existem;
- utility function versionada;
- shadow receipt antes de promocao;
- rollback ref;
- teste que bloqueia variante com `must_keep_coverage < 1.0`;
- teste que bloqueia candidato Pareto-dominado.

## Riscos

- Overfitting para canary set pequeno.
- Utility function errada favorecer custo demais.
- Exploracao ativa degradar usuario real.
- Media agregada esconder falha high-risk.

## Exemplos

Se variante A usa 8k tokens, qualidade 0.92 e custo 1.0, e variante B usa 9k
tokens, qualidade 0.90 e custo 1.2, B e dominada e nao deve ser promovida.

Se variante C usa 5k tokens, qualidade 0.89 e custo 0.6, ela pode ser boa para
pergunta simples, mas bloqueada para Finance ou Forge high-risk.

## Proximas Acoes

1. Rodar `php artisan atlas:context:pareto-frontier --json`.
2. Rodar `php artisan atlas:context:pareto-frontier --hours=168 --json`.
3. Persistir frontier receipts.
4. Integrar frontier status ao ACOP.
5. Criar safe exploration policy antes de qualquer promocao.
