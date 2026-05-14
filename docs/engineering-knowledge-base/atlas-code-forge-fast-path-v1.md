---
id: atlas-code-forge-fast-path-v1
type: engineering_knowledge
title: Atlas Code Forge Operator Fast Path v1
status: active
category: programming-forge
priority: 100
summary: Orquestracao canonica que faz uma Obra avancar pelo caminho profissional minimo do Forge (Obra → WorkItem → Spec/Plan/Tasks → Forge Live Execution → Checkpoint → Evidence) com uma acao explicita do operador. Reusa controllers canonicos; nao cria runtime novo; nao chama provider externo.
tags:
  - atlas
  - atlas-code
  - forge
  - fast-path
  - operator
capabilities:
  - atlas_code_forge_fast_path
  - obra_to_live_execution_orchestration
  - work_item_resolution
  - spec_plan_compile_reuse
  - live_execution_dispatch_async_or_sync
  - checkpoint_optional
  - fail_closed_without_obra
decisions:
  - Atlas Code SCOR-1 continua Forge-only; Fast Path nao introduz modo alternativo.
  - Sem Obra valida, Fast Path falha fechado e nao provisiona nenhum recurso.
  - Fast Path NAO gera Obra UUID silenciosa nem cria Obra implicita.
  - Fast Path orquestra controllers canonicos existentes (programming work-items, forge live-executions, checkpoints).
  - Fast Path NAO substitui benchmark externo nem libera completion_allowed quando external_rivals_certification bloqueia.
  - Stage canonicos sao 8 e fixos (obra_binding, workspace_binding, work_item_resolution, spec_plan_resolution, task_queue_resolution, execution_dispatch, state_projection, operator_next_action).
maintenance:
  - Atualize quando o contrato de algum controller orquestrado mudar (work-items, forge live-executions, checkpoints).
  - Rode `atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict` apos qualquer alteracao no service.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md
  - docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php
  - app/Http/Controllers/AtlasCodeForgeFastPathController.php
  - app/Console/Commands/AtlasCodeForgeFastPathCommand.php
  - tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-forge-fast-path-v1
graph_title: Atlas Code Forge Operator Fast Path v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-forge-live-execution-e2e-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php
  - app/Http/Controllers/AtlasCodeForgeFastPathController.php
  - app/Console/Commands/AtlasCodeForgeFastPathCommand.php
  - tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php
allowed_changes:
  - Adicionar stages canonicas ao Fast Path quando novos controllers forem incorporados ao pipeline.
  - Adicionar opcoes ao payload (intent, modos, flags) preservando fail-closed.
forbidden_changes:
  - Criar Obra silenciosa dentro do Fast Path quando obra_id nao foi passado pelo operador.
  - Chamar provider externo (Claude/Codex/Gemini) do Fast Path.
  - Reportar status=passed sem ter passado por todas as stages canonicas.
  - Permitir Atlas Code multi-modo. SCOR-1 continua Forge-only.
depends_on:
  - atlas-forge-live-execution-e2e-v1
  - atlas-programming-forge-flow
flows_to:
  - atlas-code
  - atlas-forge-operating-system
unlocks:
  - atlas-code-forge-operator-flow
governs:
  - atlas_code_forge_fast_path
evidence:
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php
  - tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php
required_tests:
  - "php artisan test --filter AtlasCodeForgeFastPathTest"
  - "php artisan atlas:code:forge-fast-path --json --strict"
  - "php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict"
requires_evidence: true
risk_level: high
visual_tags:
  - forge
  - fast-path
  - operator
ai_entrypoints:
  - Leia este doc antes de alterar AtlasCodeForgeFastPathService, AtlasCodeForgeFastPathController ou o comando atlas:code:forge-fast-path.
ai_usage_notes:
  - Nao introduza Obra UUID silenciosa em Fast Path. Falhe fechado.
  - Mantenha as 8 stages canonicas e os 3 modos (prepare_only/execute_async/execute_sync).
quality_gates:
  - fast-path-fail-closed-without-obra
  - fast-path-orchestrates-canonical-controllers
  - fast-path-no-external-provider
failure_modes:
  - Fast Path criar Obra implicita quando obra_id nao foi passado.
  - Fast Path retornar passed sem rodar todas as stages canonicas.
  - Fast Path desviar do contrato dos controllers canonicos.
