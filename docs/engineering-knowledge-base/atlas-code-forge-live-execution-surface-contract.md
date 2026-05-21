---
id: atlas-code-forge-live-execution-surface-contract
type: engineering_knowledge
title: Atlas Code Forge Live Execution Surface Contract
status: active
category: surface
priority: 100
summary: Contrato da acao de produto que permite ao Atlas Code SCOR-1 acionar Forge Live Execution por Obra, persistir async/snapshot/history/review/evidence/checkpoint e refletir o resultado nos paineis Plan, Verify e Evidence.
tags:
  - atlas-code
  - forge
  - live-execution
  - surface-contract
capabilities:
  - atlas_code_forge_live_execution
  - forge_workspace_banner
  - live_execution_snapshot
  - live_execution_history
  - forge_review_gate
  - forge_review_history
  - forge_history_replay
  - forge_async_execution
  - forge_stage_timeline
  - forge_evidence_pack
  - forge_task_queue
  - forge_work_item_binding
  - forge_spec_plan_binding
decisions:
  - Atlas Code SCOR-1 tem um unico modo operacional: Forge.
  - Forge Live Execution pela surface exige Obra vinculada.
  - A surface nao cria UUID silencioso; sem Obra, mostra `forge_workspace_blocker.v1`.
  - O endpoint de produto e `POST /atlas-code/works/{obra_id}/forge/live-executions`.
  - O estado renderizavel reaparece em `GET /atlas-code/works/{obra_id}/state` como `forge_live_execution`.
  - Context Pack detalhado e renderizado com refs rankeadas, hash e evidence marker.
  - Stage Timeline e derivada dos stages reais do Forge Live Execution e renderizada no Verify.
  - Evidence Pack materializa receipts, ledger events, replay commands e hashes; contadores sozinhos nao bastam.
  - Task Contract e artefato canonico da Obra: objetivo, arquivos permitidos, comandos de validacao, aceite, rollback e evidencia obrigatoria.
  - Forge Task Queue e read-model canonico da Obra: usa `programming_governance.tasks_json` quando existir, ou o `task_contract` da ultima execucao Forge real; nunca inventa tarefas.
  - Atlas Code pode criar/vincular um WorkItem real de Programming Governance para a Obra; o endpoint e idempotente e nao duplica WorkItem.
  - Atlas Code pode compilar/anexar Spec, Plan e Tasks reais ao WorkItem; sem binding ou contexto suficiente, falha fechado.
  - Forge Live Execution sincroniza evidence e gates de volta no WorkItem quando existe fila governada.
  - Diff/Scope Guard e derivado do patch_apply, action_manifest e patch_verifier reais.
  - Forge Run History e timeline curta de execucoes reais por Obra; nao e memoria de chat.
  - Human Review Gate aprova ou rejeita completion para um run Forge especifico, sempre com comentario de operador.
  - Human Review Ledger e read-model obrigatorio: review nao e so botao, e trilha auditavel por run.
  - Run History Replay e read-only; consulta artefatos historicos, hashes e review relacionado sem reexecutar provider.
  - Execucao assincrona enfileira job real, expoe polling por execution_id e separa aceite de request da conclusao do runtime.
  - Checkpoint/Resume e artefato persistente da Obra, nao memoria solta do chat.
maintenance:
  - Atualizar junto com AtlasCodeForgeExecutionController, WorkStateSnapshot, bridge e ForgeWorkspaceBanner.
