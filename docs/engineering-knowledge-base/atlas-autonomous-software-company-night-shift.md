---
id: atlas-autonomous-software-company-night-shift
type: engineering_knowledge
title: Atlas Autonomous Software Company Night Shift
status: future
category: agentic-engineering
priority: 100
implementation_state: future_target_not_current_runtime
summary: Governed overnight operating loop for the Autonomous Software Company Runtime. Night Shift lets Atlas scan repos, detect bugs and improvements, draft specs, create isolated branches for safe work, implement low-risk fixes, validate evidence and deliver a Morning Inbox. v1 must operate only on Atlas itself; v2 may operate authorized external companies such as BlackInk only after v1 promotion evidence and operator approval.
human_summary: Atlas trabalha enquanto o operador dorme, primeiro no proprio Atlas, depois em empresas autorizadas.
human_what: Contrato canonico para varredura noturna, findings, specs, branches isoladas, implementacao segura, evidence pack e inbox matinal.
human_purpose: Transformar a autonomia de engenharia em rotina segura: Atlas melhora software continuamente sem merge/deploy automatico.
human_input: Repos autorizados, escopo do ciclo, docs canonicos, Code Intelligence, testes, build, evidence, findings, gaps, specs e feedback do operador.
human_output: Findings, spec drafts, branch/worktree isolada, implementation attempts, validation evidence, rollback plan e Morning Inbox.
human_change_when: Mexa quando Autonomous Software Company Runtime, Self-Directed Evolution, Programming Governance, Forge, Evidence ou branch sandbox mudarem contratos.
human_block_when: Bloqueie quando IA tentar rodar v1 em repo externo, fazer merge/deploy automatico, acessar secrets ou criar runtime paralelo.
tags:
  - atlas-ai
  - autonomous-software-company
  - night-shift
  - branch-sandbox
  - morning-inbox
  - self-directed-evolution
capabilities:
  - night_shift_repo_scout
  - night_shift_finding_engine
  - night_shift_spec_draft_engine
  - night_shift_branch_sandbox
  - night_shift_low_risk_implementation
  - night_shift_evidence_pack
  - night_shift_morning_inbox
  - night_shift_v1_atlas_internal_proof
  - night_shift_v2_external_company_operation
  - night_shift_v3_continuous_loop
  - night_shift_product_mode
  - night_shift_area_focus_loop
  - area_stewardship_layer
decisions:
  - Night Shift e contrato operacional filho do Atlas Autonomous Software Company Runtime; nao e OS novo.
  - A primeira versao deve operar somente o proprio Atlas ate provar maturidade com evidence.
  - A segunda versao pode operar empresas/produtos externos autorizados, como BlackInk, somente apos promotion receipt v1->v2 aprovado pelo operador.
  - V1 e Atlas Internal Night Shift; V2 e External Company Night Shift.
  - Night Shift usa Self-Directed Evolution para gaps/spec drafts; nao cria proposal registry paralelo.
  - Night Shift usa Atlas Dev/Forge/Self-Construction para implementacao; nao cria executor paralelo.
  - Night Shift usa Programming Governance e workspace contracts para branch/worktree isolation.
  - Night Shift nunca faz merge, deploy, push externo, secret access ou mudanca destrutiva sem operador.
  - Morning Inbox e a superficie obrigatoria de decisao humana.
  - Toda branch deve ter evidence pack, rollback plan e risk classification antes de aparecer como review-ready.
  - Produto final e Product Mode: cockpit, onboarding, autonomy tiers, budget, kill switch, branch review e evidence inspector.
  - Area Focus Loop deixa o operador escolher uma area canonica para melhoria continua; isso e controle de foco do Product Mode, nao executor novo.
  - Area Stewardship Layer e a evolucao acima do Area Focus Loop: responsabilidade continua por saude, roadmap e priorizacao de uma area.
maintenance:
  - Atualize antes de implementar scout, branch sandbox, implementation loop ou inbox noturna.
  - Atualize quando v1 promotion evidence mudar ou quando v2 external company mandate for introduzido.
  - Sincronize canonical indexes apos qualquer alteracao.
related_paths:
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-710-autonomous-software-company-night-shift-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-reality-outcome-gates.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-software-company-night-shift
graph_title: Atlas Autonomous Software Company Night Shift
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-software-company-runtime
graph_status: future
graph_source: repo
human_name: Atlas Autonomous Software Company Night Shift
canonical_name: Atlas Autonomous Software Company Night Shift
technical_name: atlas-autonomous-software-company-night-shift
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
allowed_changes:
  - Refinar schemas, runbooks, safety gates and promotion evidence for Night Shift.
  - Adicionar repos autorizados somente via mandate e v2 gates.
