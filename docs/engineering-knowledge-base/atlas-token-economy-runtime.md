---
id: atlas-token-economy-runtime
type: engineering_knowledge
title: Atlas Token Economy Runtime
status: active
implementation_state: partial_runtime_with_feedback_guided_context_delivery
blocker: Provider selection ainda e advisory ate promocao governada; runtime ATER read-only ja emite budgets, compression/reuse/local receipts, context delivery policy e quality gate.
category: intelligence-runtime
priority: 98
summary: Doc filha AUCRI para reduzir tokens de entrada e saida sem reduzir evidencia, must-keep, sufficiency, qualidade ou completude.
tags: [atlas-ai, aucri, ater, token-economy, compression, cost]
capabilities: [token_budgeting, semantic_compression, context_reuse, local_pre_reasoning, quality_token_check]
decisions:
  - Economia de token so e valida se must_keep_coverage permanece 1.0.
  - ATER otimiza custo/token depois do ACCR, sem substituir retrieval ou compiler.
  - Saida curta deve ser contrato por necessidade, nao perda de completude.
maintenance:
  - Atualizar antes de mudar budgets, compression, provider selection ou output contracts.
related_paths:
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
  - docs/engineering-knowledge-base/atlas-current-provider-stack-v1.md
  - docs/engineering-knowledge-base/atlas-minimax-first-24h-flow-v1.md
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-context-compiler-runtime.md
  - docs/engineering-knowledge-base/atlas-retrieval-cost-latency-governor.md
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Context/AtlasTokenEconomyRuntimeService.php
  - app/Services/Ai/Context/LocalPrereasoningPolicy.php
  - app/Console/Commands/AtlasTokenEconomyRuntimeCommand.php
  - tests/Feature/Ai/Context/TokenEconomyRuntimeTest.php
  - tests/Unit/Ai/AtlasTokenEconomyRuntimeServiceTest.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Token Economy Runtime
runtime_acronym: ATER
internal_product_name: Atlas Token Governor
technical_runtime: AtlasTokenEconomyRuntimeService
graph_id: atlas-token-economy-runtime
graph_title: Atlas Token Economy Runtime
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: active
graph_source: repo
human_name: Atlas Token Economy Runtime
canonical_name: Atlas Token Economy Runtime
technical_name: AtlasTokenEconomyRuntimeService
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-token-economy-runtime.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-token-economy-runtime.md
allowed_changes:
  - Definir budgets, compression, reuse, local pre-reasoning e quality gates.
forbidden_changes:
  - Reduzir token removendo decisions, blockers, DoD, constraints ou evidence refs.
  - Escolher provider barato quando risk/sufficiency exige modelo superior.
  - Declarar economia sem receipt antes/depois.
depends_on: [atlas-context-compiler-runtime, atlas-retrieval-cost-latency-governor]
flows_to: [atlas-quality-preserving-efficiency-system, atlas-ai-product-certification, atlas-context-observability-plane]
unlocks: [safe_token_reduction, provider_cost_optimization, output_contract_compression]
governs: [token_budget, semantic_compression, provider_token_cost, output_length_contract]
evidence:
  - docs/engineering-knowledge-base/atlas-token-economy-runtime.md
evidence_refs:
  - symbol: AtlasTokenEconomyRuntimeService
  - symbol: LocalPrereasoningPolicy
  - symbol: AtlasTokenEconomyBudgetPolicyService
  - command: atlas:context:token-economy
  - test: AtlasTokenEconomyRuntimeServiceTest
  - test: AtlasTokenEconomyBudgetPolicyServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context:token-economy --json"
  - "php artisan atlas:context:token-economy --input='{\"provider\":\"gpt\",\"risk_level\":\"low\"}' --json"
  - "php artisan test tests/Feature/Ai/Context/TokenEconomyRuntimeTest.php"
  - "php artisan test tests/Unit/Ai/AtlasTokenEconomyRuntimeServiceTest.php"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Implementar token budget governor e quality token check antes de compression agressiva.
---

# Atlas Token Economy Runtime

## Resumo

ATER e o bloco 17 da AUCRI. Ele reduz tokens de entrada e saida mantendo
evidencia, must-keep, sufficiency e qualidade. O objetivo nao e responder pouco;
e gastar menos token para produzir o mesmo resultado ou melhor.

## Papel no Atlas

ACCR compila o melhor contexto final. ATER governa se esse contexto pode ser
menor, se parte pode ser reutilizada, se analise local evita chamada ao LLM, se
um provider mais barato e seguro, e se a resposta deve ser curta ou completa.

## Onde Se Encaixa

```text
AUCRI -> ACMF -> ACCR -> ATER -> provider/output
```

ATER tambem retroalimenta ACOP e AREBA com metricas de economia e qualidade.

## Contratos

- `atlas.token_economy.budget.v1`
- `atlas.token_economy.compression_receipt.v1`
- `atlas.token_economy.reuse_receipt.v1`
- `atlas.token_economy.local_prereasoning.v1`
- `atlas.token_economy.quality_check.v1`
- `atlas.token_economy.context_delivery_policy.v1`

Campos minimos: `flow_id`, `risk_level`, `provider`, `input_tokens_before`,
`input_tokens_after`, `output_budget`, `must_keep_coverage`, `loss_score`,
`quality_gate_status`, `savings_estimate`, `initial_context_token_budget`,
`expansion_token_reserve`, `receipt_hash`.

## Fluxo

