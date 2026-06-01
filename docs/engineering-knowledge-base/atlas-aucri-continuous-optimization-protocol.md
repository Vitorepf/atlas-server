---
id: atlas-aucri-continuous-optimization-protocol
type: engineering_knowledge
title: Atlas AUCRI Continuous Optimization Protocol
status: active
category: intelligence-runtime
priority: 99
summary: Protocolo transversal para melhorar continuamente os 18 blocos AUCRI com foco em reducao de tokens, preservacao de qualidade, evidencias e falsificacao de economias aparentes.
human_summary: Melhora continuamente como o Atlas escolhe contexto: corta token inutil, mas so aceita economia quando a qualidade e a prova continuam iguais ou melhores.
human_what: Protocolo de melhoria continua para reduzir tokens e ruido sem perder qualidade de contexto.
human_purpose: Evitar economia falsa: menos token so vale se resposta, evidencia, must-keep e risco continuarem corretos.
human_input: Recebe baseline, variante, metricas, canarios, context packs, custos, qualidade e resultados de tarefas.
human_output: Entrega decisao de manter, reverter ou promover uma otimizacao de contexto com prova.
human_change_when: Mexa quando AUCRI ganhar novo bloco, nova tecnica de compressao, novo benchmark ou novo provider profile.
human_block_when: Bloqueie quando a reducao de token piorar qualidade, remover evidencia, quebrar must-keep ou nao tiver rollback.
tags: [atlas-ai, aucri, optimization, token-economy, quality-gate]
capabilities: [continuous_optimization, token_quality_audit, context_ablation, prompt_distillation, roi_scoring]
decisions:
  - Este protocolo governa melhoria continua dos 18 blocos AUCRI, incluindo ACPFR como frontier de tradeoff.
  - Reducao de token so e aceita quando qualidade, evidence, sufficiency e must-keep continuam preservados.
  - Toda otimizacao deve ter baseline, variante, metricas, rollback e receipt.
maintenance:
  - Atualizar sempre que AUCRI ganhar novo bloco, novo provider profile ou novo metodo de compression/retrieval.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-token-economy-runtime.md
  - docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md
  - docs/engineering-knowledge-base/atlas-context-compiler-runtime.md
  - docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md
  - docs/engineering-knowledge-base/atlas-context-observability-plane.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas AUCRI Continuous Optimization Protocol
runtime_acronym: ACOPRO
internal_product_name: Atlas Context Optimization Loop
technical_runtime: AtlasAucriContinuousOptimizationProtocol
graph_id: atlas-aucri-continuous-optimization-protocol
graph_title: Atlas AUCRI Continuous Optimization Protocol
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: active
graph_source: repo
human_name: Atlas AUCRI Continuous Optimization Protocol
canonical_name: Atlas AUCRI Continuous Optimization Protocol
technical_name: AtlasAucriContinuousOptimizationProtocol
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md
allowed_changes:
  - Definir metodos de avaliacao, ablation, compression, ROI e rollback.
forbidden_changes:
  - Promover economia de token sem quality gate.
  - Otimizar um bloco isolado piorando resultado end-to-end.
  - Declarar melhoria sem baseline e receipt.
depends_on: [atlas-unified-context-retrieval-intelligence, atlas-token-economy-runtime]
flows_to: [atlas-retrieval-evaluation-benchmark-arena, atlas-context-observability-plane]
unlocks: [continuous_token_quality_improvement, safe_context_optimization]
governs: [aucri_optimization, token_quality_tradeoffs, context_ablation]
evidence:
  - docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md
evidence_refs:
  - symbol: AtlasAucriContinuousOptimizationProtocolService
  - command: atlas:aaeos:aucri-continuous-optimization-protocol
  - test: AtlasAucriContinuousOptimizationProtocolTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:aucri:optimize-audit --json"
  - "php artisan atlas:aucri:token-quality-canaries --json"
  - "php artisan atlas:context:pareto-frontier --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Criar primeiro audit AUCRI com baseline de token/qualidade por flow.
---
# Atlas AUCRI Continuous Optimization Protocol

## Resumo

ACOPRO governa a melhoria continua dos blocos AUCRI. Ele existe para forcar
analise constante de qualidade, custo, token, RAM, retrieval, compiler e output
sem transformar economia aparente em regressao real.