forbidden_changes:
  - Rodar v1 em BlackInk ou qualquer repo externo.
  - Permitir merge, deploy, push, secret access ou destructive change automatico.
  - Criar runtime paralelo a Autonomous Software Company Runtime, Self-Directed Evolution, Dev, Forge, Self-Construction ou Evidence.
  - Chamar implementation attempt de pronto sem evidence pack e Morning Inbox.
depends_on:
  - atlas-autonomous-software-company-runtime
  - atlas-self-directed-evolution-layer
  - atlas-programming-governance-system
  - atlas-forge-operating-system
  - atlas-ai-self-construction-os
  - atlas-evidence-certification-runtime
flows_to:
  - atlas_dev
  - atlas_forge
  - atlas_code
  - mission_control
  - morning_inbox
unlocks:
  - atlas_internal_night_shift
  - external_company_night_shift_after_promotion
governs:
  - atlas.night_shift
  - atlas.night_shift.finding
  - atlas.night_shift.spec_draft
  - atlas.night_shift.branch_sandbox
  - atlas.night_shift.evidence_pack
  - atlas.night_shift.area_focus_loop
  - atlas.area_stewardship
evidence:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - night-shift
  - software-company
  - branch-sandbox
  - inbox
ai_entrypoints:
  - Leia este doc antes de implementar qualquer job noturno, repo scout, branch sandbox, morning inbox ou empresa externa.
  - Se a tarefa mencionar BlackInk, primeiro verifique se NS-v1 promotion evidence existe; sem isso, bloquear v2.
ai_usage_notes:
  - Night Shift e um loop operacional governado, nao uma permissao para mudar repos sem review.
  - V1 prova competencia no Atlas; v2 opera empresas externas autorizadas.
quality_gates:
  - v1-atlas-internal-first
  - v2-requires-promotion-receipt
  - branch-isolation-required
  - morning-inbox-required
  - evidence-pack-required
  - rollback-required
  - no-merge-without-operator
  - no-deploy-without-operator
  - no-secrets
  - no-destructive-change
failure_modes:
  - IA pula direto para BlackInk sem maturidade comprovada no Atlas.
  - IA mistura scanner, spec writer e executor em runtime paralelo.
  - Branch noturna acumula escopo grande demais para review humano.
  - Build/test falha mas item aparece como pronto.
  - Secret ou ambiente produtivo entra no ciclo noturno.
  - Morning Inbox vira resumo narrativo sem evidence, branch e rollback.
observability_signals:
  - night_shift_cycle_id
  - authorized_repo_id
  - findings_count
  - spec_drafts_count
  - branch_sandbox_count
  - implementation_attempt_count
  - validation_pass_rate
  - morning_inbox_item_count
  - operator_approval_rate
  - false_positive_rate
  - focused_area_id
  - area_focus_findings_count
  - dev_budget_usage_for_area
  - forge_budget_usage_for_area
next_actions:
  - Implementar NS-v1.0 Atlas Internal Read-Only Scout.
  - Implementar NS-v1.1 Atlas Internal Spec Drafts usando Self-Directed Evolution.
  - Implementar NS-v1.2 Atlas Internal Branch Sandbox.
  - Implementar NS-v1.3 Low-Risk Implementation Attempt.
  - Implementar NS-v1.4 Morning Inbox e v1 promotion evidence.
  - Implementar Area Focus Loop primeiro como read-only para Agentic Engineering OS.
  - Evoluir para NS-v3 Continuous Loop e NS-v4 Product Mode somente apos v1/v2 evidence.
---
# Atlas Autonomous Software Company Night Shift

## Resumo

Atlas Autonomous Software Company Night Shift e o ciclo em que Atlas opera uma
empresa de software enquanto o operador dorme: observa repositorios, encontra
bugs e melhorias, escreve specs, cria branches isoladas, implementa mudancas de
baixo risco, valida evidence e entrega uma inbox matinal para decisao humana.

Regra principal:

```text
Atlas must night-shift itself before it night-shifts any company.
```

A primeira versao roda somente no proprio Atlas. A segunda versao comeca a
operar empresas/produtos externos autorizados, como BlackInk, apenas depois de
evidence real e aprovacao do operador.

