---
id: atlas-swarm-executor
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Swarm Executor
slug: atlas-swarm-executor
status: building
implementation_state: runtime_available
category: atlas_decide
priority: 92
summary: Fan-out cross-provider arms num único turn. Cada arm invoca resolver injetável; fan-in agrega outcomes; tie-break determinístico canon; Live Outcome Feedback registra cada arm.
tags: [atlas-ai, swarm, fan-out, fan-in, parallel, patamar-4]
capabilities: [arm_resolver_injection, outcome_aggregation, deterministic_tie_break, winner_selection]
decisions:
  - Resolver injetável via setResolver(Closure).
  - Tie-break canon: success > failure > (quality desc > latency asc > rank asc).
  - Cada arm registra outcome em Live Outcome Feedback ledger.
  - Append-only JSONL com execution_hash.
maintenance:
  - Production resolver wired no AppServiceProvider invocando AiProviderManager.
  - Future: paralelismo real via async (Swoole/parallel) preservando tie-break canon.
risk_level: medium
owner: atlas-ai
graph_id: atlas-swarm-executor
human_name: Atlas Swarm Executor
canonical_name: Atlas Swarm Executor
technical_name: AtlasSwarmExecutorService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-swarm-executor.md
graph_title: Atlas Swarm Executor
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-decide-swarm-conductor
graph_status: building
graph_source: repo
depends_on: [atlas-decide-swarm-conductor, atlas-constitutional-kernel, atlas-decide-live-outcome-feedback]
flows_to: [atlas-ai-context-panel]
unlocks: [parallel_swarm_execution_single_turn]
governs: [arm_fan_in_winner_selection]
authority_class: executor
related_paths:
  - app/Services/Ai/AtlasDecide/AtlasSwarmExecutorService.php
  - app/Console/Commands/AtlasSwarmExecutorCommand.php
  - tests/Unit/Ai/AtlasDecide/AtlasSwarmExecutorServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-swarm-executor.md
  - app/Services/Ai/AtlasDecide/AtlasSwarmExecutorService.php
evidence:
  - app/Services/Ai/AtlasDecide/AtlasSwarmExecutorService.php
  - tests/Unit/Ai/AtlasDecide/AtlasSwarmExecutorServiceTest.php
evidence_refs:
  - symbol: AtlasSwarmExecutorService
  - command: atlas:swarm:execute
  - test: AtlasSwarmExecutorServiceTest
required_tests:
  - "php artisan test tests/Unit/Ai/AtlasDecide/AtlasSwarmExecutorServiceTest.php"
next_actions:
  - Wirar production resolver invocando AiProviderManager.get(arm.provider)->runStreaming(...) com timeout per arm.
  - Adicionar paralelismo real via PHP async runtime quando disponível.
allowed_changes:
  - Adicionar arm-level retry e per-arm timeout.
forbidden_changes:
  - synthetic_resolver_as_production_default
  - skip_live_feedback_ledger_record
  - benchmark_or_rivals_claim
requires_evidence: true
line_limit: 480
schema:
  - atlas.swarm.execution_envelope.v1
  - atlas.swarm.arm_outcome.v1
---

# Atlas Swarm Executor

## Resumo

SwarmConductor produz dispatch envelope (K arms). Este service consome o dispatch e executa cada arm via resolver injetável dentro de um único turn lógico. Fan-in agrega outcomes; tie-break determinístico seleciona winner; Live Outcome Feedback recebe outcome de cada arm.

## Papel no Atlas

Cenário "swarm A=reasoning, B=writing, C=code, D=vision num único turn" da spec original Patamar 4. SwarmConductor era planner; este é o executor.

## Onde Se Encaixa

- Conductor `dispatch(work)` → envelope com arms
- Executor `setResolver(Closure)` injeta como cada arm é executado
- Executor `execute(dispatch, ctx)` faz fan-out + fan-in + tie-break + receipt
- Live Outcome Feedback recebe cada outcome

## Contratos

- `atlas.swarm.execution_envelope.v1` — envelope agregado
- `atlas.swarm.arm_outcome.v1` — outcome de um arm

## Fluxo

1. Operator/automation chama Conductor.dispatch(work) → arms
2. Executor.execute(arms_envelope, ctx)
3. Para cada arm: resolver retorna {result, latency_ms, quality_score, output}
4. Cada outcome → Live Outcome Feedback record
5. tie-break canon → winner
6. Receipt JSONL com execution_hash

## Regras para IA

- NUNCA usar synthetic resolver como production default.
- NUNCA pular Live Outcome Feedback record.
- Tie-break é canon; nunca recalibrar runtime.

## Escopo de Implementacao

Service + CLI + tests + receipt JSONL. CLI default usa synthetic resolver para smoke; production wire via AppServiceProvider opcional.

## Dependencias

- AtlasSwarmConductorService (source)
- AtlasConstitutionalKernelService (kernel_hash anchor)
- AtlasDecideLiveOutcomeFeedbackService (per-arm telemetry)

## Evidencias

Service + tests + CLI + JSONL append-only.

## Riscos

- Resolver lento → todos arms encadeiam (sync). Mitigação: future async runtime.
- Tie-break empata em quality_score; latency e rank desempate são determinísticos.

## Exemplos

```bash
php artisan atlas:swarm:execute \
  --action=execute \
  --task-category=code_generation \
  --role=primary \
  --parallelism=3 \
  --json
```

## Proximas Acoes

Production resolver wired no AppServiceProvider (AiProviderManager.get → AiProvider.run).

## Safety

- claim_policy provider-safe enforced via Live Outcome Feedback.
- Append-only JSONL.
- Tie-break determinístico canon.
- Resolver injection canon (não default real provider em CLI).
