---
id: atlas-code-forge-work-intake-spec-governance-v1
type: engineering_knowledge
title: Atlas Code Forge Work Intake & Spec Governance v1
status: active
category: programming-forge
priority: 100
summary: Camada governada de intake (objetivo + regra de negocio + escopo + criterios de aceite + docs canonicas) que precede Fast Path/Live Execution no Atlas Code SCOR-1. Sem provider externo; readiness calculado no backend; Obra obrigatoria.
tags:
  - atlas
  - atlas-code
  - forge
  - intake
  - spec
  - governance
capabilities:
  - atlas_code_forge_work_intake
  - readiness_gate
  - canonical_docs_required
  - business_rule_required
  - acceptance_criteria_required
  - work_item_linkage
decisions:
  - Atlas Code SCOR-1 nao roda Forge sobre pedido cru; intake governado e pre-requisito enterprise.
  - business_rule + objective + acceptance_criteria + canonical_docs sao obrigatorios para readiness=ready.
  - Sem Obra, fail-closed visual e operacional.
  - Sem provider externo nesta camada.
  - WorkItem linkage feita pelo metadata canonico programming_work_item_id; sem reinventar governance.
maintenance:
  - Atualize quando readiness/blocker contract mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - app/Services/Ai/Programming/AtlasCodeForgeWorkIntakeService.php
  - app/Http/Controllers/AtlasCodeForgeWorkIntakeController.php
  - app/Console/Commands/AtlasCodeForgeWorkIntakeCommand.php
  - tests/Feature/Ai/Programming/AtlasCodeForgeWorkIntakeTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-forge-work-intake-spec-governance-v1
graph_title: Atlas Code Forge Work Intake & Spec Governance v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-forge-operator-cockpit-v1
graph_status: active
graph_source: repo
human_name: Atlas Code Forge Work Intake & Spec Governance v1
canonical_name: Atlas Code Forge Work Intake & Spec Governance v1
technical_name: atlas-code-forge-work-intake-spec-governance-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md
allowed_changes:
  - Adicionar campos/invariants ao intake mantendo readiness gate.
forbidden_changes:
  - Permitir readiness ready sem business_rule.
  - Permitir readiness ready sem acceptance_criteria.
  - Permitir readiness ready sem canonical_docs.
  - Auto-criar Obra ou auto-executar Fast Path.
  - Tocar em Rivals, Cartografia, self-construction, voice.
depends_on:
  - atlas-code-forge-fast-path-v1
flows_to:
  - atlas-code-forge-fast-path-v1
unlocks:
  - atlas-code-forge-work-intake-spec-governance
governs:
  - atlas_code_forge_work_intake
evidence:
  - docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md
  - app/Services/Ai/Programming/AtlasCodeForgeWorkIntakeService.php
  - tests/Feature/Ai/Programming/AtlasCodeForgeWorkIntakeTest.php
required_tests:
  - "php artisan test --filter AtlasCodeForgeWorkIntakeTest"
  - "php artisan atlas:code:forge-intake --obra=<uuid> --json --strict"
  - "php artisan atlas:programming:completion-audit --json"
requires_evidence: true
risk_level: high
visual_tags:
  - forge
  - intake
  - governance
ai_entrypoints:
  - Leia este doc antes de alterar AtlasCodeForgeWorkIntakeService/Controller/Command.
ai_usage_notes:
  - Nao remova business_rule/acceptance_criteria/canonical_docs do readiness gate.
quality_gates:
  - intake-business-rule-required
  - intake-acceptance-required
  - intake-canonical-docs-required
  - intake-state-projection-available
failure_modes:
  - Aceitar intake sem business_rule e marcar ready.
  - UI inventar readiness.
  - Auto-disparar Fast Path apos save.
observability_signals:
  - readiness_status
  - blockers
  - work_item_id
  - intake_id
next_actions:
  - Manter este doc sincronizado com novos campos do intake.
---
# Atlas Code Forge Work Intake & Spec Governance v1

## Resumo

Camada governada de intake que precede Fast Path no Atlas Code SCOR-1:
operador declara objetivo + regra de negocio + escopo + criterios de aceite +
docs canonicas; backend calcula readiness; sem readiness=ready o ciclo
enterprise nao avanca.

## Papel no Atlas

Garante que toda execucao Forge sai de uma Obra com intake explicito e
auditavel. Bloqueia pedido cru. Liga intake → WorkItem → Spec/Plan/Tasks →
Fast Path.