## Papel no Atlas

Night Shift e a rotina operacional noturna do Autonomous Software Company
Runtime. Ele pega a capacidade de engenharia agentica que ja existe no Atlas e
a executa em ciclos seguros, isolados e revisaveis.

Ele nao substitui:

- AAEOS;
- Autonomous Software Company Runtime;
- Self-Directed Evolution;
- Atlas Dev;
- Forge;
- Self-Construction;
- Evidence Certification Runtime.

Quando Product Mode roda com Area Focus Loop, Night Shift tambem nao vira um
executor novo. Ele apenas concentra a varredura, specs, branches e evidence em
uma area escolhida pelo operador.

## Onde Se Encaixa

```text
Self-Directed Evolution -> gaps e spec drafts
Autonomous Software Company Runtime -> coordenacao de departamentos
Night Shift -> ciclo noturno sandboxed
Atlas Dev / Forge / Self-Construction -> implementacao governada
Evidence / Reality Gates / Morning Inbox -> prova e decisao humana
```

## Contratos

### Contrato 1: Progressao v1 Antes De v2

```text
NS-v1 = Atlas Internal Night Shift
NS-v2 = External Company Night Shift
```

NS-v1 roda somente no proprio Atlas: `atlas-server`, `atlas-desktop` e repos
Atlas autorizados. NS-v2 roda em empresas/produtos externos autorizados, como
BlackInk, somente depois de promotion receipt v1->v2 aprovado pelo operador.

Sem promotion receipt, qualquer pedido envolvendo BlackInk deve virar blocker:

```text
ns_v2_requires_v1_promotion_receipt
```

### Contrato 2: Authorized Repo Registry

```text
repo_id: atlas-server
night_shift_version: ns-v1
allowed_modes: [scan, spec_draft, branch_sandbox, low_risk_implementation]
forbidden_modes: [merge_to_main, deploy, secret_access, destructive_migration]
```

Para repos externos:

```text
night_shift_version: ns-v2
requires: [ns_v1_promotion_receipt, operator_external_company_mandate, repo_scope_contract, secret_boundary, rollback_plan]
```

### Contrato 3: Schemas

```text
atlas.night_shift.cycle.v1
atlas.night_shift.finding.v1
atlas.night_shift.spec_draft.v1
atlas.night_shift.branch_sandbox.v1
atlas.night_shift.evidence_pack.v1
atlas.night_shift.morning_inbox.v1
atlas.night_shift.promotion_receipt.v1
```

Cada finding precisa de `repo_id`, `source`, `severity`, `confidence`,
`evidence_refs`, `affected_paths`, `recommended_action`, `safe_to_autofix` e
`requires_operator_review=true`.

Cada spec draft precisa de `problem`, `evidence`, `scope`, `non_goals`,
`owner_docs`, `implementation_plan`, `tests_required`, `rollback_plan`,
`risk_level`, `operator_decision_required=true`,
`canonical_write_allowed=false` e `autoimplementation_allowed=false`.

### Contrato 4: Promotion Gate v1 -> v2

V2 so pode existir apos evidence real de v1:

```text
ns_v1_cycle_count >= 10
merge_to_main_allowed=false em 100% dos ciclos
deploy_allowed=false em 100% dos ciclos
secret_access_allowed=false em 100% dos ciclos
branch_isolation_success_rate >= 0.95
morning_inbox_delivery_rate >= 0.95
evidence_pack_completion_rate >= 0.95
rollback_plan_completion_rate >= 0.95
false_positive_rate <= 0.25
at_least_3_successful_low_risk_fixes_reviewed_by_operator
```

## Fluxo

```text
night shift starts
-> authorize repo scope
-> build context pack
-> scout repo
-> produce findings
-> draft specs
-> triage risk
-> create branch/worktree if safe
-> implement low-risk change
-> run validation
-> build evidence pack
-> publish morning inbox
-> operator approves/rejects/requests changes
```

Ladder:

```text
NS-v1.0 Atlas Internal Read-Only Scout
NS-v1.1 Atlas Internal Spec Drafts
NS-v1.2 Atlas Internal Branch Sandbox
NS-v1.3 Atlas Internal Low-Risk Implementation
NS-v1.4 Atlas Internal Morning Inbox
NS-v1.5 Atlas Internal Promotion Evidence
NS-v2.0 External Company Read-Only Scout
NS-v2.1 External Company Spec Drafts
NS-v2.2 External Company Branch Sandbox
NS-v2.3 External Company Low-Risk Implementation
NS-v2.4 External Company Morning Inbox
NS-v2.5 Multi-Company Night Shift
NS-v3 Continuous Software Company Loop
NS-v4 Product Cockpit
NS-v5 Multi-Company Software Company Product
```