related_paths:
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md
  - docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - app/Http/Controllers/AtlasCodeForgeExecutionController.php
  - app/Jobs/AtlasCodeForgeLiveExecutionJob.php
  - app/Http/Controllers/AtlasCodeForgeReviewController.php
  - app/Http/Controllers/AtlasCodeProgrammingWorkItemController.php
  - app/Http/Controllers/AtlasCodeWorkController.php
  - tests/Feature/AtlasCodeContractTest.php
  - app/Http/Controllers/AtlasCodeCheckpointController.php
  - app/Services/Ai/Programming/AtlasCodeEnterpriseCertificationService.php
  - ../atlas-desktop/packages/atlas-domain/src/index.ts
  - ../atlas-desktop/apps/desktop/src/lib/bridge.ts
  - ../atlas-desktop/apps/desktop/src/hooks/useBridge.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeWorkspaceBanner.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeExecutionHistoryPanel.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeRunReplayInspectorPanel.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeAsyncExecutionPanel.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeStageTimelinePanel.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeEvidencePackPanel.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeTaskQueuePanel.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeReviewGate.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeReviewHistoryPanel.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/TaskContractPanel.tsx
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-forge-live-execution-surface-contract
graph_title: Atlas Code Forge Live Execution Surface Contract
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-code-scor-1-implementation-contract
graph_status: active
graph_source: repo
human_name: Atlas Code Forge Live Execution Surface Contract
canonical_name: Atlas Code Forge Live Execution Surface Contract
technical_name: atlas-code-forge-live-execution-surface-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md
  - app/Http/Controllers/AtlasCodeForgeExecutionController.php
  - app/Http/Controllers/AtlasCodeCheckpointController.php
  - tests/Feature/AtlasCodeContractTest.php
allowed_changes:
  - Evoluir snapshot, endpoint e banner quando o Forge Live Execution evoluir.
forbidden_changes:
  - Permitir execucao Forge sem Obra.
  - Exibir sucesso sem snapshot real vindo do backend.
depends_on:
  - atlas-desktop-code-surface
  - atlas-code-scor-1-implementation-contract
  - atlas-forge-live-execution-e2e-v1
flows_to:
  - atlas-code
  - atlas-programming-forge-flow
unlocks:
  - atlas-code-product-facing-live-execution
governs:
  - atlas-code.forge-live-execution
evidence:
  - app/Http/Controllers/AtlasCodeForgeExecutionController.php
  - tests/Feature/AtlasCodeContractTest.php
required_tests:
  - "php artisan test --filter AtlasCodeContractTest"
  - "cd ../atlas-desktop && npm run build --workspace=@atlas/desktop"
  - "cd ../atlas-desktop && npm run lint --workspace=@atlas/desktop"
requires_evidence: true
risk_level: high
next_actions:
  - Manter endpoint, snapshot, bridge e banner sincronizados com Forge Live Execution.