observability_signals:
  - forge_fast_path_status
  - work_item_id
  - execution_id
  - history_id
  - checkpoint_id
next_actions:
  - Manter este doc sincronizado com AtlasCodeForgeFastPathService.
---
# Atlas Code Forge Operator Fast Path v1

## Resumo

Orquestracao canonica que faz uma Obra avancar pelo caminho profissional
minimo do Forge com **uma** acao explicita do operador:

```text
Obra (existente)
  → WorkItem (create or reuse via AtlasCodeProgrammingWorkItemController::store)
  → Spec / Plan / Tasks (compileSpecPlan reuse)
  → Forge Live Execution (async via AtlasCodeForgeLiveExecutionJob OR sync via store)
  → Run History (engineering_run_id)
  → Checkpoint opcional (AtlasCodeCheckpointController)
  → state_projection (AtlasProject.metadata latest_forge_live_execution)
  → Fast Path snapshot (AtlasProject.metadata latest_atlas_code_forge_fast_path)
  → operator_next_action canonico
```

Sem Obra, falha fechado. Sem provider externo. Sem mock.

Schema canonico: `atlas.code.forge_fast_path.v1`.

## Papel no Atlas

Atlas Code SCOR-1 ja tinha as pecas profissionais (WorkItem, Spec/Plan,
Live Execution async/sync, Checkpoint). O Fast Path elimina o atrito de
operacao: um clique/comando avanca a Obra pelo ciclo minimo, com fail-closed
honesto em cada contrato canonico.

NAO substitui:

- A certificacao Forge Runtime (Nivel 1 — `atlas:forge:runtime-certify`).
- A certificacao Forge Live Execution (Nivel 2 — `atlas:forge:live-execute`).
- A certificacao Atlas Code Enterprise (`atlas:code:enterprise-certify`).
- A bateria Rivals externa (`external_rivals_certification`).

## Onde Se Encaixa

Filho de `atlas-forge-live-execution-e2e-v1.md`. Complementar a
`atlas-programming-forge-flow.md`. Orquestra os controllers documentados em
`obras/shared-workspace-and-forge.md`.

## Contratos

- **Obra obrigatoria.** Sem `obra_id` valido, o service retorna
  `status=blocked`, stage `obra_binding` com `blocker=obra_required`,
  remaining_blockers inclui `obra_required`. Nenhum recurso e provisionado.
- **NAO criar Obra silenciosa.** Se a Obra nao existir, o service retorna
  `obra_not_found` (nao cria nada).
- **Workspace binding canonico.** Obra precisa estar nos dominios Atlas Code
  (`atlas` ou `programming`). Outros dominios falham fechado.
- **WorkItem intent obrigatorio.** Se a Obra nao tem WorkItem vinculado e nao
  ha `intent` no payload nem `goal/desired_outcome/description/title` na Obra,
  o service retorna `work_item_intent_required`.
- **Spec/Plan reuse.** WorkItem com spec/plan/tasks ja compilados retorna
  status `already_planned` (idempotente).
- **Modos canonicos:** `prepare_only` (sem execution), `execute_async`
  (default, dispatch async job), `execute_sync` (executa live execution
  imediatamente).
- **Checkpoint opcional.** Apenas quando `create_checkpoint=true` e somente
  apos execution dispatch.
- **Snapshot persistido.** Cada run com Obra grava
  `latest_atlas_code_forge_fast_path` e atualiza
  `atlas_code_forge_fast_path_history` (limite 10) em `AtlasProject.metadata`.
- **Read-model Atlas Code.** `GET /atlas-code/works/{obra}/state` expõe
  `forge_fast_path` para a UI sem rerodar o Fast Path.

## Fluxo

| # | Stage | Componente real reusado | Status canonico |
|---|---|---|---|
| 1 | `obra_binding` | `AtlasProject::find` | passed / blocked (obra_required, obra_not_found) |
| 2 | `workspace_binding` | `AtlasProject.metadata.workspace_path + domain check` | passed / blocked (forge_workspace_required) |
| 3 | `work_item_resolution` | `AtlasCodeProgrammingWorkItemController::store` (idempotente) | passed (created/reused) / blocked |
| 4 | `spec_plan_resolution` | `AtlasCodeProgrammingWorkItemController::compileSpecPlan` | passed (planned/already_planned) / blocked |
| 5 | `task_queue_resolution` | `AtlasProgrammingWorkItem.tasks_json` | passed / degraded |
| 6 | `execution_dispatch` | `AtlasCodeForgeExecutionController::store` ou `::startAsync` | passed / blocked / degraded |
| 7 | `state_projection` | `AtlasProject.metadata.latest_forge_live_execution` | passed |
| 8 | `operator_next_action` | next_action canonica baseada em status | passed |

