---
id: atlas-forge-live-execution-e2e-v1
type: engineering_knowledge
title: Atlas Forge Live Execution E2E v1
status: active
category: programming-forge
priority: 100
summary: Certificacao executavel do Forge runtime — prova replayable de que a cadeia Atlas Code -> Obra -> Forge Workspace -> programming.forge conduz uma execucao real controlada (patch, harness, repair, evidence) sem provider externo.
tags:
  - atlas
  - forge
  - programming
  - certification
  - live-execution
capabilities:
  - forge_live_execution
  - sandbox_real_provisioning
  - patch_fixture_apply
  - harness_real_test_run
  - repair_loop_plan
  - evidence_ledger_recording
decisions:
  - Live Execution E2E v1 prova execucao real local controlada sem chamar provider externo.
  - Patch e teste sao fixtures determinísticos; nao substituem provider real para producao.
  - Sandbox sempre limpa apos execucao; rollback receipt obrigatorio.
  - Evidence Ledger e gravado quando a tabela atlas_ledger_events existe; degrade honesto quando ausente.
  - Repair loop usa ProgrammingRepairExecutor canonico; nao implementa retry solto.
maintenance:
  - Atualize quando harness/sandbox/patch verifier/repair executor/Evidence Ledger mudarem.
  - Rode atlas:forge:live-execute --json --strict apos qualquer alteracao nesses componentes.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php
  - app/Console/Commands/AtlasForgeLiveExecuteCommand.php
  - tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-live-execution-e2e-v1
graph_title: Atlas Forge Live Execution E2E v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md
  - app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php
  - app/Console/Commands/AtlasForgeLiveExecuteCommand.php
  - tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php
allowed_changes:
  - Adicionar stages canonicos ao live execution quando o pipeline real evoluir.
  - Adicionar evidence types ao Evidence Ledger registrados pelo comando.
forbidden_changes:
  - Chamar provider externo (Claude/Codex/Gemini) sem aprovacao explicita.
  - Skippar rollback do sandbox apos execucao.
  - Marcar live_execution como passed sem todos os stages canonicos verdes ou degraded honesto.
depends_on:
  - atlas-programming-forge-flow
  - atlas-forge-runtime-certification-one-shot
flows_to:
  - atlas-forge-operating-system
  - atlas-programming-governance-system
unlocks:
  - forge-live-execution-replayable
  - forge-runtime-real-v1
governs:
  - forge-live-execution
evidence:
  - docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md
  - app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php
  - tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php
required_tests:
  - "php artisan test --filter AtlasForgeLiveExecutionTest"
  - "php artisan atlas:forge:live-execute --json --strict"
requires_evidence: true
risk_level: high
visual_tags:
  - forge
  - live-execution
  - certification
ai_entrypoints:
  - Leia este doc antes de alterar AtlasForgeLiveExecutionService, sandbox manager, patch verifier, repair executor ou Evidence Ledger.
ai_usage_notes:
  - Nao declare live_execution passed sem rodar o comando atlas:forge:live-execute --json --strict.
quality_gates:
  - live-execution-passed
  - sandbox-cleanup-confirmed
  - ledger-events-recorded-or-degraded
failure_modes:
  - Sandbox nao limpa workspace (vazamento de tmp).
  - Patch verifier passa sem manifest coverage.
  - Repair loop dispara sem failure packet valido.
  - Evidence Ledger silenciosamente nao grava sem reportar degraded.
observability_signals:
  - forge_live_execution_status
  - sandbox.provisioning_mode
  - ledger_event_ids
  - evidence_refs
next_actions:
  - Manter este doc sincronizado com AtlasForgeLiveExecutionService.
---
# Atlas Forge Live Execution E2E v1

## Resumo

Certificacao executavel do Forge runtime. Prova replayable, sem provider
externo, de que o Forge conduz uma execucao real local controlada do inicio
ao fim:

