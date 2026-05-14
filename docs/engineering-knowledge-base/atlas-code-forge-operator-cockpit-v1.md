---
id: atlas-code-forge-operator-cockpit-v1
type: engineering_knowledge
title: Atlas Code Forge Operator Cockpit v1
status: active
category: programming-forge
priority: 100
summary: Cockpit operacional unico no Atlas Code SCOR-1 que materializa Obra → Forge Workspace → Fast Path → Live Execution → Status/Polling → Repair/Resume → Evidence → Review → Completion Claim em uma surface enterprise sem auto-completion e sem provider externo.
tags:
  - atlas
  - atlas-code
  - forge
  - cockpit
  - operator
capabilities:
  - atlas_code_forge_operator_cockpit
  - fast_path_operator_actions
  - controlled_status_polling
  - review_actions_bound_to_run
  - completion_claim_visualization
  - read_only_repair_state
decisions:
  - Atlas Code SCOR-1 e Forge-only; cockpit fail-closed sem Obra.
  - UI nao inventa estado; renderiza apenas bridge/state/backend.
  - Completed so aparece quando completion_claim.final_completion_allowed=true.
  - Polling para em estados terminais (completed/review_required/blocked/rejected/rolled_back).
  - Repair UX e read_only nesta versao; backend nao expoe action de repair automatica.
maintenance:
  - Atualize quando Fast Path/Review/Completion ou Tauri commands mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - apps/desktop/src/surfaces/code/panels/ForgeOperatorCockpitPanel.tsx
  - apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx
  - apps/desktop/src/hooks/useBridge.ts
  - apps/desktop/src/lib/bridge.ts
  - crates/atlas-tauri/src/commands_bridge.rs
  - crates/atlas-tauri/src/lib.rs
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-forge-operator-cockpit-v1
graph_title: Atlas Code Forge Operator Cockpit v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-forge-fast-path-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md
allowed_changes:
  - Adicionar invariants/actions ao cockpit quando o backend canonico evoluir.
forbidden_changes:
  - Inventar estado ou completion na UI.
  - Chamar bridge direto no componente.
  - Mostrar completed sem final_completion_allowed.
  - Acionar repair automatico sem backend real.
  - Tocar em Rivals/Cartografia/self-construction/voice.
depends_on:
  - atlas-code-forge-fast-path-v1
  - atlas-code-forge-review-completion-gate-v1
flows_to:
  - atlas-code-forge-fast-path-v1
unlocks:
  - atlas-code-forge-operator-cockpit
governs:
  - atlas_code_forge_operator_cockpit
evidence:
  - docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md
  - apps/desktop/src/surfaces/code/panels/ForgeOperatorCockpitPanel.tsx
required_tests:
  - "php artisan atlas:programming:completion-audit --json"
  - "npm run lint --workspace=@atlas/desktop"
  - "npm run build --workspace=@atlas/desktop"
  - "cargo check -p atlas-tauri"
requires_evidence: true
risk_level: high
visual_tags:
  - forge
  - cockpit
  - operator
ai_entrypoints:
  - Leia este doc antes de alterar ForgeOperatorCockpitPanel, useBridge ou Tauri commands canonicos.
ai_usage_notes:
  - Cockpit deve sempre refletir estado do backend; nao adicione sucesso visual sem campo real.
quality_gates:
  - cockpit-uses-bridge-actions
  - status-polling-controlled
  - completion-only-with-final_allowed
  - tauri-commands-registered
failure_modes:
  - Botao approve habilitado sem runtime/evidence.
  - Completion visual mesmo sem final_completion_allowed.
  - Polling sem parar em estado terminal.
observability_signals:
  - lifecycle_status
  - current_stage
  - progress_percent
  - review_status
  - completion_status
next_actions:
  - Manter este doc sincronizado com novos invariants do cockpit.
---
# Atlas Code Forge Operator Cockpit v1

## Resumo

Cockpit unico no Atlas Code SCOR-1 que materializa o ciclo Obra → Forge
Workspace → Fast Path → Live Execution → Status/Polling → Repair/Resume →
Evidence → Review → Completion Claim em uma surface enterprise operavel.

## Papel no Atlas

Substitui a dispersao de painels separados por uma cabine operacional
profissional: o operador opera o Forge ponta-a-ponta de uma aba so. Toda
acao passa por `useBridge` (sem bridge direto no componente) e por Tauri
commands canonicos quando rodando em app.

