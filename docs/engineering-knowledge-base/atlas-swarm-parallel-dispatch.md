---
id: atlas-swarm-parallel-dispatch
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Swarm Parallel Dispatch
slug: atlas-swarm-parallel-dispatch
status: building
implementation_state: runtime_available_parallel_dispatch_engine_unwired_by_default
category: atlas-decide
priority: 93
summary: Motor local-first de dispatch paralelo para arms de swarm com fallback serial deterministico, timeout por arm, JSONL append-only e claim policy provider-safe.
tags: [atlas-ai, atlas-decide, swarm, patamar-4, parallel-dispatch]
capabilities: [swarm_parallel_dispatch, serial_fallback, per_arm_timeout, provider_safe_outcome_hashing]
decisions:
  - O dispatcher executa comandos locais injetados por commandBuilder; nao conhece providers.
  - Flag default fica off e preserva fallback serial deterministico.
  - CLI per-arm de producao e integracao provider sao trabalho subsequente, nao claim desta doc.
maintenance:
  - Atualizar quando AppServiceProvider ou CLI per-arm forem conectados.
  - Manter testes cobrindo unwired, serial, parallel, failure, non-json e claim policy.
risk_level: medium
owner: atlas-ai
graph_id: atlas-swarm-parallel-dispatch
graph_title: Atlas Swarm Parallel Dispatch
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-cognition-operating-system
graph_status: building
graph_source: repo
depends_on:
  - atlas-swarm-production-resolver
  - atlas-cognition-operating-system
authority_class: implementation_contract
related_paths:
  - docs/engineering-knowledge-base/atlas-swarm-parallel-dispatch.md
  - app/Services/Ai/AtlasDecide/AtlasSwarmParallelDispatchService.php
  - tests/Unit/Ai/AtlasDecide/AtlasSwarmParallelDispatchServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-swarm-parallel-dispatch.md
  - app/Services/Ai/AtlasDecide/AtlasSwarmParallelDispatchService.php
flows_to: [atlas-swarm-production-resolver, atlas-patamar4-surface-facade]
unlocks: [parallel_swarm_outcome_collection, deterministic_serial_fallback]
governs: [atlas_swarm_parallel_dispatch]
evidence:
  - app/Services/Ai/AtlasDecide/AtlasSwarmParallelDispatchService.php
  - tests/Unit/Ai/AtlasDecide/AtlasSwarmParallelDispatchServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/AtlasDecide/AtlasSwarmParallelDispatchServiceTest.php"
next_actions:
  - Conectar commandBuilder de producao somente com CLI per-arm testada.
  - Manter flag default off ate haver evidencia de integracao provider-safe.
allowed_changes:
  - Add local command builder wiring with tests.
  - Add CLI per-arm after provider-safe contract exists.
forbidden_changes:
  - call_provider_directly_inside_dispatcher
  - enable_parallel_by_default_without_operator_decision
  - return_raw_provider_output_without_hashing
requires_evidence: true
line_limit: 520
schema:
  - atlas.swarm.parallel_dispatch.v1
---

# Atlas Swarm Parallel Dispatch — Patamar 4 F6 (canon)

> **Status:** `building`
> **Group:** patamar4 / atlas_decide
> **ACOS subsystem:** `ASPD — Atlas Swarm Parallel Dispatcher`
> **Authority:** docs canônicos governam implementação.
> **Claim policy:** provider-safe. Local-first.

## Resumo

ASPD executa arms de swarm via Symfony Process fan-out quando a flag esta ligada, ou fallback serial quando esta desligada.

## Papel no Atlas

`AtlasSwarmExecutorService` executa arms em laço serial. Quando o operador habilitar swarm production (F2) com K=3..5 arms, latência total ≈ Σ latência por arm. **F6 entrega execução paralela honesta** via Symfony Process fan-out, com timeout per-arm + serial fallback determinístico.

## Onde Se Encaixa

Fica entre o swarm resolver/executor e o subprocess local que executa cada arm. Nao decide provider, modelo ou criterio de qualidade.

## Contratos

```
AtlasSwarmParallelDispatchService
  setCommandBuilder(Closure(arm, ctx): array<string>)   // build process argv
  dispatch(arms, ctx, perArmTimeoutSeconds?): envelope
  listDispatches(tail)
  claimPolicy()
```

O `commandBuilder` recebe `(arm, context)` e retorna o argv (array de strings) do subprocess que vai produzir um JSON canônico no stdout:

```
{"result":"success|failure|timeout","latency_ms":int,
 "quality_score":float|null,"output":"..."}
```

