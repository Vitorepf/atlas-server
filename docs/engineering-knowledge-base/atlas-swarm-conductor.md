---
id: atlas-swarm-conductor
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Swarm Conductor (Patamar 4 · 4.5)
slug: atlas-swarm-conductor
status: building
implementation_state: runtime_available_dispatch_only
category: atlas_decide
priority: 91
summary: Composer de dispatch multi-arm que usa ADML, Constitutional Kernel e Autonomy Admission para emitir envelope seguro sem executar providers ou declarar winner.
tags: [atlas-ai, atlas-decide, swarm, routing, patamar-4]
capabilities: [swarm_dispatch_envelope, multi_arm_dispatch_preflight, adml_route_composition, aggregate_claim_lock]
decisions:
  - Swarm Conductor nao executa providers; ele apenas monta dispatch envelope governado.
  - Routing continua pertencendo ao ADML; Conductor nao cria tabela paralela.
  - Claims de winner, rivals ou benchmark permanecem proibidos no envelope.
maintenance:
  - Atualizar antes de mudar parallelism cap, arm schema, ADML integration ou admission gates.
  - Manter testes cobrindo insufficient_evidence, claim_policy e persistencia append-only.
risk_level: medium
owner: atlas-ai
graph_id: atlas-swarm-conductor
graph_title: Atlas Swarm Conductor
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-decide-meta-learning-loop-closure
graph_status: building
graph_source: repo
depends_on:
  - atlas-decide-meta-learning-loop-closure
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
authority_class: composer
related_paths:
  - docs/engineering-knowledge-base/atlas-swarm-conductor.md
  - docs/engineering-knowledge-base/atlas-decide-meta-learning-loop-closure.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - app/Services/Ai/AtlasDecide/AtlasSwarmConductorService.php
  - app/Services/Ai/AtlasDecide/AtlasDecideMetaLearningService.php
  - tests/Unit/Ai/AtlasDecide/AtlasSwarmConductorServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-swarm-conductor.md
  - app/Services/Ai/AtlasDecide/AtlasSwarmConductorService.php
flows_to: [atlas-cognition-operating-system]
unlocks: [governed_multi_arm_dispatch, swarm_dispatch_audit]
governs: [swarm_dispatch_envelopes, swarm_arm_envelopes]
evidence:
  - app/Services/Ai/AtlasDecide/AtlasSwarmConductorService.php
  - tests/Unit/Ai/AtlasDecide/AtlasSwarmConductorServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/AtlasDecide/AtlasSwarmConductorServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Integrar consumers de execucao fora do conductor com owner docs e claim_policy preservada.
  - Provar dispatch multi-arm end-to-end sem claim de winner.
allowed_changes:
  - Add provider execution consumers only outside this conductor with explicit owner docs.
forbidden_changes:
  - duplicate_routing_table
  - bypass_constitutional_kernel
  - bypass_autonomy_admission
  - claim_aggregate_winner_without_evidence
requires_evidence: true
line_limit: 520
schema:
  - atlas.swarm_conductor.dispatch_envelope.v1
  - atlas.swarm_conductor.arm.v1
---

# Atlas Swarm Conductor — Patamar 4 · 4.5

## Resumo

Composer de dispatch multi-arm governado por ADML, Kernel e Admission.

## Papel no Atlas

Montar envelopes de dispatch paralelos sem executar providers e sem declarar vencedor.

## Onde Se Encaixa

Fica sobre ADML e antes de qualquer consumer que execute provider.

## Contratos

Schemas `atlas.swarm_conductor.dispatch_envelope.v1` e `atlas.swarm_conductor.arm.v1`.

## Fluxo

ADML recommendation -> arms -> Kernel -> Admission -> dispatch JSONL.

## Regras para IA

Nao criar routing table paralela. Nao usar dispatch como prova de benchmark, rivals ou winner.

## Escopo de Implementacao

Service e testes unitarios existem; execucao provider fica fora deste composer.

## Dependencias

ADML, Constitutional Kernel e Autonomy Admission.

## Evidencias

Service `AtlasSwarmConductorService` e teste `AtlasSwarmConductorServiceTest`.

## Riscos

Confundir orquestracao de arms com execucao real ou resultado comparativo.

## Exemplos

Use `dispatch()` para emitir envelope; consumer separado decide execucao permitida.

## Proximas Acoes

Adicionar integracao com consumer real mantendo claim_policy bloqueante.

## Por que existe