## Onde Se Encaixa

Filho de `atlas-code-forge-fast-path-v1.md` e
`atlas-code-forge-review-completion-gate-v1.md`. Aparece na aba `Cockpit`
do RightRail e e o primeiro panel listado.

## Contratos

- **Obra obrigatoria.** Sem Obra o painel renderiza fail-closed visual
  (`sem Obra · cockpit fail-closed`).
- **UI nao inventa estado.** Renderiza apenas bridge/state/backend.
- **Completion** so aparece como `completed` quando
  `claim.final_completion_allowed=true`.
- **Polling controlado.** `setInterval` 5s para em
  `completed/review_required/blocked/rejected/rolled_back` e nao duplica
  request enquanto `busy/pending`.
- **Repair UX read_only.** Mostra `repair_available`, `failure_packet`,
  `suggested_repair_command` mas nao executa repair automaticamente
  (backend nao expoe action automatica nesta versao).
- **Tauri commands canonicos** (`bridge_run_forge_fast_path`,
  `bridge_get_forge_fast_path_status`, `bridge_resume_forge_fast_path`,
  `bridge_get_forge_review_packet`, `bridge_decide_forge_review`) registrados.

## Fluxo

```text
Operador abre Atlas Code → seleciona Obra → cockpit mostra Forge Workspace
  → clica prepare/async/sync
    → cockpit polls status a cada 5s ate review_required/completed/blocked
  → repair_available aparece quando aplicavel (read_only)
  → operador inspeciona evidence/ledger counts
  → clica open review → packet/claim aparecem
  → approve (so passa com runtime/evidence) ou reject (exige reason) ou rollback
  → completion_claim final_completion_allowed=true → completed
```

## Regras para IA

- Nao adicione completion visual sem campo `final_completion_allowed`.
- Nao adicione execucao automatica de repair.
- Nao chame bridge dentro do componente (use useBridge actions).
- Nao toque em Rivals/Cartografia/self-construction/voice.

## Escopo de Implementacao

Implementado em:

- `apps/desktop/src/surfaces/code/panels/ForgeOperatorCockpitPanel.tsx`
- `apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx` (registrado como aba `cockpit`)
- `apps/desktop/src/surfaces/code/panels/rightRailTypes.ts` (OpsTab inclui `cockpit`)
- `apps/desktop/src/hooks/useBridge.ts` (refresh/approve/reject/rollback ja existiam)

Tauri commands em `crates/atlas-tauri/src/commands_bridge.rs` +
`crates/atlas-tauri/src/lib.rs`.

## Dependencias

- AtlasCodeForgeFastPathService (run + lifecycle).
- AtlasCodeForgeFastPathStatusService (status/resume).
- AtlasCodeForgeReviewCompletionService (review/completion).
- bridge `getForgeFastPathStatus/resumeForgeFastPath/runForgeFastPath`.
- bridge `getForgeReviewPacket/approveForgeReview/rejectForgeReview/rollbackForgeReview`.
- Tauri commands canonicos.

## Evidencias

- `atlas:programming:completion-audit --json`
  `atlas_code_forge_operator_cockpit_certification.status=available` com
  13/13 invariantes verdes.
- `npm run lint --workspace=@atlas/desktop` exit 0.
- `npm run build --workspace=@atlas/desktop` exit 0.
- `cargo check -p atlas-tauri` exit 0.

## Riscos

- Polling sem parar em estado terminal.
- Botao approve habilitado sem runtime/evidence.
- Completion visual mesmo sem `final_completion_allowed`.

## Exemplos

Sem Obra:

```text
Forge Operator Cockpit · sem Obra
sem Obra · cockpit fail-closed · selecione uma Obra para operar Forge
```

Async em execucao:

```text
Forge Operator Cockpit · queued · running
forge workspace · obras_shared_workspace · forge_workspace
obra · <uuid>
work item · OTH-XXXXXXXX
run · <ulid>
execution · <ulid>
lifecycle · queued · visual · running
[####--------]  20%
stage · execution_dispatch · 20%
review · pending · completion · not_allowed · runtime · queued
evidence · 0 · ledger · 0 · next · wait_for_async_execution
```

## Proximas Acoes

1. Manter este doc sincronizado com novos invariants do cockpit.
2. Rodar `atlas:programming:completion-audit --json` apos alteracao
   significativa no painel.
3. Continuar isolando Rivals externo.