```text
Atlas Code intent
-> Obra fixture
-> Forge Workspace binding
-> programming.forge
-> sandbox real (storage/app/forge-live-exec-tmp)
-> action manifest canonico
-> patch fixture aplicado em disco real
-> ProgrammingPatchVerifier::verify (real)
-> teste real via Symfony Process (php -r)
-> ProgrammingStageReceiptStore::make (real)
-> ProgrammingRepairExecutor::attemptPlan (real, quando aplicavel)
-> AtlasEvidenceLedger::record em atlas_ledger_events (real, quando tabela presente)
-> sandbox_rollback com cleanup do workspace
-> relatorio JSON replayable
```

## Papel no Atlas

Este doc define o **Nivel 2** da certificacao Forge. O Nivel 1 (forge_core)
prova binding/contract/governance. O Nivel 2 prova execucao.

Live Execution NAO substitui rodadas reais com provider externo (Claude/Codex/
Gemini). Ele garante que o pipeline executor existe, conecta os componentes
canonicos, escreve em disco, roda comandos, registra evidencia e limpa.

## Onde Se Encaixa

Filho de `atlas-programming-forge-flow.md`. Complementar a
`atlas-forge-runtime-certification-one-shot.md`. Usa os componentes canonicos
declarados em `programming-professional-rag-operating-standard.md`.

## Contratos

- **Obra e obrigatoria.** Sem `--obra=<uuid>`, o comando falha fechado:
  - `forge_live_execution_status=blocked`
  - stage `obra_binding` com `status=blocked`, `blocker=obra_required`
  - `remaining_blockers` inclui `obra_required`
  - sandbox NAO e provisionado, patch NAO e aplicado, test NAO roda
  - sem `--obra` em `--strict`, o exit code e non-zero (1)
  - o comando NUNCA gera Obra UUID silenciosamente
- `atlas:forge:live-execute --obra=<uuid> --json --strict` deve retornar
  `forge_live_execution_status=passed` apos toda alteracao em harness,
  sandbox, patch verifier, repair executor ou Evidence Ledger.
- **Context Pack minimo** nao pode ter `ranked_refs` vazio:
  - `context_completeness=canonical_minimum` quando todos os refs estao presentes
  - `canonical_minimum_partial` quando faltam refs canonicos
  - cada ref carrega `path`, `kind`, `reason`, `evidence_marker`, `content_hash`
- **Repair Loop** tem estados canonicos distintos:
  - `skipped_not_needed` — teste passou, repair nao foi acionado
  - `passed` — repair acionado, plan `planned` retornado pelo `ProgrammingRepairExecutor`
  - `degraded` — repair acionado mas plan retornou estado nao-canonico
  - `blocked` — `blocked_no_progress` ou `blocked_max_attempts`
- **Completion Audit nao e benchmark externo.** O bloco
  `forge_live_execution_certification` em
  `atlas:programming:completion-audit --json` distingue
  `available` (artefatos + cobertura presente), `requires_operator_run` (faltam
  metodos de teste canonicos) e `missing_artifacts`. `passed` so e admitido
  apos evidencia recente de operador. Nada disso e bateria provider paga.
- Nenhum stage canonico pode ser pulado (11 stages obrigatorios com Obra).
- Sandbox rollback e obrigatorio com workspace cleanup confirmado.
- `external_provider_call` deve ser `false`.
- Evidence Ledger grava 4 eventos canonicos quando a tabela existe:
  `EXECUTION_STARTED`, `GATE_EVALUATED`, `EVIDENCE_PACKED`,
  `OPERATION_COMPLETED` (ou `OPERATION_BLOCKED`).

## Fluxo

Stages canonicos do comando:

| # | Stage | Componente real | Output minimo |
|---|---|---|---|
| 1 | `obra_binding` | UUID Obra + forge_workspace binding | obra_id, forge_workspace.* (sem obra: status=blocked, blocker=obra_required) |
| 2 | `sandbox_provision` | `ProgrammingSandboxManager::provision()` | mode, workspace_hash, execution_workspace |
| 3 | `context_pack` | retrieval plan + context pack hash | context_pack_hash, retrieval_receipt.receipt_id |
| 4 | `patch_apply` | `File::put` em sandbox | patch_target_hash, content_hash, changed_files |
| 5 | `action_manifest` | manifest canonico v1 | manifest_id, stage=patch, rollback.available=true |
| 6 | `patch_verifier` | `ProgrammingPatchVerifier::verify()` | report.status, blocking_reasons |
| 7 | `test_run` | Symfony Process real | exit_code, stdout_hash, passed |
| 8 | `stage_receipts` | `ProgrammingStageReceiptStore::make()` | 2 receipts (patch + test) |
| 9 | `repair_loop` | `ProgrammingRepairExecutor::attemptPlan()` quando teste falha | plan.status, repair_capsule |
| 10 | `evidence_ledger` | `AtlasEvidenceLedger::record()` x4 | ledger_event_ids |
| 11 | `sandbox_rollback` | `rollbackReceipt()` + `File::deleteDirectory` | workspace_cleaned=true |

## Regras para IA

- **Nao gere Obra UUID silenciosamente quando o operador nao passou `--obra`.**
  Falhe fechado em `obra_binding` com `obra_required`.
- Nao chame provider externo (Claude/Codex/Gemini) deste comando.
- Nao puxe LLM para gerar patch — use fixture determinístico.
- Nao silencie `evidence_ledger=degraded`: se a tabela `atlas_ledger_events`
  nao existe, retorne degraded honesto.
- Nao salte rollback do sandbox.
- Nao marque `repair_loop` como `passed` se ele nao foi acionado — use
  `skipped_not_needed`.
- Nao marque `context_pack` como `passed` com `ranked_refs` vazio.
- Cada novo componente Forge deve aparecer em um stage proprio (nao misturar).

## Escopo de Implementacao

Implementado em:

- `app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php`
- `app/Console/Commands/AtlasForgeLiveExecuteCommand.php`
- `tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php`

Bloco `forge_live_execution_certification` aparece em
`atlas:programming:completion-audit --json` ao lado de
`forge_runtime_certification` e `external_rivals_certification`.

## Dependencias

- `ProgrammingSandboxManager` — sandbox real.
- `ProgrammingPatchVerifier` — verifica patch + manifests.
- `ProgrammingRepairExecutor` — plano de repair canonico.
- `ProgrammingStageReceiptStore` — stage receipts.
- `AtlasEvidenceLedger` + `LedgerEventType` — eventos.

## Evidencias

- `tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php` (5 testes).
- `php artisan atlas:forge:live-execute --json --strict` (exit 0).
- `php artisan atlas:programming:completion-audit --json`
  (`forge_live_execution_certification.status=available`).

## Riscos

- Comando passar mas sandbox nao limpar (vazamento).
- Comando passar com `evidence_ledger=degraded` silencioso.
- Patch fixture nao representar patch real LLM (esperado, mas declarar).
- Mudancas em sandbox/harness/repair nao acionarem este comando.

## Exemplos

Saida tipica passed:

```json
{
  "forge_live_execution_status": "passed",
  "external_provider_call": false,
  "stages": ["obra_fixture ... sandbox_rollback (11)"],
  "ledger_event_ids": ["<4 ulids>"],
  "evidence_refs": ["<receipt_ids>"],
  "remaining_blockers": []
}
```

## Proximas Acoes

1. Manter este doc sincronizado com cada novo stage canonico do live execution.
2. Rodar `atlas:forge:live-execute --json --strict` antes de promover qualquer
   alteracao em harness, sandbox, patch verifier, repair executor ou Evidence
   Ledger.
3. Garantir que o eixo `external_rivals_certification` continue separado.
