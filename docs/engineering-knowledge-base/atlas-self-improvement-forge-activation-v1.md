---
id: atlas-self-improvement-forge-activation-v1
type: engineering_knowledge
title: Atlas Self-Improvement → Forge Activation v1
status: active
category: self-construction
priority: 100
summary: Closed loop entre Self-Improvement Governance e Forge Obra. Uma proposta aprovada vira Obra Forge real com Intake completo, baseline, approval receipt, evidence — sem provider externo, sem tokens, sem auto Fast Path.
tags:
  - atlas
  - self-improvement
  - self-construction
  - forge
  - activation
  - obra
  - governance
capabilities:
  - self_improvement_forge_activation
  - approved_proposal_to_real_obra
  - approval_receipt_persistence
  - before_snapshot_baseline
  - canonical_docs_hashing
decisions:
  - Apenas propostas aprovadas pelo Power Gate viram Obra real.
  - Hard fail / rejected / needs_revision NUNCA cria Obra.
  - human_review_required exige accept explícito com reviewer + reason.
  - Aprovação gera receipt deterministico (`atlas.self_improvement.forge_activation_approval.v1`).
  - Antes de criar Obra, gera-se before_snapshot canônico (maturity + invariant lock + regression sentinel + strategy portfolio + trust ledger + docs hashes).
  - after_snapshot e null/placeholder explícito; never inventar resultado.
  - Activation NUNCA executa Fast Path. next_action e sempre `open_atlas_code_forge`.
  - Strategy Portfolio classifica em 1 dos 8 buckets canônicos; portfolio_deviation flag não bloqueia.
  - Trust Ledger registra outcomes canônicos: `proposal_accepted_for_forge`, `proposal_rejected_for_forge`.
  - Docs canônicos com hash (sha256). Doc obrigatório ausente: `blocked_missing_canonical_doc`.
maintenance:
  - Atualize antes de mexer em service, CLI, controller, completion-audit, desktop panel ou ladder runtime.
  - Mantenha como autoridade de contrato; service e tests vencem texto aspiracional.
related_paths:
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php
  - app/Console/Commands/AtlasSelfImprovementActivateForgeCommand.php
  - app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php
  - tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-improvement-forge-activation-v1
graph_title: Atlas Self-Improvement → Forge Activation v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-self-improvement-governance-ladder
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php
  - app/Console/Commands/AtlasSelfImprovementActivateForgeCommand.php
  - app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php
  - tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php
evidence:
  - docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php
  - tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php
allowed_changes:
  - Estender outcomes do trust ledger sob ladder doc.
  - Adicionar canonical docs ao baseline conforme novo eixo for criado.
forbidden_changes:
  - Permitir criação silenciosa de Obra.
  - Auto-promover proposta crítica (autopromotion bloqueada por policy).
  - Executar Fast Path automaticamente apos accept.
  - Liberar `external_rivals_certification` a partir desta camada.
  - Promover completion claim.
depends_on:
  - atlas-self-improvement-governance-ladder
  - atlas-forge-continuum-os
  - atlas-programming-forge-flow
  - atlas-code-forge-work-intake-spec-governance-v1
flows_to:
  - atlas-code
  - atlas-forge-continuum-os
  - atlas-self-improvement-activation-cockpit-v1
  - atlas-self-improvement-closed-loop-level7-v1
unlocks:
  - governed_self_improvement_to_obra_loop
governs:
  - self_improvement_forge_activation
required_tests:
  - "php artisan test --filter='AtlasSelfImprovementForgeActivationTest|AtlasSelfImprovementGovernanceTest'"
  - "php artisan atlas:self-improvement:activate-forge --json --strict"
requires_evidence: true
risk_level: critical
visual_tags:
  - self-improvement
  - forge
  - activation
ai_entrypoints:
  - Leia este doc antes de criar Obra a partir de proposta de melhoria, ou de mudar approval flow / baseline / docs hashes.
ai_usage_notes:
  - Activation NUNCA executa Fast Path. next_action e sempre `open_atlas_code_forge`.
  - human_review_required bloqueia até reviewer+reason+accept explícito.
quality_gates:
  - proposal-power-gate-required
  - human-review-required-for-critical
  - approval-receipt-persisted
  - before-snapshot-available
  - no-auto-fast-path
  - external-rivals-separated
failure_modes:
  - Criar Obra a partir de proposta fraca.
  - Auto-aceitar proposta crítica.
  - Executar Fast Path apos approve.
  - Mascarar blockers do gate.
