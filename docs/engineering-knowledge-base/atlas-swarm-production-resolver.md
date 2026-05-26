---
id: atlas-swarm-production-resolver
type: engineering_knowledge
title: Atlas Swarm Production Resolver
status: building
implementation_state: opt_in_runtime_with_tests_flag_default_off
blocker: production resolver is opt-in; external superiority/rivals claims remain blocked
category: atlas-decide
priority: 90
summary: Contrato canonico do resolver real opt-in para Atlas Swarm Executor, com AiProviderManager, circuit breaker, outcome mapping e flag default OFF.
tags:
  - atlas-ai
  - atlas-decide
  - swarm
  - provider-runtime
capabilities:
  - swarm_production_resolver
  - provider_outcome_mapping
  - circuit_breaker
decisions:
  - Resolver real e opt-in via config; default OFF preserva stubs/testes.
  - Falhas de provider viram outcome deterministico; excecoes nao vazam para executor.
  - Claim externo, rivals e superioridade publica continuam bloqueados.
maintenance:
  - Atualizar quando resolver, AppServiceProvider wiring, config flags ou outcome mapping mudarem.
  - Rodar docs-health e testes Swarm resolver/executor apos alterar.
related_paths:
  - app/Services/Ai/AtlasDecide/AtlasSwarmProductionResolverService.php
  - app/Services/Ai/AtlasDecide/AtlasSwarmExecutorService.php
  - app/Providers/AppServiceProvider.php
  - tests/Unit/Ai/AtlasDecide/AtlasSwarmProductionResolverServiceTest.php
  - tests/Unit/Ai/AtlasDecide/AtlasSwarmExecutorServiceTest.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-swarm-production-resolver
graph_title: Atlas Swarm Production Resolver
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-swarm-executor
graph_status: building
graph_source: repo
human_name: Atlas Swarm Production Resolver
canonical_name: Atlas Swarm Production Resolver
technical_name: AtlasSwarmProductionResolverService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-swarm-production-resolver.md

owner: atlas-decide
repo_paths:
  - docs/engineering-knowledge-base/atlas-swarm-production-resolver.md
allowed_changes:
  - Atualizar contrato quando resolver real, flags, provider mapping ou tests mudarem.
forbidden_changes:
  - Habilitar resolver real por default sem approval/operator config.
  - Declarar benchmark, rivals ou superioridade externa a partir deste resolver.
depends_on:
  - atlas-swarm-executor
flows_to:
  - atlas-decide
unlocks:
  - swarm-provider-resolver-opt-in
governs:
  - swarm_provider_resolution
evidence:
  - app/Services/Ai/AtlasDecide/AtlasSwarmProductionResolverService.php
  - tests/Unit/Ai/AtlasDecide/AtlasSwarmProductionResolverServiceTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Unit/Ai/AtlasDecide/AtlasSwarmProductionResolverServiceTest.php tests/Unit/Ai/AtlasDecide/AtlasSwarmExecutorServiceTest.php"
requires_evidence: true
risk_level: high
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Unit/Ai/AtlasDecide/AtlasSwarmProductionResolverServiceTest.php tests/Unit/Ai/AtlasDecide/AtlasSwarmExecutorServiceTest.php"
failure_modes:
  - Resolver real habilitado sem operator config.
  - Provider exception derruba executor em vez de virar outcome.
  - Circuit breaker global mistura providers.
observability_signals:
  - circuitState()
  - result=success|failure|timeout
next_actions:
  - Manter flag default OFF e testar qualquer mudanca no provider mapping.
---
# Atlas Swarm Production Resolver — Patamar 4 F2 (canon)

> **Status:** `building`
> **Group:** patamar4 / atlas_decide
> **ACOS subsystem:** consumed by `ASWE — Atlas Swarm Executor`
> **Authority:** docs canônicos governam implementação.
> **Claim policy:** provider-safe. Sem benchmark, rivals ou superioridade externa; providers sao engines governadas.

## 1. Razão de existir

`AtlasSwarmExecutorService` aceita `setResolver(Closure $resolver)` para invocar cada arm de uma dispatch envelope. Em test/stub o resolver é uma closure fake. Em produção precisa ser uma chamada real ao `AiProviderManager::get($provider)->run($job, $prompt)` com circuit breaker, mapeamento canônico de outcome e segurança.

**F2 entrega esse resolver real, opt-in via flag.**

## 2. Arquitetura

- **`AtlasSwarmProductionResolverService`** em `app/Services/Ai/AtlasDecide/`.
- Construtor: `AiProviderManager`, threshold de circuit (default 3), cooldown segundos (default 60).
- API:
  - `asClosure(): Closure` — closure compatível com `AtlasSwarmExecutorService::setResolver`.
  - `resolve(arm, ctx): array` — chamada single-arm.
  - `circuitState()`, `resetCircuit($provider?)`.
- Saída canônica idêntica ao contrato Swarm Executor:
  ```
  ['result' => success|failure|timeout, 'latency_ms' => int,
   'quality_score' => ?float, 'output' => string]
  ```