---
# Atlas Code Forge Live Execution Surface Contract
## Resumo
Este doc governa a ponte de produto entre Atlas Code SCOR-1 e Forge Live
Execution. A CLI `atlas:forge:live-execute` prova o runtime; este contrato
prova que a surface consegue acionar esse runtime por Obra e renderizar o
resultado sem mock.
## Papel no Atlas
Atlas Code e a cabine desktop; o Programming Domain continua sendo o setor de
programacao. Esta ponte transforma a Obra selecionada em execucao local
certificada do Forge sem provider externo.
## Onde Se Encaixa
Fica entre a surface `atlas_code`, o endpoint `/atlas-code/works/*` e
`AtlasForgeLiveExecutionService`. O resultado volta para os paineis Plan,
Verify e Evidence por `WorkStateSnapshot.forgeLiveExecution`.
## Contratos
- A Obra selecionada e a unidade de execucao: `obra_id == work_id`.
- Sem Obra, a UI exibe `forge_workspace_blocker.v1`; nao chama endpoint.
- Com Obra, a UI pode chamar `POST /atlas-code/works/{obra_id}/forge/live-executions`.
- O controller chama `AtlasForgeLiveExecutionService` com `obra_id`.
- O resultado persiste `AtlasEngineeringRun`, `AtlasEngineeringEvidence` quando as tabelas existem.
- O snapshot fica em `AtlasProject.metadata.latest_forge_live_execution`.
- O estado async fica em `AtlasProject.metadata.latest_forge_live_execution_async`.
- O binding do WorkItem fica em `AtlasProject.metadata.programming_work_item_id` e `programming_work_item_code`.
- O historico curto fica em `AtlasProject.metadata.atlas_code_forge_live_execution_history`.
- `stage_timeline` e snapshot materializado dos stages do report: fase, status, blocker e resumo por stage.
- `evidence_pack` e snapshot materializado de receipts, ledger events, replay, persistence e integrity hashes.
- Cada entrada do historico carrega `evidence_pack_digest`; runs antigos continuam auditaveis sem depender do ultimo snapshot.
- Review humano fica em `AtlasProject.metadata.latest_atlas_code_forge_review`.
- Historico de review fica em `AtlasProject.metadata.atlas_code_forge_review_history`.
- Checkpoint fica em `AtlasProject.metadata.latest_atlas_code_checkpoint`.
- `GET /atlas-code/works/{obra_id}/state` retorna `forge_live_execution`.
- `GET /atlas-code/works/{obra_id}/state` retorna `programming_governance` quando ha WorkItem real vinculado.
- `GET /atlas-code/works/{obra_id}/state` retorna `forge_task_queue`.
- `POST /atlas-code/works/{obra_id}/programming/work-items` cria ou reutiliza WorkItem real via `ProgrammingGovernanceService::intake`.
- `POST /atlas-code/works/{obra_id}/programming/work-items/{work_item}/spec` usa `ProgrammingSpecCompiler`, `PlanCompiler`, `TaskCompiler`, `attachSpec` e `attachPlan`.
- `GET /atlas-code/works/{obra_id}/state` retorna `forge_live_execution_async`.
- `GET /atlas-code/works/{obra_id}/state` retorna `forge_live_execution_history`.
- `GET /atlas-code/works/{obra_id}/state` retorna `forge_review`.
- `GET /atlas-code/works/{obra_id}/state` retorna `forge_review_history`.
- `GET /atlas-code/works/{obra_id}/state` retorna `checkpoint`.
- `POST /atlas-code/works/{obra_id}/forge/reviews` aceita `approved` ou `rejected`; aprovacao falha fechado se o run nao passou ou completion gate nao permite.
- `GET /atlas-code/works/{obra_id}/forge/live-executions/history/{history_id}` retorna replay packet read-only; history inexistente retorna 404 e Obra divergente retorna 403.
- `POST /atlas-code/works/{obra_id}/forge/live-executions/async` retorna 202 `queued` e despacha `AtlasCodeForgeLiveExecutionJob`.
- `GET /atlas-code/works/{obra_id}/forge/live-executions/{execution_id}` retorna status pollable.
- A surface deve chamar o polling enquanto o status estiver `queued` ou `running`; `queued` nunca e completion.
- `forge_task_queue.source_authority=programming_governance.tasks_json` tem precedencia sobre fallback de live execution.
- `forge_task_queue.source_authority=latest_forge_live_execution.task_contract` so existe quando o ultimo snapshot carrega `task_contract`.
- `forge_task_queue.entries[*].completion_claim_allowed` vem do completion gate/diff scope ou do estado fechado da governanca; nao vem de texto de chat.
## Fluxo

```text
Atlas Code Obra selecionada
-> ForgeWorkspaceBanner
-> POST /atlas-code/works/{obra_id}/programming/work-items quando nao houver WorkItem
-> ProgrammingGovernanceService::intake
-> AtlasProject.metadata.programming_work_item_id
-> POST /atlas-code/works/{obra_id}/programming/work-items/{work_item}/spec
-> Spec/Plan/Tasks persistidos em atlas_programming_work_items
-> POST /atlas-code/works/{obra_id}/forge/live-executions
-> ou POST /atlas-code/works/{obra_id}/forge/live-executions/async
-> AtlasCodeForgeLiveExecutionJob quando async
-> GET /atlas-code/works/{obra_id}/forge/live-executions/{execution_id} ate terminal
-> AtlasForgeLiveExecutionService
-> stage_timeline artifact
-> evidence_pack artifact
-> programming_governance_feedback artifact
-> context_pack artifact
-> task_contract artifact
-> forge_task_queue read-model
-> diff_scope artifact
-> checkpoint artifact quando solicitado
-> engineering run/evidence
-> latest_forge_live_execution
-> atlas_code_forge_live_execution_history
-> forge_review artifact quando operador aprova/rejeita
-> atlas_code_forge_review_history
-> GET replay historico read-only quando usuario inspeciona run antigo
-> WorkStateSnapshot.forgeLiveExecution
-> WorkStateSnapshot.forgeTaskQueue
-> WorkStateSnapshot.forgeLiveExecutionAsync
-> WorkStateSnapshot.forgeLiveExecutionHistory
-> WorkStateSnapshot.forgeReview
-> WorkStateSnapshot.forgeReviewHistory
-> Plan / Verify / Evidence
```