observability_signals:
  - activation_id
  - proposal_hash
  - power_gate_hash
  - invariant_lock_hash
  - regression_sentinel_hash
  - strategy_bucket
  - portfolio_deviation
  - approval.receipt_hash
  - created_obra_id
next_actions:
  - Conectar approval workflow no Atlas Code (UI signoff persistido).
  - Quando dispatcher real for emitido, propagar `created_obra_id` ao Decision Receipt.
---
# Atlas Self-Improvement → Forge Activation v1

## Resumo

Camada de fechamento entre `atlas-self-improvement-governance-ladder.md`
(Proposal Packet + Power Gate + Delta Scorecard + Invariant Lock +
Regression Sentinel + Capability Maturity + Trust Ledger + Strategy
Portfolio) e o `atlas-forge-continuum-os.md` (Obra + Intake + Forge). Uma
proposta aprovada NUNCA executa código sozinha; ela vira Obra real com
Intake completo, baseline e approval receipt — pronta para que o operador
abra `atlas_code_forge`.

## Papel no Atlas

Sem este eixo, propostas aprovadas pelo Self-Improvement ficavam soltas:
ninguém transformava intenção em Obra real e governada. Forge Activation
fornece o último contrato:

```text
proposta aprovada
-> baseline canonical (maturity, invariant lock, regression sentinel,
   strategy portfolio, trust ledger, docs hashes)
-> approval receipt deterministico
-> AtlasProject real (domain=programming, workspace seguro, metadata canonica)
-> latest_atlas_code_forge_work_intake populado (objective, business_rule,
   acceptance_criteria, canonical_docs, scope_in/out, risk_level, constraints)
-> next_action=open_atlas_code_forge
```

## Onde Se Encaixa

```text
Self-Improvement Governance Ladder
└─ Proposal Packet → Power Gate
   └─ Approved + (human_review_required → operator accept)
      └─ Atlas Self-Improvement → Forge Activation v1 (this doc)
         └─ AtlasProject + Forge Intake → Atlas Code Forge
```

## Contratos

| Schema | Producer | Consumer |
|---|---|---|
| `atlas.self_improvement.forge_activation.v1` | `AtlasSelfImprovementForgeActivationService` | CLI, API, Audit, Desktop |
| `atlas.self_improvement.forge_activation_approval.v1` | service.accept() | Activation payload, audit, ledger |
| `atlas.self_improvement.forge_activation_baseline.v1` | service.plan() | Activation payload |
| `atlas.self_improvement.forge_activation_state.v1` | AtlasCodeWorkController state | Desktop |
| `atlas.self_improvement.forge_activation_certification.v1` | completion audit | `atlas:programming:completion-audit` |

### Statuses canônicos

`blocked`, `needs_revision`, `pending_human_review`, `accepted`,
`obra_created`, `dry_run_planned`, `rejected`.

### Approval Receipt (campos)

`schema_version`, `activation_id`, `reviewer`, `reason`, `approved_at`,
`proposal_hash`, `power_gate_hash`, `invariant_lock_hash`,
`regression_sentinel_hash`, `maturity_target`, `silent=false`,
`auto_promotes_completion_claim=false`, `auto_executes_fast_path=false`,
`external_provider_call=false`, `receipt_hash`.

## Fluxo

```text
1. Receber proposal payload, packet ou activation_id (via CLI/API).
2. Build packet via AtlasSelfImprovementProposalPacketService.
3. Power Gate evaluate (10 hard fails canon).
4. Coletar canonical docs hashes; bloquear se obrigatório faltar.
5. Build before_snapshot canonical (maturity, invariant lock, regression
   sentinel, strategy portfolio, trust ledger, docs hashes).
6. Classificar bucket (8 canon); portfolio_deviation flag.
7. Status inicial:
   - rejected/hard_fail → blocked.
   - needs_revision → needs_revision.
   - human_review_required → pending_human_review.
   - approved → accepted (mas Obra so via accept() para preservar audit).
   - dry_run sempre → dry_run_planned, sem persistência de Obra.
8. Persistir activation read-model (Storage local + ledger best-effort).
9. accept() exige reviewer+reason; gera receipt; cria AtlasProject;
   popula latest_atlas_code_forge_work_intake; registra trust ledger
   `proposal_accepted_for_forge`.
10. reject() exige reviewer+reason; registra rejection; trust ledger
    `proposal_rejected_for_forge`; NUNCA cria Obra.
11. next_action sempre `open_atlas_code_forge` ou similar — NUNCA dispara
    Fast Path automaticamente.
```