## Regras para IA

IA deve:

- comecar por NS-v1 no proprio Atlas;
- usar Self-Directed Evolution para gaps/specs;
- usar Dev/Forge/Self-Construction para implementacao;
- usar Evidence para prova;
- criar uma branch/worktree por item;
- parar em Morning Inbox.

IA nunca deve:

- rodar v1 em BlackInk ou cliente externo;
- fazer merge, push ou deploy automatico;
- acessar secrets;
- fazer destructive migration;
- tocar auth, billing, security critical ou dados reais sem decisao humana;
- criar runtime paralelo.

Risk triage:

```text
branch_allowed: docs-only, tests-only, lint/type fix, bug local isolado, UI polish pequeno
inbox_only: auth, billing, secrets, production config, legal/healthcare/finance/trading/cyber sensivel, data deletion, architecture redesign, large refactor, deploy
```

## Escopo de Implementacao

Primeiros slices:

1. NS-v1.0 Atlas Internal Read-Only Scout.
2. NS-v1.1 Spec Drafts via Self-Directed Evolution.
3. NS-v1.2 Branch Sandbox.
4. NS-v1.3 Low-Risk Implementation Attempt.
5. NS-v1.4 Morning Inbox.
6. NS-v1.5 Promotion Evidence.

Depois da prova v1/v2, o alvo de produto final fica em
`atlas-autonomous-software-company-night-shift-product-mode.md`: NS-v3 roda 24h
com budget/locks/kill switch, NS-v4 entrega cockpit de produto e NS-v5 opera
multi-company supervisionado.

Comandos futuros:

```text
atlas:night-shift run --repo=atlas-server --mode=read-only --json
atlas:night-shift run --repo=atlas-server --mode=sandbox --json
atlas:night-shift inbox --repo=atlas-server --json
atlas:night-shift promote --from=v1 --to=v2 --json
```

## Dependencias

| Necessidade | Owner |
|---|---|
| Orquestracao de software company | Autonomous Software Company Runtime |
| Gap/spec draft | Self-Directed Evolution |
| Implementacao | Atlas Dev, Forge, Self-Construction |
| Branch/worktree isolation | Programming Governance + Forge workspace |
| Evidence/receipt/certification | Evidence Certification Runtime |
| Outcome feedback | Reality Outcome Gates + ASRE/AEMOR |
| Empresa externa | Domain Runtime Contract + operator mandate |

## Evidencias

Evidence pack obrigatorio:

```text
cycle_id
repo_id
branch
finding_refs
spec_draft_ref
files_changed
commands_run
test_results
build_results
risk_assessment
rollback_instructions
diff_summary
known_caveats
```

Morning Inbox obrigatoria:

```text
what_found
why_it_matters
spec_draft
branch_or_no_branch_reason
implementation_summary
validation_summary
risk
rollback
recommendation
operator_actions: approve | request_changes | reject | continue_in_sandbox
```

## Riscos

| Risco | Mitigacao |
|---|---|
| Pular direto para BlackInk | `ns_v2_requires_v1_promotion_receipt` |
| Runtime paralelo | Owner map e AP-710 |
| Branch grande demais | Uma obra por branch |
| Falha aparecer como pronto | Evidence pack + validation summary |
| Secret/producao no loop | `secret_access_allowed=false` |
| Inbox narrativa sem prova | Campos obrigatorios de evidence |

## Exemplos

Pedido valido v1:

```text
Run Night Shift for Atlas itself. Mode: NS-v1 internal only. Scan, draft specs,
sandbox safe low-risk fixes, validate, and produce Morning Inbox.
```

Pedido bloqueado antes de promotion:

```text
Run Night Shift on BlackInk.
-> blocked: ns_v2_requires_v1_promotion_receipt
```

## Proximas Acoes

1. Implementar NS-v1.0 Read-Only Scout no Atlas.
2. Conectar findings a Self-Directed Evolution spec drafts.
3. Criar Branch Sandbox Factory para repos Atlas.
4. Criar Evidence Pack e Morning Inbox.
5. Medir ciclos v1 suficientes para pedir promotion v2.