## Shape

```json
{
  "schema_version": "atlas.code.forge_live_execution_response.v1",
  "work_id": "obra-id",
  "report": { "forge_live_execution_status": "passed" },
  "snapshot": {
    "schema_version": "atlas.code.forge_live_execution.snapshot.v1",
    "status": "passed",
    "obra_id": "obra-id",
    "command": "php artisan atlas:forge:live-execute --obra=obra-id --json",
    "strict_command": "php artisan atlas:forge:live-execute --obra=obra-id --json --strict",
    "context_pack": {
      "schema_version": "atlas.code.context_pack_artifact.v1",
      "context_completeness": "canonical_minimum",
      "ranked_refs": [{ "rank": 1, "path": "docs/...", "evidence_marker": "present" }]
    },
    "stage_timeline": {
      "schema_version": "atlas.code.forge_live_execution.stage_timeline.v1",
      "total": 11,
      "passed": 10,
      "blocked": 0,
      "degraded": 0,
      "skipped": 1,
      "blocking": 0,
      "entries": [{
        "index": 7,
        "name": "test_run",
        "phase": "verify",
        "status": "passed",
        "blocking": false,
        "blocker": null,
        "summary": "test exit 0 | passed true"
      }]
    },
    "evidence_pack": {
      "schema_version": "atlas.code.forge_live_execution.evidence_pack.v1",
      "status": "passed",
      "stage_receipt_count": 2,
      "stage_receipts": [{ "stage": "patch", "status": "passed", "receipt_id": "sha256..." }],
      "ledger_event_count": 4,
      "ledger_events": [{ "event_id": "evt...", "source": "atlas_ledger_events" }],
      "changed_files": ["forge-live-execution-fixture.txt"],
      "replay": {
        "command": "php artisan atlas:forge:live-execute --obra=obra-id --json",
        "strict_command": "php artisan atlas:forge:live-execute --obra=obra-id --json --strict",
        "external_provider_call": false
      },
      "persistence": {
        "engineering_run_persisted": true,
        "engineering_evidence_persisted": true
      },
      "integrity": {
        "report_hash": "sha256...",
        "stage_timeline_hash": "sha256...",
        "evidence_pack_hash": "sha256..."
      }
    },
    "repair_loop": { "status": "skipped_not_needed", "triggered": false },
    "task_contract": {
      "schema_version": "atlas.code.task_contract_artifact.v1",
      "status": "verified",
      "objective": "Executar cadeia Forge Live local...",
      "allowed_files": ["forge-live-execution-fixture.txt"],
      "validation_commands": ["php -r \"...\""],
      "acceptance_criteria": ["patch_apply.status=passed"],
      "rollback": { "available": true },
      "evidence_required": ["stage_receipts", "evidence_pack", "evidence_refs", "ledger_event_ids", "diff_scope"]
    },
    "diff_scope": {
      "schema_version": "atlas.code.diff_scope_artifact.v1",
      "status": "passed",
      "scope_status": "in_scope",
      "changed_file_count": 1,
      "completion_gate": { "status": "passed", "completion_claim_allowed": true }
    },
    "stage_count": 11,
    "evidence_ref_count": 2,
    "ledger_event_count": 4,
    "remaining_blockers": []
  },
  "forge_live_execution_async": {
    "schema_version": "atlas.code.forge_live_execution.async.v1",
    "execution_id": "01...",
    "status": "queued",
    "job_dispatched": true,
    "snapshot_status": null,
    "completion_claim_allowed": false,
    "updated_at": "2026-05-14T00:00:00Z"
  },
  "checkpoint": {
    "schema_version": "atlas.code.checkpoint_artifact.v1",
    "status": "ready",
    "resume": { "next_safe_action": "resume_from_verified_forge_live_execution" }
  },
  "forge_review": {
    "schema_version": "atlas.code.forge_review_artifact.v1",
    "status": "approved",
    "decision": "approved",
    "comment": "local operator review",
    "approval_effective": true,
    "review_gate": {
      "completion_claim_allowed": true,
      "human_approved": true,
      "final_completion_allowed": true,
      "blockers": []
    }
  },
  "forge_live_execution_history": {
    "schema_version": "atlas.code.forge_live_execution_history.v1",
    "source_authority": "AtlasProject.metadata.atlas_code_forge_live_execution_history",
    "total": 2,
    "entries": [{
      "schema_version": "atlas.code.forge_live_execution.history_entry.v1",
      "status": "degraded",
      "simulate_failure": true,
      "repair_triggered": true,
      "task_contract_status": "needs_review",
      "diff_scope_status": "blocked",
      "completion_claim_allowed": false,
      "evidence_pack_digest": {
        "schema_version": "atlas.code.forge_live_execution.evidence_pack_digest.v1",
        "stage_receipt_count": 2,
        "ledger_event_count": 4,
        "evidence_pack_hash": "sha256..."
      }
    }]
  },
  "programming_work_item_binding": {
    "schema_version": "atlas.code.programming_work_item_binding_response.v1",
    "status": "bound",
    "created": true,
    "binding": {
      "surface_id": "atlas_code",
      "flow_id": "programming.forge",
      "work_item_id": "uuid",
      "work_item_code": "FEA-..."
    }
  },
  "forge_task_queue": {
    "schema_version": "atlas.code.forge_task_queue.v1",
    "obra_id": "obra-id",
    "source_authority": "latest_forge_live_execution.task_contract",
    "total": 1,
    "ready_count": 0,
    "blocked_count": 0,
    "verified_count": 1,
    "needs_review_count": 0,
    "entries": [{
      "schema_version": "atlas.code.forge_task_queue_entry.v1",
      "task_id": "forge-live-execution-task",
      "status": "verified",
      "allowed_files": ["forge-live-execution-fixture.txt"],
      "validation_commands": ["php -r \"...\""],
      "evidence_required": ["stage_receipts", "evidence_pack"],
      "run_history_id": "history-id",
      "evidence_pack_hash": "sha256...",
      "stage_timeline_hash": "sha256...",
      "completion_claim_allowed": true
    }]
  },
  "forge_review_history": {
    "schema_version": "atlas.code.forge_review_history.v1",
    "source_authority": "AtlasProject.metadata.atlas_code_forge_review_history",
    "total": 1,
    "entries": [{
      "schema_version": "atlas.code.forge_review_history_entry.v1",
      "decision": "approved",
      "status": "approved",
      "live_execution_status": "passed",
      "final_completion_allowed": true,
      "stage_receipt_count": 2,
      "ledger_event_count": 4,
      "evidence_pack_hash": "sha256..."
    }]
  }
}
```