## Persistencia e Read-Model

O retorno do Fast Path nao e transiente. Quando ha Obra valida, o service
persiste:

- `AtlasProject.metadata.latest_atlas_code_forge_fast_path`
- `AtlasProject.metadata.atlas_code_forge_fast_path_history`

O endpoint de estado do Atlas Code projeta esse snapshot como:

```text
GET /atlas-code/works/{obra}/state
  → forge_fast_path
```

O desktop consome esse campo como parte de `WorkStateSnapshot`, depois aciona
Fast Path via `useBridge.runForgeFastPath()` para recarregar a Obra e manter o
rail sincronizado.

## API

```text
POST /atlas-code/works/{project}/forge/fast-path
```

Payload:

```json
{
  "mode": "execute_async",
  "intent": "opcional",
  "auto_create_work_item": true,
  "auto_compile_spec_plan": true,
  "start_execution": true,
  "create_checkpoint": false,
  "operator_id": "atlas-code-local-operator"
}
```

Resposta canonica (200 prepare/201 sync/202 async/409 blocked):

```json
{
  "schema_version": "atlas.code.forge_fast_path.v1",
  "status": "queued",
  "mode": "execute_async",
  "obra_id": "<uuid>",
  "work_item_id": "<uuid>",
  "work_item_code": "OTH-XXXXXXXX",
  "spec_hash": "<sha256>",
  "plan_hash": "<sha256>",
  "task_count": 2,
  "execution_id": "<ulid>",
  "history_id": null,
  "checkpoint_id": null,
  "stages": [...8 stages canonicas...],
  "blockers": [],
  "evidence_refs": [...],
  "commands": {...},
  "next_action": "poll_async_execution_and_open_review_when_passed",
  "external_provider_call": false
}
```

## CLI

```bash
php artisan atlas:code:forge-fast-path --json --strict
# Sem --obra: exit 1, status=blocked, blocker=obra_required.

php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict
# exit 0 quando status=queued e nenhum blocker.

php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=prepare_only --json
# Cria WorkItem + Spec/Plan sem disparar execucao.

php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_sync --create-checkpoint --json
# Executa live execution imediatamente e cria checkpoint final.
```

## UI

Painel `Forge Fast Path` integrado no `PlanPanel` da surface Code. Mostra
3 botoes (preparar / async / sync), status do report ao terminar, blockers
e comandos canonicos. Sem Obra selecionada, exibe blocker explicito.

## Regras para IA

- Nao crie Obra silenciosa dentro do Fast Path.
- Nao chame provider externo deste fluxo.
- Mantenha as 8 stages canonicas; novas peças viram stages proprios, nao mistura.
- Nao marque `status=passed` quando alguma stage canonica esta blocked.
- Sempre reuse os controllers canonicos via container; nao duplique runtime.

## Escopo de Implementacao

Implementado em:

- `app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php`
- `app/Http/Controllers/AtlasCodeForgeFastPathController.php`
- `app/Console/Commands/AtlasCodeForgeFastPathCommand.php`
- `tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php`
- `routes/api.php` (rota POST registrada)
- `atlas-desktop/.../surfaces/code/panels/ForgeFastPathPanel.tsx` (UI)
- `atlas-desktop/.../lib/bridge.ts` (`runForgeFastPath`)

Bloco `forge_fast_path_certification` aparece em
`atlas:programming:completion-audit --json` ao lado de
`forge_runtime_certification`, `forge_live_execution_certification`,
`atlas_code_enterprise_certification` e `external_rivals_certification`.

## Dependencias

- `AtlasCodeProgrammingWorkItemController` — WorkItem create + compileSpecPlan.
- `AtlasCodeForgeExecutionController` — store (sync) + startAsync (async).
- `AtlasCodeCheckpointController` — checkpoint opcional.
- `ProgrammingGovernanceService` — intake/find/snapshot.
- `ProgrammingSpecCompiler` + `PlanCompiler` + `TaskCompiler`.

## Evidencias

