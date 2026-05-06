# AP-99 — CLI Provider Usage / Performance Contract

Status: implemented-operational-read-model

## Objetivo

Formalizar um contrato unico para medir uso e performance dos providers CLI do Atlas sem reimplementar a arquitetura-mae.

O Atlas ja possui `AtlasEvidenceLedger`, `atlas_ledger_events`, `ProviderCalled`, `ProviderReturned`, `ProviderFallback`, `ai_router_decisions`, `AtlasCliProviderStrategyService`, `DecisionReceipt` e telemetry rollups. Este AP conecta essas pecas para que o Atlas aprenda empiricamente qual provider funciona melhor por dominio, tarefa, risco, latencia, gates e outcome.

## Nao Objetivo

Nao implementar:

- novo Evidence Ledger;
- novo Decision Receipt;
- nova arquitetura paralela de provider router;
- API direta de providers;
- modelo local;
- Text-to-SQL;
- Graph RAG;
- Background Fanout;
- mudanca automatica da estrategia default sem dados suficientes.

## Fluxo

```text
Atlas Decide
-> Provider Strategy Matrix
-> Provider Driver / CLI
-> ProviderCalled / ProviderReturned / ProviderFallback
-> Evidence Ledger
-> Provider Performance Projection
-> CLI / MCP provider performance reports
-> Self-Improvement provider_performance_review
```

## Contrato de Payload

Todo evento de provider CLI deve conseguir projetar o seguinte shape:

```text
CLI Provider Usage Event
- provider_cli
- model_name_if_available
- domain
- task_type
- flow
- risk
- trace_id
- envelope_id
- receipt_id
- started_at
- finished_at
- latency_seconds
- attempts
- input_context_size_estimate
- output_size_estimate
- exit_status
- quality_gate_result
- user_acceptance
- repair_count
- failure_reason
- router_decision_ref
- ledger_event_ref
```

## Agregados Minimos

```text
provider_cli + domain + task_type:
- total_runs
- success_rate
- average_latency_seconds
- p50_latency_seconds
- p95_latency_seconds
- repair_rate
- failure_rate
- user_acceptance_rate
- quality_gate_pass_rate
- last_seen_at
```

## Critérios De Aceite

- [x] `ProviderCalled`, `ProviderReturned` e `ProviderFallback` carregam campos suficientes para projetar `CLI Provider Usage Event`.
- [x] `ai_router_decisions` pode ser correlacionado com ledger por `trace_id`, `envelope_id` ou `receipt_id` quando disponivel.
- [x] Existe teste cobrindo a normalizacao do payload no hot path do Worker.
- [x] Existe teste cobrindo agregacao minima por `provider_cli + domain + specialist_profile + task_type`.
- [x] Provider usage captura `specialist_profile` e AP-99 filtra/agrega essa dimensao em CLI, API e MCP.
- [x] O resultado alimenta `AtlasCliProviderStrategyService` como `empirical_performance`.
- [x] O Self-Improvement recebe `provider_performance_review` como insumo em modo report/proposal.
- [x] Existe comando read-only `atlas:ai:provider-performance` para auditar performance recente por janela e filtros canonicos.
- [x] Existe API read-only `GET /ai/provider-performance` com auth Atlas token, filtros canonicos e paridade de indisponibilidade.
- [x] Existe ferramenta MCP read-only `atlas_provider_performance_report` para expor a mesma projecao ao Open Brain sem criar fluxo paralelo.
- [x] Observability expoe `provider_performance` no mesmo payload operacional que SLO, Repair, Kernel Pipeline e Schedule Replay.
- [x] Reports indisponiveis preservam `review_signal` canonico com `wait_for_provider_usage_evidence`.
- [x] `atlas:ai:architecture-validate` expoe `ap99_provider_usage_performance_contract`.

## Arquivos Provaveis