## Surface

`ForgeWorkspaceBanner` aparece em Plan, Verify e Evidence. `ForgeAsyncExecutionPanel`
inicia async, mostra `updated_at`, permite refresh manual e auto-poll de queued/running.
`ContextPackPanel`, `TaskContractPanel` e `CheckpointPanel` aparecem em Plan.
`ForgeTaskQueuePanel` aparece em Plan e mostra a fila canonica da Obra: cria
WorkItem quando a Obra ainda nao tem binding, depois mostra counts
ready/blocked/review/verified, fonte de autoridade, WorkItem ou run historico,
spec/plan hash, botao de gerar Spec/Plan, arquivos permitidos, comandos, evidencia, blockers e hashes quando existirem.
`ForgeStageTimelinePanel`, `DiffScopeGuard` e `ForgeReviewGate` aparecem em Verify.
`ForgeReviewGate` exige decisao explicita (`approved` ou `rejected`) e comentario editavel.
`ForgeExecutionHistoryPanel` mostra digest historico do Evidence Pack e permite
inspecionar um run antigo. `ForgeRunReplayInspectorPanel` renderiza o replay
read-only selecionado: history entry, evidence digest, timeline digest,
snapshot disponivel, review vinculado e comando de replay. `ForgeReviewHistoryPanel`
mostra o ledger de decisoes humanas por run; `ForgeEvidencePackPanel`
mostra o pack completo do ultimo snapshot. Todos usam somente `RightRailContext` e snapshot real.