Outcome canônico final (one per arm):
```
{arm_id, rank, origin, provider, model, result, latency_ms,
 quality_score, output_hash}
```

## Fluxo

Arms + contexto -> commandBuilder local -> Symfony Process serial/paralelo -> outcome normalizado -> JSONL append-only.

## Regras para IA

- Nao chamar provider direto dentro do dispatcher.
- Nao ligar paralelo por default.
- Nao retornar output bruto; usar hash/provider-safe envelope.
- Nao declarar CLI per-arm pronta antes de existir rota/comando testado.

## Escopo de Implementacao

Service e testes unitarios existem. Wiring de producao do commandBuilder e CLI per-arm ainda sao trabalho subsequente.

## Dependencias

- `AtlasSwarmExecutorService`
- `AtlasSwarmProductionResolverService`
- `Symfony\Component\Process\Process`

## Evidencias

- `app/Services/Ai/AtlasDecide/AtlasSwarmParallelDispatchService.php`
- `tests/Unit/Ai/AtlasDecide/AtlasSwarmParallelDispatchServiceTest.php`

## Riscos

- Provider direto vazar para o dispatcher.
- Output bruto de subprocess virar dado sensivel.
- Operador confundir motor de dispatch com swarm production totalmente conectado.

## Exemplos

```bash
php artisan test tests/Unit/Ai/AtlasDecide/AtlasSwarmParallelDispatchServiceTest.php
```

## Proximas Acoes

- Criar CLI per-arm se a integracao production for aprovada.
- Conectar commandBuilder no service provider com testes de contrato.

## Modos

| Flag | Mode | Comportamento |
|---|---|---|
| `atlas.patamar4.swarm_parallel_enabled=false` | `serial` | Cada arm rodado em sequência (fallback determinístico). |
| `atlas.patamar4.swarm_parallel_enabled=true` | `parallel` | Todos arms `start()` simultâneo, parent `wait()` all com timeout. |
| commandBuilder não setado | `unwired` | Envelope com `reason` honesto, zero outcomes. |
| arms vazios | `noop_no_arms` | Envelope honesto. |

## Timeouts

- `setTimeout($timeoutSeconds)` per Symfony Process.
- Timeout → `result=timeout`, `output_hash=sha256(process_timeout)`.
- Exception não-timeout → `result=failure`, output `process_error: ...`.

## Invariants

1. Flag default OFF — serial puro.
2. Per-arm timeout enforced via Symfony Process.
3. Outcomes carregam `output_hash` (sha256 do output stdout) — nunca conteúdo bruto.
4. Append-only JSONL `storage/atlas/atlas_decide/swarm_parallel.jsonl` com `dispatch_hash`.
5. claim_policy provider-safe.
6. CommandBuilder é a única lógica injetada — service não conhece providers.

## Como o operador habilita produção

1. Habilita flag: `config(['atlas.patamar4.swarm_parallel_enabled' => true])` ou env.
2. Em AppServiceProvider, registra `commandBuilder` que retorna `[PHP_BINARY, artisan, 'atlas:swarm:execute-arm', '--arm='.$json, ...]` (CLI dedicada a executar single arm via AtlasSwarmProductionResolverService, futura).
3. Cada subprocess fala stdout JSON canônico.
4. `dispatch()` retorna envelope com K outcomes paralelos.

A CLI `atlas:swarm:execute-arm` é **trabalho subsequente** — F6 entrega o motor de dispatch + contrato, não a CLI per-arm. Doc explícito.

## Filtro 5 perguntas

1. **Wrapper composto?** Sim — paralelismo é multiplicador composto sobre swarm executor.
2. **Antifrágil?** Sim — timeout vira sinal, falha vira sinal, parallel mode pode degradar pra serial sem perder determinismo.
3. **Linguagem natural?** Sim — operador requisita; Atlas paraleliza; entrega K outcomes.
4. **Destrava função?** Sim — sem paralelismo K=5 arms = 5x latência.
5. **Local-first?** Sim — Symfony Process spawn local; sem rede além do que cada subprocess faz.

## Testes canon

- `tests/Unit/Ai/AtlasDecide/AtlasSwarmParallelDispatchServiceTest.php` — 11 testes (unwired, noop, serial, parallel, outcome shape, invalid command, JSONL persist, claim_policy, schema/hash, failure subprocess, non-JSON subprocess).

## Cross-references

- `AtlasSwarmExecutorService` (laço serial atual).
- `AtlasSwarmProductionResolverService` (F2 — quem o commandBuilder futuro deve invocar).
- `Symfony\Component\Process\Process` (engine de fan-out).