## Regras para IA

- Nunca criar Obra silenciosamente.
- Nunca aceitar proposta crítica sem human review explícito.
- Nunca executar Fast Path apos accept.
- Nunca chamar provider externo.
- Nunca gastar token.
- Nunca promover completion claim.
- Nunca desbloquear `external_rivals_certification`.
- Nunca mascarar blockers do gate.

## Escopo de Implementacao

| Componente | Path |
|---|---|
| Service | `app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php` |
| CLI | `app/Console/Commands/AtlasSelfImprovementActivateForgeCommand.php` |
| Controller | `app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php` |
| Routes | `/atlas-code/self-improvement/forge-activations` (POST/GET/accept/reject) |
| State | `AtlasCodeWorkController::state` → `self_improvement_activation` |
| Audit | `atlas_self_improvement_forge_activation_certification` em `ProgrammingProfessionalCompletionAuditService` |
| Tests | `tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php` |
| Desktop | `apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementGovernancePanel.tsx` (section reusada) |

## Dependencias

- `atlas-self-improvement-governance-ladder.md`
- `atlas-forge-continuum-os.md`
- `atlas-programming-forge-flow.md`
- `atlas-code-forge-work-intake-spec-governance-v1.md`
- `domains/programming-professional-completion-audit.md`

## Evidencias

```bash
php artisan atlas:self-improvement:activate-forge --proposal=@/tmp/proposal.json --json --strict
php artisan atlas:self-improvement:activate-forge --proposal-id=<id> --approve --reviewer=human-1 --reason="…" --json --strict
php artisan atlas:self-improvement:activate-forge --proposal-id=<id> --reject --reviewer=human-1 --reason="…" --json --strict
php artisan atlas:programming:completion-audit --json
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
php artisan test --filter='AtlasSelfImprovementForgeActivationTest|AtlasSelfImprovementGovernanceTest|AtlasCodeForgeWorkIntakeTest|AtlasCodeForgeFastPathTest'
```

## Riscos

| Risco | Bloqueio correto |
|---|---|
| Auto-promote proposta crítica | Power Gate retorna `rejected` (`attempts_critical_autopromotion`); activation `blocked`. |
| Aceitar sem reviewer | service.accept() exige reviewer+reason; sem isso, retorna blocker `accept_requires_reviewer_and_reason`. |
| Executar Fast Path apos accept | next_action sempre `open_atlas_code_forge`; `auto_fast_path_executed=false` em todo payload. |
| Inflar trust score | trust ledger registra outcome canônico real; nunca artificial. |
| Reduzir gates | activation expoe blockers do gate; nunca mascara. |

## Exemplos

### Activation aprovada (com human review)

```bash
# 1. Plan
$ php artisan atlas:self-improvement:activate-forge --proposal=@/tmp/strong.json --json
# status=pending_human_review (porque human_review_required=true)

# 2. Accept explícito
$ php artisan atlas:self-improvement:activate-forge \
    --proposal-id=act_… --approve --reviewer=human-1 --reason='maturity gain validated' --json
# status=obra_created, created_obra_id=<uuid>, work_intake populado.
```

### Activation rejeitada

```bash
$ php artisan atlas:self-improvement:activate-forge \
    --proposal-id=act_… --reject --reviewer=human-2 --reason='out of scope' --json
# status=rejected, rejection.reviewer=human-2, NUNCA cria Obra.
```

### Activation bloqueada

```bash
$ php artisan atlas:self-improvement:activate-forge --proposal=@/tmp/weak.json --json
# status=blocked, blockers inclui proposal_power_gate_rejected ou missing_*.
```

## Cockpit visual

A camada humana deste fluxo vive em `atlas-self-improvement-activation-cockpit-v1.md`. O cockpit é o operador read-model + 2 mutations (accept/reject) com reviewer + reason obrigatórios e checkbox "não executa Fast Path" para accept. Schema cockpit: `atlas.self_improvement.activation_cockpit.v1`.

## Proximas Acoes

1. (entregue 2026-05-14) ~~Conectar approval workflow no Atlas Code (UI com signoff persistido).~~ Cockpit v1 entrega painel + accept/reject visual + receipt.
2. Quando dispatcher real for emitido, propagar `created_obra_id` ao Decision Receipt para fechar evidence chain.
3. Adicionar trust band influence sobre threshold de approval automatico para low-risk autopromotable proposals.