## Papel no Atlas

O papel e manter AUCRI evoluindo. Cada proposta de melhoria precisa provar:
menos token, igual ou melhor qualidade, mesma cobertura de must-keep, mesmas
evidencias criticas e rollback claro.

## Onde Se Encaixa

```text
18 blocos AUCRI
  -> ACOPRO audit/experimento/receipts
  -> AREBA mede
  -> ATER/ACCR/ACMF aplicam
  -> ACOP observa
```

ACOPRO nao e bloco novo de runtime. E protocolo transversal dos 18 blocos.

## Contratos

- `atlas.aucri.optimization_experiment.v1`
- `atlas.aucri.token_quality_receipt.v1`
- `atlas.aucri.context_ablation_report.v1`
- `atlas.aucri.optimization_rollback.v1`

Campos minimos: `target_block`, `baseline_hash`, `variant_hash`,
`input_tokens_before`, `input_tokens_after`, `output_tokens_before`,
`output_tokens_after`, `quality_score_before`, `quality_score_after`,
`must_keep_coverage`, `evidence_coverage`, `rollback_ref`, `receipt_hash`.

## Fluxo

1. Escolher flow real e bloco alvo.
2. Capturar baseline de token, qualidade, latencia, fonte e outcome.
3. Criar variante de otimizacao.
4. Rodar ablation: remover grupos de contexto e medir dano.
5. Rodar quality gate: must-keep, evidence, sufficiency, freshness e policy.
6. Promover apenas se token cai sem queda de qualidade.
7. Registrar receipt e rollback.

## Regras para IA

- Nao otimizar por media se caso high-risk piorou.
- Nao contar token saving de resposta incompleta.
- Nao aceitar compressao que remove decision, blocker, DoD, constraint ou risk.
- Nao promover variante sem baseline reproduzivel.
- Nao trocar provider por custo se o risk level exige modelo superior.

## Escopo de Implementacao

Tecnicas obrigatorias de melhoria continua:

- Context Ablation Testing: mede quais partes do contexto realmente importam.
- Semantic Redundancy Removal: remove duplicatas sem perder evidencia.
- Prompt Distillation: transforma instrucoes repetidas em contratos compactos.
- Local Tool Substitution: faz diff/parse/count localmente antes do LLM.
- Output Minimality Contract: evita relatorios longos quando resposta curta basta.
- Provider-Aware Packing: adapta formato para Claude, GPT, Gemini e local.
- Segment ROI Scoring: calcula valor por token de cada segmento.
- Failure-Driven Retrieval Tuning: usa erros reais para melhorar ranking.
- Stop Rule for Retrieval: para busca quando sufficiency passa e custo marginal cai.
- Regression Canary Set: casos pequenos que bloqueiam economia falsa.

## Dependencias

Depende de ATER para economia, ACCR para compiled pack, AREBA para metricas,
ACOP para observabilidade, ACFQ para sufficiency e ARPTL para privacy.

## Evidencias

Evidencia minima:

- experimento baseline vs variante;
- token receipt antes/depois;
- must_keep_coverage = 1.0;
- evidence_coverage sem regressao;
- quality_score igual ou maior;
- rollback_ref existente.

## Riscos

- Reducao de token parecer boa mas aumentar retrabalho.
- Otimizar para flow simples e quebrar Forge/Dev long-horizon.
- Ablation remover contexto raro mas critico.
- Provider-aware packing ficar obsoleto com novos modelos.

## Exemplos

Se Atlas Dev envia 40k tokens e resolve com 12k, ACOPRO deve provar que os 28k
removidos eram redundantes ou reconstruiveis, e que tests, blockers, decisions
e evidence refs continuaram presentes.

Se Research gera resposta longa, Output Minimality Contract pode reduzir saida,
mas apenas se perguntas, fontes e incertezas continuarem claras.

## Proximas Acoes

1. Rodar `php artisan atlas:aucri:optimize-audit --json`.
2. Rodar `php artisan atlas:aucri:token-quality-canaries --json`.
3. Rodar `php artisan atlas:context:pareto-frontier --json`.
4. Criar matriz baseline por flow: Dev, Forge, Research, Finance e Strategy.
5. Integrar receipts com ATER e ACOP.
6. Rodar primeiro audit em Atlas Dev/Forge antes de qualquer benchmark externo.
