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
- [x] Existe teste cobrindo agregacao minima por `provider_cli + domain + task_type`.
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
expoe evidencia empirica de provider por CLI, MCP, Strategy Matrix e
Self-Improvement. Ele ainda nao muda automaticamente a estrategia default com
base em performance empirica; isso continua bloqueado ate haver amostra
suficiente, benchmark e proposta revisavel.