1. Classificar risco, flow e objetivo.
2. Definir token budget de entrada e saida.
3. Reusar context pack/compiled pack se hash e freshness permitirem.
4. Executar pre-reasoning local para diff, contagem, parsing e validacao.
5. Aplicar semantic compression com must_keep_coverage = 1.0.
6. Se ACRS enviou `feedback_impact_report`, gerar politica de entrega:
   pacote inicial minimo, fontes iniciais, fontes diferidas e gatilhos de
   expansao sob demanda.
7. Escolher provider/modelo pelo menor custo seguro.
8. Definir output contract curto ou completo.
9. Rodar quality token check e bloquear se houver perda critica.

## Regras para IA

- Nao economizar token em tarefa high-risk sem quality gate.
- Nao comprimir must-keep.
- Nao pedir relatorio longo quando resposta curta resolve.
- Nao chamar provider frontier para triagem local simples.
- Nao reusar contexto stale.
- Nao despejar testes, docs longos ou grafo completo no primeiro pacote quando
  `context_delivery_policy` consegue expor handles de expansao.
- Se feedback perder fonte obrigatoria, tratar como recheck guardado antes de
  implementacao, nao como permissao para sumir com evidencia.

## Escopo de Implementacao

Sub-blocos cobertos pelo runtime read-only:

- ATOG: Atlas Token Budget Governor.
- ASCR: Atlas Semantic Compression Runtime.
- ACRR: Atlas Context Reuse Runtime.
- ALPR: Atlas Local Pre-Reasoning Runtime.
- AOPC: Atlas Output Contract Compressor.
- ATDR: Atlas Token Delta Runtime.
- APMS: Atlas Provider Model Selector.
- AQTC: Atlas Quality Token Check.
- ACDP: Atlas Context Delivery Policy, que transforma impacto before/after do
  ACRS em budget inicial, reserva de expansao, fontes iniciais/diferidas e
  triggers provider-safe.

### Budget Policy (ATOG Phase 1)

`AtlasTokenEconomyBudgetPolicyService` (`app/Services/Ai/Tokens/`) e a
implementacao Phase 1 do token budget por flow/risk (schemas
`atlas.token_economy.{budget|consumption|reuse_credit|variant}.v1`):

- `budget(flow, risk)` retorna limites canonicos de input/output tokens e
  cost units por flow (`atlas_dev`, `atlas_research`, `atlas_forge`, `default`)
  x risk (`low|medium|high|irreversible`); flow/risk desconhecidos degradam
  para `default`/`low` em vez de falhar.
- `evaluateVariants()` avalia variantes de contexto (compactado vs expandido)
  contra o budget; variante com `must_keep_coverage < 1.0` NUNCA e elegivel
  (invariante imune: must_keep nao se remove para caber em budget); selecao
  entre elegiveis e por maior quality_score, depois menor custo; se nada cabe,
  retorna `blocked_reason=no_variant_fits_budget_with_must_keep_intact`.
- `reuseCredit()` credita reuso por hash de contexto (cache hit = tokens salvos).
- `estimateCost()` e deterministico: `(input + output*4) / 100k` cost units.
- In-memory nesta fase; persistencia em `atlas_token_economy_receipts` e wiring
  com ACPFR/ARCLG ficam para phase 2.

`LocalPrereasoningPolicy` e o unico dono das regras de ALPR. O runtime ATER usa
essa politica para montar o receipt `atlas.token_economy.local_prereasoning.v1`.
`LocalPrereasoningEligibilityClassifier` e seu teste unitario foram retirados em
2026-06-09 (commit 0c39c8cbd9); o identificador do schema passou a viver na
constante `LOCAL_PREREASONING_SCHEMA` do `AtlasTokenEconomyRuntimeService`, com
cobertura viva em `tests/Unit/Ai/AtlasTokenEconomyRuntimeServiceTest.php` e
`tests/Feature/Ai/Context/TokenEconomyRuntimeTest.php`.

## Dependencias

Depende de ACCR para compiled pack, ARCLG para custo/latencia, ACMF para delta e
reuse quente, ACFQ para sufficiency, ARPTL para privacy e AREBA para avaliar
regressao de qualidade.

## Evidencias

Evidencia atual:

- `atlas:context:token-economy --json`;
- `atlas:context:token-economy --input='<json>' --json`;
- `atlas:context:token-economy:input --input='<json>' --json` como alias de
  compatibilidade, sem competir pelo comando canonico;
- receipt com tokens antes/depois;
- `context_delivery_policy` com `initial_context_token_budget` e
  `expansion_token_reserve` quando recebe `feedback_impact_report` do ACRS;
- must_keep_coverage = 1.0;
- loss_score abaixo do limite;
- teste que bloqueia compressao que remove DoD/blocker/decision;
- teste que escolhe provider barato somente quando risk permite.
- teste que prova staging minimo com feedback ativo e recheck guardado quando
  uma fonte obrigatoria e perdida.

## Riscos

- Economia falsa que aumenta retrabalho.
- Resposta curta demais esconder lacuna.
- Provider barato errar tarefa critica.
- Compression remover nuance.
- Reuse de contexto stale.

## Exemplos

Pergunta "o que falta?" deve usar output contract curto e contexto delta. Obra
Forge high-risk pode economizar tokens em boilerplate, mas nao em SDD, blockers,
risks, decisions e evidence refs.

## Proximas Acoes

1. Enforcar ATER antes de provider calls.
2. Expor metricas em ACOP.
3. Integrar primeiro com Atlas Dev e Forge.
4. Promover provider selection somente com AP/receipt.
5. Manter fixtures de must-keep e provider risk verdes.
6. Fazer Open Brain/ACCR consumir `context_delivery_policy` para entregar
   handles de expansao sob demanda fora do runtime read-only.