## Onde Se Encaixa

Pai operacional: `atlas-code-forge-operator-cockpit-v1.md`. Antes do Fast
Path (`atlas-code-forge-fast-path-v1.md`). Persiste em
`AtlasProject.metadata.latest_atlas_code_forge_work_intake` +
`atlas_code_forge_work_intake_history` (25).

## Contratos

- **Obra obrigatoria.** Sem Obra → fail-closed (`blocked_no_obra`).
- **Campos obrigatorios** para `readiness=ready`:
  - `objective`
  - `business_rule`
  - >= 1 `acceptance_criteria`
  - >= 1 `canonical_docs`
- **Blockers canonicos:** `blocked_missing_objective`,
  `blocked_missing_business_rule`, `blocked_missing_acceptance_criteria`,
  `blocked_missing_canonical_docs`, `blocked_no_obra`.
- **`intake_id`** ULID estavel para a mesma Obra (rememorado entre saves).
- **`work_item_id`/`work_item_code`** preenchidos quando programming
  governance ja existe.
- **`external_provider_call=false`** sempre.

## Fluxo

```text
Operador abre Atlas Code → seleciona/cria Obra → abre Intake panel
  → preenche objective/business_rule/acceptance/docs/scope
  → POST /atlas-code/works/{obra}/forge/intake
    → service calcula readiness
    → blockers expostos no payload
  → readiness=ready libera cockpit/Fast Path enterprise
```

## Regras para IA

- Nao remova business_rule/acceptance_criteria/canonical_docs do gate.
- Nao auto-execute Fast Path apos save de intake.
- Nao inicialize readiness=ready com Obra ausente.
- Nao toque em Rivals, Cartografia, self-construction, voice.

## Escopo de Implementacao

- `app/Services/Ai/Programming/AtlasCodeForgeWorkIntakeService.php`
- `app/Http/Controllers/AtlasCodeForgeWorkIntakeController.php`
  (`GET` + `POST /atlas-code/works/{project}/forge/intake`)
- `app/Console/Commands/AtlasCodeForgeWorkIntakeCommand.php`
  (`atlas:code:forge-intake`)
- `tests/Feature/Ai/Programming/AtlasCodeForgeWorkIntakeTest.php` (10 cenarios)
- State projection em `AtlasCodeWorkController::state.forge_work_intake`
- Audit bloco `atlas_code_forge_work_intake_certification`

UI desktop: `apps/desktop/src/surfaces/code/panels/ForgeWorkIntakePanel.tsx`
+ bridge `getForgeWorkIntake`/`saveForgeWorkIntake` + useBridge
`refreshForgeWorkIntake`/`saveForgeWorkIntake` + Tauri commands
`bridge_get_forge_work_intake`/`bridge_save_forge_work_intake`.

## Dependencias

- `AtlasProject` (metadata).
- `AtlasProgrammingWorkItem` (linkage opcional).
- Programming Governance (quando work item existe).

## Evidencias

- `php artisan atlas:code:forge-intake --obra=<uuid> --json --strict`
  exit 0 quando ready, exit 1 quando blocked.
- `php artisan atlas:programming:completion-audit --json` expoe
  `atlas_code_forge_work_intake_certification` separado de Rivals.
- 10 testes em `AtlasCodeForgeWorkIntakeTest`.

## Riscos

- Aceitar `ready` sem campos obrigatorios.
- UI fingir readiness.
- Auto-disparar Fast Path apos save.

## Exemplos

```bash
# Sem args:
$ php artisan atlas:code:forge-intake --json --strict; echo $?
{"schema_version":"atlas.code.forge_work_intake.v1","status":"blocked","blocker":"obra_required"}
1

# Pedido completo:
$ php artisan atlas:code:forge-intake --obra=<uuid> \
  --objective="Refatorar contrato Forge" \
  --business-rule="Toda Obra precisa de intake governado" \
  --acceptance="suite verde" \
  --doc="docs/engineering-knowledge-base/atlas-programming-forge-flow.md" \
  --json --strict
{"readiness_status":"ready","blockers":[],"next_action":"run_forge_fast_path"}
0
```

## Proximas Acoes

1. Manter este doc sincronizado com novos campos.
2. Rodar `atlas:code:forge-intake --json --strict` apos alteracoes.
3. Manter `external_rivals_certification` separado.