- `php artisan atlas:code:forge-fast-path --json --strict` (exit 1 sem obra).
- `php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict` (exit 0).
- `tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php` (5 testes).
- `atlas:programming:completion-audit --json`
  `forge_fast_path_certification.status` em `available` apos artefatos+docs+coverage.

## Riscos

- Fast Path mascarar problemas reais de algum controller canonico.
- Adicionar Obra silenciosa por engano de "facilitar UX".
- Marcar passed sem execution_id em async.

## Exemplos

Sem Obra (fail-closed):

```json
{
  "status": "blocked",
  "obra_id": null,
  "stages": [{"name":"obra_binding","status":"blocked","blocker":"obra_required"}],
  "blockers": ["obra_required"],
  "next_action": "provide_obra_id"
}
```

Async com Obra existente:

```json
{
  "status": "queued",
  "obra_id": "<uuid>",
  "work_item_code": "OTH-XXXXXXXX",
  "task_count": 2,
  "execution_id": "<ulid>",
  "next_action": "poll_async_execution_and_open_review_when_passed",
  "stages": [/* 8 stages all passed */]
}
```

## Run Lifecycle v2

Cada Fast Path emite um `fast_path_run_id` ULID. Estados canonicos:

```text
prepared
queued → running → passed → review_required → completed
                  ↘ degraded → repair available → failed/blocked
```

Persistencia canonica (read-only via state endpoint):

- `AtlasProject.metadata.latest_atlas_code_forge_fast_path_run` (objeto v1)
- `AtlasProject.metadata.atlas_code_forge_fast_path_run_history` (ate 25 entradas)

Schema do run: `atlas.code.forge_fast_path_run.v1` (subset profissional do Fast Path report).

## Polling Endpoint

```text
GET /atlas-code/works/{project}/forge/fast-path/{run}/status
```

Schema: `atlas.code.forge_fast_path_run_status.v1`. Reconstroi o estado real cruzando metadata + execution_async_runs + latest_forge_live_execution + reviews. Fail-closed se `run` nao pertence a Obra (`fast_path_run_not_found` 404, `fast_path_run_obra_mismatch` 403). Nunca sintetiza sucesso.

CLI equivalente:

```bash
php artisan atlas:code:forge-fast-path-status --obra=<uuid> --run=<run> --json --strict
```

## Resume Endpoint

```text
POST /atlas-code/works/{project}/forge/fast-path/{run}/resume
```

Idempotente: reidrata o run a partir do estado persistido. NAO recria WorkItem nem execucao quando ja existem. Retorna o mesmo shape do `status` + `next_action` claro:

- `wait_for_async_execution`
- `open_human_review`
- `run_repair`
- `approve_completion`
- `inspect_blocker`
- `completed`

## Review Gate

O Fast Path nao declara `completed` automaticamente:

- Quando `latest_forge_live_execution.status=passed` e nao ha review, status vira `review_required`.
- `review_gate.review_required=true`, `review_status=pending`.
- `approval_api` retorna `POST /atlas-code/works/{obra}/forge/reviews`.
- `no_auto_completion_without_review=true` e invariante canonico.

## Repair Path

Quando a execucao fica `degraded`/`failed`:

- `repair.repair_available=true`
- `repair.repair_loop_status` reflete o estado real (`skipped_not_needed` / `pending` / `passed` / `blocked`).
- `repair.failure_packet` e exposto quando disponivel; caso contrario `fail_closed_without_evidence=true`.
- `suggested_repair_command` sugere `atlas:forge:live-execute --simulate-failure` para validar repair plan via `ProgrammingRepairExecutor`.

## Completion Audit (v2)

`forge_fast_path_certification` agora tambem expoe `lifecycle_invariants`:

- `run_lifecycle_available`
- `polling_endpoint_registered`
- `resume_endpoint_registered`
- `cli_status_command_registered`
- `review_gate_integrated`
- `repair_path_exposed`
- `no_auto_completion_without_review`
- `state_projection_available`

Schema da certificacao: `atlas.code.forge_fast_path_certification.v2`. Continua separado de `external_rivals_certification` — nunca substitui benchmark externo.

## Proximas Acoes

1. Manter este doc sincronizado com novos stages do Fast Path e novos invariantes do lifecycle.
2. Rodar `atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict` apos qualquer alteracao em controllers orquestrados.
3. Rodar `atlas:code:forge-fast-path-status --obra=<uuid> --run=<run> --json --strict` para validar status/resume.
4. Manter `external_rivals_certification` separado.
