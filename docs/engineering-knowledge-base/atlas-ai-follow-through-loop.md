---
id: atlas-ai-follow-through-loop
type: engineering_knowledge
title: Atlas AI Autonomous Follow-Through Loop
status: active
category: runtime
priority: 91
summary: Loop seguro que conduz missions planejadas ciclo a ciclo sem provider externo nem acao perigosa.
tags:
  - atlas-ai
  - mission
  - follow-through
capabilities:
  - mission_follow_through
decisions:
  - Follow-Through executa ciclos seguros sobre Mission Foundation sem duplicar Mission Mode.
  - Termos `planned`, `running`, `blocked`, `certifying`, `completed`, `simulated`, `handoff_dev` e `handoff_forge` sao estados/eventos canonicos de lifecycle; nao significam doc futura ou scaffold.
maintenance:
  - Atualizar quando MissionFollowThroughService, comandos ou lifecycle mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-follow-through-loop
graph_title: Atlas AI Autonomous Follow-Through Loop
graph_world: atlas
graph_layer: flow
graph_kind: flow
graph_parent: atlas-kernel-mission-foundation
graph_status: active
graph_source: repo
human_name: Atlas AI Autonomous Follow-Through Loop
canonical_name: Atlas AI Autonomous Follow-Through Loop
technical_name: atlas-ai-follow-through-loop
cartography_type: flow
canonical_source: docs/engineering-knowledge-base/atlas-ai-follow-through-loop.md
owner: mission-runtime
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-follow-through-loop.md
allowed_changes:
  - Atualizar contrato de follow-through com codigo e testes correspondentes.
forbidden_changes:
  - Declarar provider execution ou acao perigosa dentro do loop.
depends_on:
  - atlas-kernel-mission-foundation
flows_to:
  - atlas-code
unlocks:
  - mission-cycle-execution
governs:
  - mission-follow-through
evidence:
  - docs/engineering-knowledge-base/atlas-ai-follow-through-loop.md
required_tests:
  - "php artisan test tests/Feature/Ai/Mission"
requires_evidence: true
risk_level: medium
next_actions:
  - Manter loop alinhado aos gates de Mission Foundation.
---
# Atlas AI Autonomous Follow-Through Loop

## Resumo

Loop backend-first que conduz uma mission planejada ate terminal/blocked.

## Papel no Atlas

Executa progresso seguro sobre missions sem provider externo.

## Onde Se Encaixa

Acima de Mission Mode e abaixo de Hyperflow runtime.

## Contratos

Nao executa acao perigosa, provider externo ou conclusao sem certificacao.

## Fluxo

Seleciona work order, resolve flow, registra evidencia e tenta certificacao.

## Regras para IA

Nao trate simulated dispatch como execucao externa real.

## Escopo de Implementacao

Documento governa o loop de follow-through e seus comandos.

## Dependencias

Mission Foundation, lifecycle, work orders e evidence refs.

## Evidencias

Codigo, comandos e eventos de mission/follow-through.

## Riscos

Confundir handoff/simulated dispatch com autonomia irrestrita.

## Exemplos

`php artisan atlas:ai:mission run --mission=<uuid> --json`.

## Proximas Acoes

Manter testes e docs sincronizados com o runtime.

**Status:** ativo · entregue 2026-05-19
**Schema raiz:** `atlas.ai.mission_follow_through.cycle.v1` · `atlas.ai.mission_follow_through.run.v1` · `atlas.ai.control_plane.follow_through.v1`
**Camada:** acima do Mission Mode, abaixo do Hyperflow runtime.

---

## O que é

Loop backend-first que pega uma mission **planned/running** e conduz o próximo ciclo de execução **sem chamar provider** ou executar ações perigosas. Cada `runNext()`:

