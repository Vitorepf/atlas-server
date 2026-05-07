# AP-147 — Dynamic Compute Market Shadow Surface

Status: implemented-shadow-contract

## Problema

AP-99 ja mede qualidade, custo e latencia por provider/modelo, mas esse sinal
nao pode virar roteamento paralelo. O Atlas precisa expor a comparacao de
mercado para humanos e IAs sem trocar provider, sem executar tarefa e sem
bypassar Atlas Decide.

## Contrato

`DynamicComputeMarketAdvisor` e o unico calculador do sinal
`atlas.dynamic_compute_market.v1`. Ele consome somente
`ProviderPerformanceProjection`, compara o provider selecionado com o mercado
AP-99 da mesma rota e devolve recomendacao shadow.

As recomendacoes canonicas sao:

- `collect_ap99_evidence`;
- `review_provider_policy_patch`;
- `benchmark_lower_latency_alternative`;
- `configure_provider_cost_rates`;
- `keep_selected_provider`.

Toda resposta deve declarar:

- `mode=shadow_advisory` no advisor;
- `authority=advisory_only_atlas_decide_remains_authority` no advisor;
- `routing_control.changes_provider=false`;
- `routing_control.routing_authority=atlas_decide`;
- `provider_change_requires` contendo `policy_patch` e `decision_receipt`.

## Surfaces

`DynamicComputeMarketReportService` expõe o advisor como report read-only com
schema `atlas.dynamic_compute_market_report.v1`, `mode=report_only` e
`authority=read_only_no_routing_change`.

Surfaces implementadas:

- CLI: `atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json`;
- API: `/ai/dynamic-compute-market?provider=<provider>`.
- MCP read-only: `atlas_dynamic_compute_market_report`.

A API exige token Atlas e retorna `503` com `status=ledger_unavailable` quando
AP-99/Evidence Ledger nao esta disponivel. CLI e MCP preservam o mesmo
`status=ledger_unavailable`, `ok=false` e `routing_control.changes_provider=false`.
Falta de provider e erro de input, nao decisao de mercado.

## Curator

`self_improvement.provider_performance_review` tambem consome o advisor em
modo proposal-only. Quando AP-99 mostra provider selecionado com amostra real e
um candidato melhor por latencia ou custo, o Curator emite finding
`atlas.self_improvement.dynamic_compute_market.v1` com acao primaria
`run_provider_benchmark`. Esse finding nunca troca rota: ele preserva
`routing_control.changes_provider=false` e exige `policy_patch` +
`decision_receipt` antes de qualquer mudanca futura em Atlas Decide.

## Nao Escopo

O Dynamic Compute Market nao escolhe provider sozinho, nao escreve policy, nao
altera `DecisionReceipt`, nao consulta preco externo e nao aplica proposta
automaticamente. O Curator pode abrir proposta revisavel, mas ela fica presa ao
Inbox/review humano e aos gates de policy/receipt.

## Testes

- `AtlasDecideReceiptIntegrationTest::test_dynamic_compute_market_uses_ap99_provider_performance_inside_decision_receipt`
- `AtlasDecideReceiptIntegrationTest::test_dynamic_compute_market_requests_cost_rates_when_quality_is_ok_but_cost_is_unknown`
- `AtlasDecideReceiptIntegrationTest::test_dynamic_compute_market_recommends_benchmark_when_better_alternative_has_insufficient_sample`
- `AtlasDecideReceiptIntegrationTest::test_dynamic_compute_market_keeps_selected_provider_when_evidence_is_stable`
- `AtlasAiDynamicComputeMarketCommandTest`
- `AtlasAiDynamicComputeMarketApiTest`
- `AtlasOpenBrainMcpServiceTest::test_dynamic_compute_market_report_exposes_read_only_shadow_advice`
- `AtlasOpenBrainMcpServiceTest::test_dynamic_compute_market_report_preserves_review_signal_when_ledger_is_unavailable`
- `AtlasSelfImprovementRuntimeTest::test_provider_performance_review_emits_dynamic_compute_market_benchmark_proposal`
- `AtlasSelfImprovementRuntimeTest::test_self_improvement_command_surfaces_dynamic_compute_market_proposal_without_routing_change`
- `KernelArchitectureStaticScanner::scanProviderUsagePerformanceContract`