ADML (4.x do Patamar 2) decide UMA rota ativa por (task_category, role, framework). Em Patamar 4, há cenários onde Atlas precisa **dispatch multi-arm** — executar a mesma tarefa em K rotas paralelas (K=2..5) para:

- Aprendizado: comparar outcomes K-vias para alimentar o ledger.
- Robustez: redundância quando uma rota falha.
- Tarefas onde diversidade de provider melhora qualidade final.

Swarm Conductor NÃO redefine routing. Lê do ADML qual é a melhor rota + runner-up + signal → monta K arms canônicos → atravessa Kernel + Admission → emite dispatch envelope. Execução real é responsabilidade do consumer (AiGatewayService).

## Princípio de não-duplicação (canon)

| Conceito                                 | Fonte canon (NÃO duplicar)                                              |
|------------------------------------------|-------------------------------------------------------------------------|
| Routing recommendation                   | `AtlasDecideMetaLearningService::recommend()`                           |
| Routing table                            | `AtlasDecideMetaLearningService::routingTable()`                        |
| Active route lookup                      | `AtlasDecideMetaLearningService::activeRouteFor()`                      |
| Pétreo validation                        | `AtlasConstitutionalKernelService::validateChange()`                    |
| Autonomy admission                       | `AtlasAutonomyAdmissionService::admit()`                                |
| Provider catalog                         | (consumer-side; conductor não conhece catálogo, só passa adiante)        |
| Multi-case evidence                      | `AtlasForgeRivalsProviderPerformanceLedgerService` (lido via ADML)      |

Regra: Conductor não chama provider, não calcula winner, não claim aggregate superiority. **Apenas dispatch + gates + envelope**.

## API

```php
dispatch(array $work): array            // atlas.swarm_conductor.dispatch_envelope.v1
listDispatches(): array
lastDispatch(): ?array
```

### `dispatch` input

```json
{
  "task_category": "code_generation|refactor|...",
  "role": "primary|secondary|...",
  "framework": "laravel|react|...",      // opcional
  "parallelism": 2,                       // K: 1..5
  "scope": { "privacy_class": "public|normal|sensitive|secret|cyber" },
  "requested_autonomy": "execute_with_approval|autonomous",
  "rationale": "free-form"
}
```

### `dispatch` envelope

```json
{
  "schema_version": "atlas.swarm_conductor.dispatch_envelope.v1",
  "dispatch_id": "swarm_...",
  "generated_at": "ISO",
  "task_category": "...",
  "role": "...",
  "framework": "...",
  "requested_parallelism": 2,
  "effective_parallelism": 2,
  "arms": [
    {
      "schema_version": "atlas.swarm_conductor.arm.v1",
      "arm_id": "arm_a_...",
      "rank": 1,
      "provider": "claude_code|codex_cli|gemini_cli|atlas_local|...",
      "model": "...",
      "origin": "recommended|runner_up|local_fallback"
    }
  ],
  "kernel_decision": "allow|block|allow_with_human_approval",
  "admission_decision": "allow_autonomous|allow_with_approval|deny",
  "claim_policy": {
    "aggregate_winner_claim_allowed": false,
    "rivals_claim_allowed": false,
    "benchmark_claim_allowed": false
  },
  "dispatch_hash": "sha256:..."
}
```

### Regra dos arms

- `parallelism=1` → 1 arm (recommended_provider).
- `parallelism=2` → recommended + runner_up (se existir).
- `parallelism≥3` → recommended + runner_up + `atlas_local` fallback arm.
- Se ADML retorna `signal=insufficient_evidence` → `effective_parallelism = 0`, arms=[], envelope marca `kernel_decision=allow` mas `admission_decision=deny` (não roteia sem evidência).

### Gates obrigatórios

1. **Constitutional Kernel** sobre `change_kind=swarm_dispatch`.
2. **Autonomy Admission** sobre `change_kind=swarm_dispatch`.
3. **`claim_policy`** hardcoded no envelope — toda agregação posterior está proibida de claim de winner/rivals/benchmark.

## Storage

- Append-only JSONL: `storage/atlas/swarm/dispatches.jsonl`.

## Não-objetivos

- **NÃO** executa providers — apenas emite envelope.
- **NÃO** agrega resultados — quem faz isso é o consumer + ledger.
- **NÃO** claim winner — `aggregate_winner_claim_allowed=false`.
- **NÃO** duplica routing.

## Replay / audit

`listDispatches()` + cross-reference com `routingTable()` do ADML reconstrói qualquer dispatch retroativamente.