1. Inspeciona o estado da mission.
2. Transit `planned → running` se ainda não.
3. Seleciona o próximo `AiWorkOrder` (via `WorkOrderSelectionService` puro).
4. Resolve o `flow_id` canônico (specialist flow / atlas_dev / atlas_forge).
5. Executa de forma **safe**: marca o WO com status final (`simulated`, `handoff_dev`, `handoff_forge`), anexa `AiMissionEvidenceRef` tipo receipt + receipt_hash determinístico, registra `AiMissionEvent` canônico `follow_through.cycle.*`.
6. Quando todos WO terminais → tenta `MissionCertificationService::certify()`. Só transita para `completed` se PASSED (13 checks críticos verdes).
7. Quando falha → marca mission `blocked`, registra blocker em event, retorna `next_action` honesto.
8. Retorna `MissionFollowThroughResult` (DTO) com hash determinístico do ciclo.

## Diferença para Mission Mode

| Camada | Responsabilidade |
|---|---|
| **Mission Mode** (entregue 2026-05-19) | Detecta intent persistente, cria `AiMission`, decompõe objectives, planeja work_orders, transita `draft→planned`. **Cria** a missão. |
| **Follow-Through Loop** (esta entrega) | Pega a mission planejada e **conduz** ciclo a ciclo até terminal/blocked. **Executa** a missão (safely). |

Mission Mode = "criar plano". Follow-Through = "seguir o plano".

## Safe execution boundary

O loop é canon-safe. Em nenhuma circunstância chama provider externo ou executa ação perigosa. Estratégia por flow:

| Flow resolvido | Action | WorkOrder.status final |
|---|---|---|
| `atlas_research/finance/marketing/strategy/cyber/personal_development/automation/conversation/review` | `safe_simulated_dispatch` | `simulated` |
| `atlas_dev/atlas_debug/atlas_review` | `handoff_to_atlas_dev` | `handoff_dev` |
| `atlas_forge` (mission_type=obra) | `handoff_to_atlas_forge` | `handoff_forge` |
| Sem flow resolvível | block branch | (não muda) |

Handoffs marcam o WO como "encaminhado para outra equipe" — Follow-Through não os reprocessa.

## Lifecycle

Reusa o canon `MissionLifecycleService` (9 estados):

```
draft → planned ─[runNext]→ running ─[cycles]→ certifying ─[passed]→ completed
                                ↓
                            blocked ─[human resolves]→ repairing → running
```

Outcomes canônicos de cycle (`MissionFollowThroughResult::OUTCOME_*`):

- `simulated_safe` — non-programming safe sim
- `handoff_dev` / `handoff_forge` — encaminhado
- `no_selectable_step` — todos WO bloqueados ou sem WO
- `mission_blocked` — falha → mission BLOCKED
- `mission_completed_pending_cert` — certify rodou mas falhou
- `mission_certified` — certify PASSED → mission COMPLETED
- `mission_already_terminal` — noop
- `noop_invalid_state` — estado que não permite progresso (waiting_approval, blocked)

## Comandos

```bash
# Single cycle
php artisan atlas:ai:mission run --mission=<uuid> --json

# Multi-cycle (até terminal/blocked)
php artisan atlas:ai:mission run --mission=<uuid> --until-blocked --max-cycles=3 --json
```

`--max-cycles` default 3, hard cap 20 (clampado server-side).

Exit codes:
- `0` — operação executada (outcome no payload)
- `1` — runtime error (mission não encontrada, exception)
- `2` — usage error (faltando `--mission`)

## Control Plane

`MissionControlPlaneService::snapshot()` agora carrega bloco `follow_through`:

```json
{
  "follow_through": {
    "schema_version": "atlas.ai.control_plane.follow_through.v1",
    "mission_uuid": "...",
    "mission_status": "running",
    "cycles_count": 3,
    "last_cycle": {
      "event_type": "follow_through.cycle.completed",
      "cycle_id": "...",
      "flow": "atlas_research",
      "action": "safe_simulated_dispatch",
      "status_after": "simulated",
      "receipt_hash": "...",
      "created_at": "..."
    },
    "active_blockers": [...],
    "work_order_summary": {
      "total": 3,
      "selectable": 1,
      "blocked": 0,
      "terminal": 2,
      "all_terminal": false,
      "has_selectable": true
    },
    "next_action": "collect_evidence_then_try_certify"
  }
}
```