```text
app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
app/Services/Ai/Kernel/Evidence/LedgerEventType.php
app/Services/Ai/Kernel/Evidence/ProviderUsagePayload.php
app/Services/Ai/Kernel/Evidence/ProviderPerformanceProjection.php
app/Console/Commands/AtlasAiProviderPerformanceCommand.php
app/Http/Controllers/AtlasAiProviderPerformanceController.php
app/Http/Controllers/AiObservabilityController.php
app/Services/Ai/AtlasOpenBrainMcpService.php
app/Services/Ai/Cli/AtlasCliProviderStrategyService.php
app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
app/Services/Ai/Telemetry/*
app/Services/Ai/AiWorker.php
app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php
database/migrations/2026_04_30_123000_create_ai_router_decisions_table.php
database/migrations/2026_05_05_020000_create_atlas_ledger_events_table.php
tests/Feature/Ai/AiWorkerProviderChoiceTest.php
tests/Feature/Ai/AtlasAiProviderPerformanceCommandTest.php
tests/Feature/Ai/AtlasAiProviderPerformanceApiTest.php
tests/Feature/Ai/AiObservabilityKernelSloTest.php
tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php
tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php
tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php
tests/Unit/Ai/ProviderPerformanceProjectionTest.php
```

## Status Operacional

AP-99 esta implementado como read model operacional. O Atlas mede, projeta e
expoe evidencia empirica de provider por CLI, MCP, API, Strategy Matrix e
Self-Improvement. O rollup inclui `specialist_profile`, permitindo comparar
frontend, arquitetura, QA ou seguranca sem hardcode de provider.

Eventos `PROVIDER_CALLED`, `PROVIDER_RETURNED` e `PROVIDER_FALLBACK` tambem
carregam telemetria normalizada de tokens e custo: `prompt_tokens`,
`completion_tokens`, `total_tokens`, `estimated_tokens`, `token_source`,
`cost_microusd`, `cost_confidence`, `cost_source` e `cost_mode`. Quando nao
existe rate configurado, o custo permanece `null` com
`cost_confidence=unknown`; isso e sinal operacional para completar a tabela de
rates, nao autorizacao para inventar custo.

O Atlas ainda nao muda automaticamente a estrategia default com base em
performance empirica. Qualquer alteracao de provider/modelo continua bloqueada
ate haver amostra suficiente, benchmark e proposta revisavel.

`atlas:ai:provider-performance`, `GET /ai/provider-performance`, Observability
e MCP devem expor esses rollups. `self_improvement.provider_performance_review`
deve abrir finding revisavel quando provider falha/faz fallback e tambem quando
`unknown_cost_count > 0`, pedindo `configure_provider_cost_rates`.

`configure_provider_cost_rates` e uma Inbox action assistida. Sem rates
informados, ela devolve template e marca o item como lido, sem resolver. Com
`input_microusd_per_1k` e `output_microusd_per_1k`, ela grava em
`ai_provider_cost_rates`, atualiza `provider_cost_rate_action`, resolve o item e
emite `INBOX_ACTION_RECORDED`. Essa action nao consulta internet, nao inventa
preco e nao muda provider/modelo; ela apenas transforma revisao humana de custo
em evidencia operacional governada.

O replay do Evidence Ledger tambem deve projetar essa action. O contrato minimo
do `inboxActionReportForWindow` inclui `provider_cost_rate_action_count`,
`provider_cost_rate_applied_count`, contagens por provider/modelo e os campos
recentes `provider_cost_rate_provider`, `provider_cost_rate_model`,
`provider_cost_rate_input_microusd`, `provider_cost_rate_output_microusd` e
`provider_cost_rate_applied`. Quando a action foi apenas preview/template, o
review signal recomenda `configure_provider_cost_rates`; quando aplicou rate
real, publica `provider_cost_rates_configured`. Isso fecha o ciclo:
Curator -> Inbox -> tabela de rates -> Evidence Ledger -> replay/report.