## Regras para IA

- Nao inferir Obra a partir de WorkItem de governance.
- Nao inventar fila: `ForgeTaskQueuePanel` so renderiza `forge_task_queue` do backend.
- Nao criar WorkItem duplicado para a mesma Obra; endpoint de binding e idempotente.
- Nao rebaixar WorkItem real a fallback de task contract; `programming_governance.tasks_json` tem precedencia.
- Nao gerar status fake quando `forge_live_execution` e `null`.
- Nao tratar async `queued` ou `running` como execucao concluida.
- Nao deixar async sem polling/refresh visivel na Surface.
- Nao inferir progresso por texto de chat; timeline vem de `report.stages`.
- Nao reconstruir historico a partir de texto de conversa.
- Nao deixar run historico sem `evidence_pack_digest` quando o run veio do Forge Live Execution.
- Nao esconder refs `missing` ou context pack parcial.
- Nao reduzir evidencias a contadores; mostrar receipts, ledger IDs, replay e hashes quando existirem.
- Nao esconder `remaining_blockers`.
- Nao executar tarefa pesada sem WorkItem/task contract verificavel.
- Nao declarar completion se `diff_scope.completion_gate` bloquear.
- Nao declarar completion final sem `forge_review.review_gate.final_completion_allowed=true`.
- Nao transformar review humano em atalho automatico; operador precisa poder rejeitar e comentar.
- Nao esconder review history; aprovacao/rejeicao precisa sobreviver no read-model da Obra.
- Nao fazer replay historico reexecutar provider ou mutar workspace; replay e consulta read-only.
- Nao deixar endpoint de replay sem UI: run historico precisa ser inspecionavel pelo operador.
- Nao tratar checkpoint ausente como retomada confiavel.
- Nao confundir `forge_live_execution_certification` com benchmark externo.
- Manter o texto do comando pre-preenchido sincronizado com o endpoint.

## Escopo de Implementacao

Dentro: endpoint sync, endpoint async, endpoint polling, queue job, WorkItem binding por Obra, Spec/Plan/Tasks, governed execution por WorkItem, stage timeline artifact, evidence pack artifact, context pack artifact, task contract artifact, forge task queue read-model, diff/scope artifact, run history artifact, run replay read-only, run replay inspector, human review artifact, review ledger artifact, checkpoint/resume artifact, persistence snapshot/evidence, bridge/Tauri, hook e paineis.
Fora: provider externo, benchmark Rivals, multiagent real e streaming completo.

## Dependencias

- `atlas-forge-live-execution-e2e-v1.md`
- `AtlasForgeLiveExecutionService`
- `AtlasCodeWorkController`
- `WorkStateSnapshot`
- `ForgeWorkspaceBanner`

## Evidencias