Resolução do `MissionFollowThroughService` no `MissionControlPlaneService` é **lazy** (constructor null-default + fallback via `app()`). Tests legados continuam funcionando.

## Completion gate

**Não há atalho.** Para transitar para `completed`:

1. Todos `AiWorkOrder` precisam estar em status terminal (`completed`/`failed`/`cancelled`/`simulated`/`handoff_dev`/`handoff_forge`).
2. `MissionCertificationService::certify()` precisa retornar `STATUS_PASSED` (13 checks CRITICAL verdes — entre eles: evidence_refs não-vazio, work_orders com receipt_hash, DoD criteria, eventos canônicos, sem blockers).
3. Loop transita `certifying → completed` e sela `certification_hash` (SHA-256 determinístico).

**Cycle gera `receipt` evidence_ref automaticamente** — isso é audit trail do próprio loop, não substitui evidence de produto. Para uma mission produzir certify PASSED com requisitos exigentes, o operador (ou outro caller upstream) deve anexar evidence adicional (test/diff/doc/artifact).

## Anti-loop infinito

Hard cap `MAX_CYCLES_HARD_CAP = 20`. CLI clampa server-side. Cada ciclo:
- avança status do WO selecionado;
- ou marca mission BLOCKED;
- ou tenta certify.

Loop só continua enquanto `result.shouldContinue() === true`. Estados terminais (`mission_certified`, `mission_blocked`, `mission_already_terminal`, `noop_invalid_state`) param o loop imediatamente.

## Limitações reais

- **Selector estatedeo simples** — `objective.priority ASC + created_at ASC`. Sem otimização por risco/dependência. Próxima fatia: dependency graph se preciso.
- **`safe_simulated_dispatch` não chama specialist flow handler real** — apenas marca WO simulated e gera receipt. Specialist handlers são read-only planning hoje; chamá-los aqui não muda outcome.
- **Programming handoff é "marcar e parar"** — Follow-Through não materializa `AiForgeIntake` real (evita side effect no DB de testes). Caller pode chamar `ForgeIntakeService::intakeFromEscalationPacket` depois quando quiser propagar.
- **maxCycles default 3** — operador escolhe maior se precisar. Hard cap 20 evita loop infinito mesmo com input malicioso.
- **Sem UI dedicada** — backend-first; Control Plane existente já agrega.

## Relação com peças adjacentes

| Peça | Relação |
|---|---|
| **Mission Mode** | Cria mission + plan. Follow-Through é o "play". |
| **Mission Foundation** | Reusa MissionLifecycle/Evidence/Certification/CanonicalHash sem duplicar. |
| **Hyperflow V2** | Independente. Mission Mode roda no `AtlasHyperflowEntryService::run()`. Follow-Through roda **depois**, via comando ou job futuro. |
| **Specialist Flows** | Resolve flow_id canon, mas não chama handler (handlers são read-only planning hoje). |
| **Forge intake** | Follow-Through marca `handoff_forge` no WO. Caller upstream materializa AiForgeIntake quando quiser. |
| **Control Plane** | `MissionControlPlaneService.snapshot()` agora carrega `follow_through` block via lazy `MissionFollowThroughService` resolve. |

## Arquivos

- `app/Services/Ai/Mission/WorkOrderSelectionService.php` — selector puro
- `app/Services/Ai/Mission/MissionFollowThroughResult.php` — DTO + hash determinístico
- `app/Services/Ai/Mission/MissionFollowThroughService.php` — orchestrator
- `app/Console/Commands/AtlasAiMissionCommand.php` — action `run` adicionada
- `app/Services/Ai/Mission/MissionControlPlaneService.php` — bloco `follow_through` no snapshot
- `tests/Feature/Ai/Mission/MissionFollowThroughServiceTest.php` — 13 testes
- `tests/Feature/Ai/Mission/AtlasAiMissionRunCommandTest.php` — 7 testes