- Construção de `AiJob` ephemeral em memória (sem persistir) — apenas para invocar `provider->run($job, $prompt)`.

## 3. Circuit breaker canon

- Counter rolling por `provider_key`.
- Threshold default `3` falhas consecutivas → opened.
- Cooldown default `60s` → half-open (1 probe).
- Sucesso reseta state.
- `output='circuit_open'` quando short-circuit.

Configurável:
- `config('atlas.patamar4.swarm_circuit_threshold')`
- `config('atlas.patamar4.swarm_circuit_cooldown_seconds')`

## 4. Flag opt-in

```
config('atlas.patamar4.swarm_production_resolver_enabled') // default false
```

Quando `true`, `AppServiceProvider` registra resolving callback no `AtlasSwarmExecutorService` que injeta o resolver real. Quando `false`, executor mantém comportamento stub (operador-driven `setResolver`).

## 5. Mapeamento `AiProviderResult` → outcome canon

| AiProviderResult | Outcome Swarm |
|---|---|
| `ok=true` | `result=success`, `output=AiProviderResult.output`, `quality_score = metadata.quality_score?` |
| `ok=false, errorCode contém "timeout"` | `result=timeout`, `output=errorCode` |
| `ok=false` | `result=failure`, `output=errorCode` |
| Exception | `result=failure`, `output='provider_run_error: …'` |
| `circuit_open` | `result=failure`, `output='circuit_open'` |

`latency_ms` vem de `AiProviderResult::durationMs` (ou medido localmente se ausente).

## 6. Invariants

1. Resolver NUNCA propaga exceção pro executor — todo throw vira `failure` determinístico.
2. Flag default OFF.
3. Ephemeral AiJob nunca persistido.
4. Circuit breaker é per-provider, in-memory, vida-do-processo.
5. `claim_policy` provider-safe — nenhum claim externo, nenhum benchmark/rivals.
6. `setResolver(null)` pode ser chamado por testes para limpar.

## 7. Filtro 5 perguntas

1. **Wrapper composto?** Sim — Atlas captura cada provider via swarm + circuit breaker; engine externa nunca destrava sem governance.
2. **Antifrágil?** Sim — falhas viram sinais (circuit_open, outcome.timeout) que retroalimentam Live Outcome Feedback ledger.
3. **Linguagem natural?** Sim — operador fala, Atlas dispatch K arms, escolhe melhor outcome.
4. **Destrava função?** Sim — sem resolver real, swarm é simulação.
5. **Local-first?** Provider-aware. Privacy-class continua governada por Kernel/Admission antes de chegar aqui.

## 8. Testes canon

- `tests/Unit/Ai/AtlasDecide/AtlasSwarmProductionResolverServiceTest.php` — sucesso, falha, timeout, circuit open/cooldown, ephemeral job shape, claim_policy provider-safe.
- `tests/Unit/Ai/AtlasDecide/AtlasSwarmExecutorServiceTest.php` — back-compat stub resolver continua funcionando.

## 9. Cross-references

- `AtlasSwarmExecutorService` (consumer).
- `AiProviderManager`, `AiProvider`, `AiProviderResult` (engine).
- `AtlasDecideLiveOutcomeFeedbackService` (downstream ledger).

## Resumo

Resolver real opt-in para conectar Atlas Swarm Executor ao AiProviderManager sem
transformar teste/stub em comportamento padrao de producao.

## Papel no Atlas

Fornece boundary de provider para Swarm Executor: outcome deterministico,
circuit breaker e claim policy segura.

## Onde Se Encaixa

Fica entre `AtlasSwarmExecutorService`, `AiProviderManager` e AppServiceProvider
wiring por flag local.

## Contratos

Entrada: arm + contexto. Saida: `result`, `latency_ms`, `quality_score` e
`output`, sempre sem propagar exception.

## Fluxo

Flag OFF mantem stub/operator resolver. Flag ON injeta closure real que chama o
provider e mapeia sucesso, failure, timeout ou circuit_open.

## Regras para IA

Nao habilitar por default, nao declarar benchmark/rivals/superioridade e nao
contornar Kernel/Admission/privacy para provider calls.

## Escopo de Implementacao

Service resolver, AppServiceProvider wiring, config flags e testes unitarios de
resolver/executor.

## Dependencias

Depende de Atlas Swarm Executor, AiProviderManager, AiProviderResult e config
local do operador.

## Evidencias

Codigo do resolver, testes unitarios, docs-health e flag default OFF.

## Riscos

Provider exception, circuit breaker mal segmentado, resolver real ligado sem
approval e claim externo indevido.

## Exemplos

Timeout de provider deve retornar `result=timeout`; circuito aberto retorna
`result=failure` e `output=circuit_open`.

## Proximas Acoes

Manter resolver opt-in, ampliar telemetria apenas com receipts provider-safe e
preservar testes de fallback stub.