- `AtlasCodeContractTest::test_atlas_code_can_run_forge_live_execution_for_work`.
- `AtlasCodeContractTest::test_atlas_code_keeps_forge_live_execution_history_for_work`.
- `AtlasCodeContractTest::test_atlas_code_exposes_programming_governance_as_forge_task_queue`.
- `AtlasCodeContractTest::test_atlas_code_can_create_and_bind_programming_work_item_for_obra`.
- `AtlasCodeContractTest::test_atlas_code_can_compile_spec_plan_and_queue_from_bound_work_item`.
- `AtlasCodeContractTest::test_atlas_code_blocks_spec_plan_for_unbound_work_item`.
- `AtlasCodeContractTest::test_atlas_code_can_approve_forge_run_with_human_review`.
- `AtlasCodeContractTest::test_atlas_code_can_reject_forge_run_with_human_review`.
- `AtlasCodeContractTest::test_atlas_code_replays_forge_run_history_read_only_with_review_context`.
- `AtlasCodeContractTest::test_atlas_code_blocks_forge_run_history_replay_on_obra_mismatch`.
- `AtlasCodeContractTest::test_atlas_code_can_start_and_complete_forge_live_execution_async`.
- `AtlasCodeContractTest::test_atlas_code_blocks_human_approval_for_degraded_forge_run`.
- `AtlasCodeContractTest::test_atlas_code_can_create_checkpoint_for_work_resume`.
- `AtlasCodeContractTest::test_atlas_code_enterprise_certification_proves_full_product_loop`.
- `AtlasCodeContractTest::test_atlas_code_enterprise_certification_api_exposes_product_proof_packet`.
- `POST/GET /atlas-code/certification` certifica e retorna a ultima certificacao persistida sem criar Obra nova na leitura.
- `/atlas-code/works/{obra}/state.atlas_code_enterprise_certification` reexpoe a ultima certificacao persistida.
- `/atlas-code/works` oculta Obras efemeras de certificacao para manter a lista operacional limpa.
- `php artisan atlas:code:enterprise-certify --json --strict`.
- `npm run build --workspace=@atlas/desktop`.
- `npm run lint --workspace=@atlas/desktop`.

## Riscos

- UI parecer executavel sem Obra.
- Snapshot antigo ser confundido com execucao atual.
- Timeline ser reconstruida por heuristica no desktop em vez de vir do backend.
- Evidence Pack ausente ser substituido por contadores sem receipts/ledger/replay.
- Historico virar log narrativo sem run/evidence id.
- Review virar estado efemero sem ledger auditavel.
- Replay historico reexecutar provider, escrever em disco ou ignorar mismatch de Obra.
- Aceitar `queued` como completion.
- Disparar async e nao conseguir consultar o mesmo `execution_id`.
- Aprovar run degradado ou diff bloqueado.
- Task sem arquivos permitidos ou criterio de aceite ser tratada como profissional.
- Fila mostrar tarefa que nao veio de WorkItem nem task contract real.
- Criar multiplos WorkItems para a mesma Obra por clique repetido.
- Diff fora de escopo ser exibido como completion permitido.
- Resume depender de memoria do chat em vez de checkpoint de Obra.
- Provider externo ser misturado com certificacao local.

## Exemplos

Com Obra, o botao `rodar forge live` retorna `status=passed`, `stage_timeline`
com 11 entries canonicas, `evidence_pack` com 2 stage receipts, 4 ledger events,
replay commands e hashes, `task_contract` verified, `forge_task_queue`
verified, `diff_scope` in-scope.
Cada run entra em `Forge Run History`; se `simulate_failure=true`, a entrada
fica `degraded`, `repair_triggered=true` e `completion_claim_allowed=false`.
`ForgeAsyncExecutionPanel` inicia job com 202 `queued`, consulta o endpoint
pollable e atualiza o snapshot quando o worker roda; o mesmo snapshot real e
history sao materializados. `ForgeReviewGate` aprova somente run `passed` com
completion gate liberado; em WorkItem governado, a aprovacao promove o
promotion artifact para o workspace vivo com hash check, rollback backup,
evidence e `live_workspace_mutated=true`. O mesmo gate executa rollback por
`POST /atlas-code/works/{obra_id}/forge/promotions/{promotion_id}/rollback`;
rollback valida drift/hash, restaura backup, grava evidence propria e marca o
review `rolled_back`. Run degradado retorna 409 e review `blocked`. `Forge
Review Ledger` preserva aprovacoes/rejeicoes/rollbacks com hashes; replay
historico consulta o run sem reexecutar provider. Sem Obra, a UI mostra blocker
e nao chama o endpoint.

## Proximas Acoes

1. Adicionar refresh/streaming do snapshot quando o executor deixar de ser sync.
2. Ligar futuro Scope Guard/Diff real ao mesmo banner, sem mudar a autoridade da Obra.
