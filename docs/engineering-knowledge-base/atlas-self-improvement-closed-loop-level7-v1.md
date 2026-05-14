---
id: atlas-self-improvement-closed-loop-level7-v1
type: engineering_knowledge
title: Atlas Self-Improvement Closed Loop Level 7 v1
status: active
category: self-construction
priority: 100
summary: Closed loop end-to-end Self-Improvement → Forge. Backlog persistente, projeção 12 stages, result ledger com delta scorecard real, learning packet, next-cycle recommendation, integração com Obra Command Center. NUNCA chama provider, NUNCA executa Fast Path automático, NUNCA promove completion claim, NUNCA libera external_rivals_certification.
tags:
  - atlas
  - self-improvement
  - self-construction
  - closed-loop
  - level-7
  - backlog
  - result-ledger
  - learning
  - next-cycle
  - governance
capabilities:
  - self_improvement_proposal_backlog
  - self_improvement_closed_loop_projection
  - self_improvement_before_after_delta
  - self_improvement_learning_packet
  - self_improvement_next_cycle_recommendation
  - self_improvement_origin_visibility_in_obra_command_center
  - self_improvement_trust_ledger_outcomes_extended
decisions:
  - Backlog é fonte canônica de propostas antes de virarem activations; persiste em Storage/json sem migração nova.
  - Closed-loop é projeção pura (read-only) que reconstrói proposal→activation→obra→forge→evidence→delta→trust→learning→next-cycle.
  - Result ledger só grava após review humano com reviewer+reason+before/after snapshots; nunca promove completion claim.
  - Delta grade é derivado deterministicamente do DeltaScorecardService + InvariantLockService + RegressionSentinelService.
  - Trust ledger ganha 5 outcomes canônicos novos para self_improvement (major_improvement/improved/neutral/regressed/invalid_evidence).
  - Next-cycle recommendation NUNCA cria proposta automaticamente; sugere payload editável que o operador deve submeter explicitamente.
  - Obra Command Center mostra `self_improvement_origin` block quando metadata da Obra carrega `self_improvement_activation`; null para Obras manuais.
  - Mutações Level 7 são HTTP-only no desktop (sem Tauri commands novos); documentado.
  - measure-result CLI exige proposal_id + obra_id + before/after snapshots + reviewer + reason; sem isso, retorna blocked.
  - 12 stages canônicas: proposal_captured → power_gate_evaluated → human_approved → activation_created → obra_created → forge_executed → evidence_collected → human_reviewed → delta_measured → trust_updated → learning_recorded → next_cycle_recommended.
maintenance:
  - Atualize antes de mexer em ProposalBacklog, ClosedLoop, ResultLedger, NextCycleRecommendation, certification ou painel Level 7.
  - Mantenha esta doc como autoridade do contrato; service + tests + cert vencem texto aspiracional.
  - Quando v1 do ForgeActivation ou Activation Cockpit mudar, refletir aqui antes de tocar a UI.
related_paths:
  - docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md
  - docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementResultLedgerService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementNextCycleRecommendationService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementHumanTrustLedgerService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementDeltaScorecardService.php
  - app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php
  - app/Console/Commands/AtlasSelfImprovementProposalBacklogCommand.php
  - app/Console/Commands/AtlasSelfImprovementClosedLoopCommand.php
  - app/Console/Commands/AtlasSelfImprovementMeasureResultCommand.php
  - app/Console/Commands/AtlasSelfImprovementNextCycleCommand.php
  - tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopLevel7Test.php
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementLevel7Panel.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx
  - ../atlas-desktop/apps/desktop/src/lib/bridge.ts
  - ../atlas-desktop/packages/atlas-domain/src/index.ts
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-improvement-closed-loop-level7-v1
graph_title: Atlas Self-Improvement Closed Loop Level 7 v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-self-improvement-activation-cockpit-v1
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementResultLedgerService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementNextCycleRecommendationService.php
  - tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopLevel7Test.php
evidence:
  - docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementResultLedgerService.php
  - tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopLevel7Test.php
allowed_changes:
  - Adicionar source novo ao Proposal Backlog enum (postmortem hook, rivals trigger, etc) com test de regressão.
  - Estender Result Ledger schema com campos opcionais sob backward-compat.
  - Adicionar nova recomendação ao Next-Cycle service desde que mantenha human_approval_required=true.
  - Adicionar Tauri commands nativos para mutações Level 7 (HTTP fallback continua válido).
forbidden_changes:
  - Permitir Backlog criar Obra silenciosa.
  - Aceitar/Rejeitar proposta sem reviewer + reason.
  - measure-result sem reviewer + reason + before/after.
  - Next-cycle recommendation criar proposta sem comando explícito do operador.
  - Promover completion claim a partir de result entry.
  - Executar Fast Path automaticamente após Obra criada.
  - Disparar provider externo a partir de qualquer surface Level 7.
  - Liberar `external_rivals_certification` (continua bloqueado externo).
  - Mascarar regressões ou findings do invariant lock.
  - Aceitar synthetic_scores como evidência.
  - Tocar Voice ou Cartografia.
depends_on:
  - atlas-self-improvement-activation-cockpit-v1
  - atlas-self-improvement-forge-activation-v1
  - atlas-self-improvement-governance-ladder
  - atlas-forge-continuum-os
  - atlas-programming-forge-flow
  - atlas-code-forge-human-first-ux-orchestrator-v1
flows_to:
  - atlas-code
  - atlas-self-improvement-activation-cockpit-v1
unlocks:
  - human_visibility_of_full_self_improvement_lifecycle
  - measured_before_after_with_governance_review
  - learning_packets_for_pattern_promotion
  - next_cycle_recommendations_human_curated
governs:
  - self_improvement_closed_loop_level7
required_tests:
  - "php artisan test --filter='AtlasSelfImprovementClosedLoopLevel7|AtlasSelfImprovementForgeActivation|AtlasSelfImprovementActivationCockpit'"
  - "php artisan atlas:self-improvement:proposal-backlog --json --strict"
  - "php artisan atlas:self-improvement:closed-loop --proposal=<id> --json --strict"
  - "php artisan atlas:self-improvement:measure-result --proposal=<id> --obra=<uuid> --before=@b.json --after=@a.json --reviewer=<who> --reason=<why> --json --strict"
  - "php artisan atlas:self-improvement:next-cycle --latest --json --strict"
  - "npm run lint --workspace=@atlas/desktop"
  - "npx tsc -b"
requires_evidence: true
risk_level: critical
visual_tags:
  - self-improvement
  - closed-loop
  - level-7
  - editorial
  - human-first
ai_entrypoints:
  - Leia este doc antes de tocar backlog, closed-loop, result-ledger, next-cycle, certification ou painel Level 7.
ai_usage_notes:
  - Backlog é fonte canônica do que vai virar activation; nunca crie Obra direto.
  - Closed-loop é projeção pura — toda mutação reusa accept/reject do v1 e record do ledger.
  - Next-cycle nunca persiste proposta; entrega payload draft para humano submeter.
next_actions:
  - Hook automático: regression sentinel → cria entry no backlog com source=regression_sentinel.
  - Hook automático: trust ledger band drop → sugere proposal source=trust_ledger.
  - Dashboard de "rule candidates promovidos" agregado mensalmente.
  - Tauri commands nativos para mutações Level 7 (HTTP fallback continua válido).
  - Integração Cartografia: drill-down do evidence_refs[] para abrir doc/ledger event correspondente.
---

# Atlas Self-Improvement Closed Loop Level 7 v1

## Resumo

A entrega anterior (Atlas Self-Improvement Activation Cockpit v1, 2026-05-14) tornou o trecho proposta → power gate → approval → Obra visível dentro do Atlas Code. Mas o **loop não fechava**: nada projetava o estado completo proposal → activation → obra → forge → evidence → delta → trust → learning, e nada media o resultado pós-Obra. Esta entrega — Closed Loop Level 7 v1 — fecha o ciclo com 4 services novos (ProposalBacklog, ClosedLoop, ResultLedger, NextCycleRecommendation), 5 outcomes canônicos novos no trust ledger, 4 CLIs, 9 endpoints HTTP, painel desktop unificado de 5 sections + integração com Obra Command Center, 22 testes feature e certification dedicada com 31 invariantes.

## Papel no Atlas

Level 7 é a **camada de fechamento do loop** entre Self-Improvement Governance e Forge Continuum. Antes desta entrega:

- Proposta → activation → Obra existia, mas sem backlog persistente.
- Nada media o resultado pós-Obra com governance real.
- Trust ledger não tinha outcome para "Atlas melhorou de verdade".
- Obra Command Center não mostrava origem Self-Improvement.

Depois:

- Backlog persiste todas as propostas (até as descartadas).
- Closed-loop projection reconstrói o estado completo de qualquer proposta.
- measure-result roda Delta Scorecard + Invariant Lock + Regression Sentinel REAIS, grava result entry + learning packet, e atualiza trust ledger com outcome canônico.
- Next-cycle recommendation sugere próximo passo (broaden/repair/archive/etc) com payload draft editável.
- Obra Command Center exibe `self_improvement_origin` bloco com human message + measure-result hint.

Papel do humano:

1. Cria proposta no backlog (manual / chat / hook).
2. Avalia via Power Gate.
3. Prioriza via Strategy Portfolio bucket.
4. Aprova via Activation Cockpit (reviewer + reason + ack no-fast-path).
5. Abre Obra no Forge — decide se Fast Path roda agora.
6. Após Obra finalizada, mede resultado (CLI ou API) com before/after snapshots + reviewer + reason.
7. Revisa learning packet e next-cycle recommendation.
8. Decide criar follow-up explicitamente OU arquivar.

Nada acontece sem intervenção humana explícita.

## Onde Se Encaixa

| Camada | Componente | Papel |
|---|---|---|
| Doc-mãe direta | `atlas-self-improvement-activation-cockpit-v1.md` | Cockpit visual do trecho activation. Level 7 adiciona backlog + closed-loop + result + next-cycle. |
| Doc-mãe governance | `atlas-self-improvement-governance-ladder.md` | 7 níveis de ladder. Level 7 implementa o nível alto: closed loop completo, before/after real. |
| Doc-mãe forge | `atlas-forge-continuum-os.md` | Forge continua sendo o executor. Level 7 fica antes (proposta) e depois (medição) — nunca substitui Forge. |
| Backend services | ProposalBacklog / ClosedLoop / ResultLedger / NextCycleRecommendation | 4 services novos; backlog + ledger persistem em Storage/json local. |
| Backend HTTP | 9 rotas em `/atlas-code/self-improvement/proposals*` + `result-ledger` + `next-cycle-recommendations` | Read-mostly + 2 mutações (proposal create, measure-result). |
| Backend CLI | `atlas:self-improvement:{proposal-backlog,closed-loop,measure-result,next-cycle}` | Operação local e CI. |
| Backend cert | `atlas_self_improvement_closed_loop_level7_certification` (31 invariantes) | Registrada em `ProgrammingProfessionalCompletionAuditService::report()`. |
| Desktop panel | `AtlasSelfImprovementLevel7Panel.tsx` (5 sections) | Substitui Activation Cockpit panel na aba `self_improvement`. |
| Desktop integration | `ObraCommandCenterPanel.tsx` (+ `SelfImprovementOriginCard`) | Bloco `self_improvement_origin` projetado per-Obra. |

## Contratos

Schemas canônicos:

```
atlas.self_improvement.proposal_backlog.v1           — read-model agregado
atlas.self_improvement.proposal_backlog_item.v1      — proposal item persistido
atlas.self_improvement.proposal_priority_decision.v1 — bucket + score
atlas.self_improvement.closed_loop.v1                — projection (12 stages)
atlas.self_improvement.result_ledger.v1              — read-model do ledger
atlas.self_improvement.result_entry.v1               — entry persistido (per measure-result)
atlas.self_improvement.learning_packet.v1            — sub-schema do result entry
atlas.self_improvement.next_cycle_recommendation.v1  — recomendação determinística
atlas.self_improvement.closed_loop_level7_certification.v1 — audit cert
atlas.code.obra_command_center_self_improvement_origin.v1 — bloco no Command Center
```

Status canônicos do Backlog Item:

```
draft / evaluating / needs_revision / pending_human_review /
approved_for_activation / activated / obra_created / forge_running /
awaiting_review / measuring_delta / learned / rejected / archived
```

Sources canônicos:

```
manual / chat / activation / postmortem / rivals /
regression_sentinel / trust_ledger / operator
```

Trust ledger outcomes adicionados:

```
self_improvement_major_improvement
self_improvement_improved
self_improvement_neutral
self_improvement_regressed
self_improvement_invalid_evidence
```

Delta grades (gerados em `record()`):

```
regressed / neutral / improved / major_improvement / invalid
```

Mapeamento outcome ← grade:
- `major_improvement` → `self_improvement_major_improvement` (trust_delta +1.0)
- `improved` → `self_improvement_improved` (trust_delta +0.4)
- `neutral` → `self_improvement_neutral` (trust_delta 0.0)
- `regressed` → `self_improvement_regressed` (trust_delta -0.5)
- `invalid` → `self_improvement_invalid_evidence` (trust_delta -0.2)

## Fluxo

```
1) createProposal(payload)
     → ProposalPacketService::build normaliza
     → status: draft
     → next_safe_action: evaluate_proposal_through_power_gate
     → NUNCA cria Obra

2) evaluateProposal(proposal_id)
     → ProposalPowerGateService::evaluate
     → status → pending_human_review | needs_revision | rejected | approved_for_activation
     → NUNCA cria Obra

3) prioritize(proposal_id)
     → StrategyPortfolioService::snapshot
     → strategy_bucket + priority_score
     → human_approval_required=true | auto_activation_allowed=false

4) [via Activation Cockpit] ForgeActivation::plan + accept
     → Obra criada com Intake preenchido
     → Backlog::markActivated + linkObra
     → status → obra_created

5) [via Forge Human Panel] Operador decide rodar Fast Path
     → Forge executa
     → Backlog::markForgeState(forge_running → awaiting_review)

6) measureResult(proposal_id, obra_id, before, after, reviewer, reason)
     → DeltaScorecardService::compute (13 métricas)
     → InvariantLockService::evaluate (8 invariantes)
     → RegressionSentinelService::scan (regressões + findings)
     → ResultLedgerService grava entry + learning packet
     → HumanTrustLedgerService::record(self_improvement_*)
     → Backlog::markDeltaMeasured(grade)
     → status → learned

7) Next-Cycle Recommendation
     → NextCycleRecommendationService::recommend
     → broaden_scope | continue | repair | gather | archive | promote_rule | followup
     → human_approval_required=true | proposed_next_proposal_payload (draft editável)
     → NUNCA persiste proposta automaticamente

8) Obra Command Center
     → self_improvement_origin block (proposal + activation + grade + human_message + measure_action)
     → null para Obras manuais
```

## Regras para IA

1. **Backlog não cria Obra.** SEMPRE delega `markActivated` / `linkObra` para quando o ForgeActivation::accept materializar.
2. **Closed-loop é leitura.** Para mutar, chame ProposalBacklog (evaluate/prioritize/archive/reject) ou ResultLedger (record), nunca ClosedLoopService.
3. **Result entry requer review humano.** `reviewer` + `reason` + `before_snapshot` + `after_snapshot` obrigatórios. Sem isso → blocked.
4. **Delta grade vem de evidência, não promoção.** `evidence_strength=invalid` → grade=invalid; invariant blocked → grade=regressed; hard_regression detected → grade=regressed.
5. **Next-cycle não persiste.** Sempre devolve um draft. Operador deve chamar `createProposal` explicitamente se quiser usar.
6. **Trust ledger outcomes são autoridade do nível de confiança.** Quando regredido, `trust_delta -0.5`; UI mostra band drop. Não invente.
7. **Obra Command Center origin é projeção.** Nunca duplique a fonte; sempre leia de `AtlasProject::metadata['self_improvement_activation']` + busca por `obra_id` em ResultLedger.
8. **Não toque external_rivals_certification.** Permanece `blocked_requires_operator_approval` independentemente do que Level 7 fizer.
9. **Não promova completion claim.** Result entry NUNCA promove; só registra outcome diagnóstico.
10. **Snapshots canônicos suportam dual-shape.** Caller pode passar `{metrics: {...}, completion_audit: {...}}` OU `{...flat scores...}`; service detecta automaticamente.

## Escopo de Implementacao

| Arquivo | Tipo | Responsabilidade |
|---|---|---|
| `app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php` | Service | CRUD + evaluate + prioritize + linkage helpers. Storage local + ledger mirror. |
| `app/Services/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopService.php` | Service | Projection determinística do estado proposal→learning. Read-only. |
| `app/Services/Ai/SelfImprovement/AtlasSelfImprovementResultLedgerService.php` | Service | Record entries com delta + invariant + regression real. Trust outcome lateral. |
| `app/Services/Ai/SelfImprovement/AtlasSelfImprovementNextCycleRecommendationService.php` | Service | Recomendação determinística + payload draft. Read-only. |
| `app/Services/Ai/SelfImprovement/AtlasSelfImprovementHumanTrustLedgerService.php` | Service (extended) | +5 OUTCOME_SELF_IMPROVEMENT_* constants + KNOWN_OUTCOMES entries. |
| `app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php` | Service (extended) | Inject `self_improvement_origin` block top-level. |
| `app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php` | Service (extended) | Registra `atlas_self_improvement_closed_loop_level7_certification`. |
| 4 controllers + 9 routes | HTTP | Backlog index/store/show/evaluate/prioritize · closed-loop show · result-ledger index/measure · next-cycle index. |
| 4 CLIs | Console | proposal-backlog · closed-loop · measure-result · next-cycle. |
| `tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopLevel7Test.php` | Tests | 22 tests cobrindo backlog/closed-loop/measure/grade/trust/command-center/safety invariants. |
| `apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementLevel7Panel.tsx` | UI | 5-section editorial linear panel (Backlog → Pipeline → Cockpit → Result → NextCycle). |
| `apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx` (+ SelfImprovementOriginCard) | UI (extended) | Bloco origem renderiza human_message + measure_result_action hint. |
| `packages/atlas-domain/src/index.ts` (+12 types) | Types | ProposalBacklog/Item/Filters, ClosedLoop/Stage, ResultLedger/Entry/Learning, NextCycle, AtlasCodeObraSelfImprovementOrigin. |
| `apps/desktop/src/lib/bridge.ts` (+9 actions + adapters) | Bridge | HTTP-only fallback; pattern de `getSelfImprovementTrustLedger`. |
| `apps/desktop/src/hooks/useBridge.ts` (+9 actions + 4 snapshot fields) | Hook | snapshot.selfImprovementProposalBacklog / ClosedLoop / ResultLedger / NextCycle. |
| `rightRailTypes.ts` + `RightRail.tsx` + `CodeSurface.tsx` + `rightRailRegistry.tsx` | Wiring | Propagar context + props + apontar tab `self_improvement` → Level7Panel. |

## Dependencias

| Dependência | Tipo | Por quê |
|---|---|---|
| `atlas-self-improvement-activation-cockpit-v1` | doc-mãe direta | Activation cockpit é embedded como section 3 do Level7Panel; mutations continuam servidas pelo cockpit endpoints. |
| `atlas-self-improvement-forge-activation-v1` | doc-mãe | Service v1 ainda materializa Obra; backlog só linka. |
| `atlas-self-improvement-governance-ladder` | doc-mãe governance | 7 níveis de ladder com PowerGate / InvariantLock / RegressionSentinel / MaturityScore / TrustLedger / StrategyPortfolio. Level 7 reusa TODOS. |
| `atlas-forge-continuum-os` | doc-mãe forge | Obra criada por activation continua governada pelo Forge; measure-result lê completion_audit canônico via InvariantLock. |
| `atlas-programming-forge-flow` | flow doc | Atlas Code surface continua o lar; Level 7 adiciona uma tab + integra com Command Center. |
| `atlas-code-forge-human-first-ux-orchestrator-v1` | UX canon | Pattern do painel humano (sections editoriais lineares + safety strip) que Level7Panel espelha. |
| `AtlasSelfImprovementProposalPacketService` | runtime | Backlog::createProposal normaliza via packet build. |
| `AtlasSelfImprovementProposalPowerGateService` | runtime | Backlog::evaluateProposal reusa evaluate(). |
| `AtlasSelfImprovementDeltaScorecardService` | runtime | ResultLedger::record reusa compute(13 metrics). |
| `AtlasSelfImprovementInvariantLockService` | runtime | ResultLedger::record reusa evaluate(after, diff, packet). |
| `AtlasSelfImprovementRegressionSentinelService` | runtime | ResultLedger::record reusa scan(before, after, diff). |
| `AtlasSelfImprovementHumanTrustLedgerService` | runtime | Estendido com 5 outcomes; ResultLedger::record grava self_improvement_*. |
| `AtlasSelfImprovementStrategyPortfolioService` | runtime | Backlog::prioritize + NextCycle::recommend usam snapshot(). |
| `AtlasCodeForgeWorkIntakeService` | runtime | ClosedLoop::project lê intake para evidence_collected stage. |
| `AtlasCodeObraCommandCenterService` | service estendido | `self_improvement_origin` block injetado em `snapshot()` quando metadata da Obra carrega `self_improvement_activation`. |
| `AtlasProject` (model) | model | Metadata `self_improvement_activation` é a fonte canônica da origem. |
| Pattern `Storage::disk('local')` + JSON + `_registry.json` + `atlas_ledger_events` mirror | persistence | Copy idiomático do ForgeActivationService. **Sem nova migration.** |

## Evidencias

`evidence_refs[]` no result entry referencia:
- `proposal:<id>` — proposal_id originador.
- `obra:<uuid>` — Obra materializada.
- `result_entry:<id>` — id deste entry (self-referência).
- `doc:<path>@<sha256>` — quando context.evidence_refs incluir docs.
- `activation:<id>` — quando linkado.
- `fast_path_run:<id>` — quando Backlog::markForgeState anotou.
- `completion_claim:<id>` — quando review humana foi registrada.
- `learning_packet:<what_changed>` — handle do learning.

Backlog evidence_refs:
- `activation:<id>`, `obra:<uuid>`, `fast_path_run:<id>`, `completion_claim:<id>`, `result_entry:<id>`.

Verificáveis via:
1. Filesystem (`atlas/self-improvement/proposal-backlog/*.json` + `_registry.json`, `atlas/self-improvement/result-ledger/*.json` + `_registry.json`).
2. Tabela `atlas_ledger_events` (event_type prefixado `SELF_IMPROVEMENT_PROPOSAL_BACKLOG_<STATUS>` / `SELF_IMPROVEMENT_RESULT_LEDGER_<GRADE>` / `SELF_IMPROVEMENT_TRUST_LEDGER_ENTRY`).
3. AtlasProject metadata (`self_improvement_activation`, `latest_atlas_code_forge_work_intake`, `atlas_self_improvement_human_trust_ledger`).
4. State projection `/atlas-code/works/{project}/obra-command-center` (campo `self_improvement_origin`).

## Riscos

| Risco | Mitigação |
|---|---|
| Operador pular evaluate e tentar ativar direto | Backlog::canActivate exige status=approved_for_activation ou pending_human_review + zero blockers. Cockpit accept também valida. |
| measure-result com snapshot inválido (só metrics, sem completion_audit) | Service aceita ambas shapes (dual-shape) — extrai `metrics` para delta, passa shape inteiro para invariant. Sem completion_audit, invariant retorna blocked → grade=regressed (defensivo). |
| Synthetic_scores promovendo grade fake | InvariantLockService checa `completion_audit.rules.synthetic_scores_allowed=false`. ResultLedger nunca confia em evidência sem refs. |
| Regressed grade tentando virar learning_packet usável | `should_become_rule=false` quando grade != major_improvement. Learning packet ainda grava `what_failed_or_was_missing` para auditoria. |
| Next-cycle criar proposta sem operador | API/CLI **só** retorna `proposed_next_proposal_payload` como draft. Para persistir, operador chama `createProposal` explicitamente. |
| Cert quebrar quando painel desktop é removido | Invariante `desktop_cockpit_available` cai para false → status `backend_available_ui_pending`. |
| Trust ledger inundado de outcomes self_improvement_* | Service tem dedupe 30s + cap 100 entries por Obra. |
| External rivals desbloqueado por descuido | Invariante `external_rivals_separated=true` + teste `test_external_rivals_certification_remains_separated` previnem. |
| Closed-loop projeção inconsistente quando proposal apaga | `project()` retorna status='blocked' + blockers=['proposal_not_found'] + 404 na API. |

## Exemplos

**Listar backlog filtrado por status:**
```bash
php artisan atlas:self-improvement:proposal-backlog --status=pending_human_review --json --strict
```

**Criar proposta:**
```bash
php artisan atlas:self-improvement:proposal-backlog \
  --create \
  --proposal=@payload.json \
  --json --strict
```

**Avaliar via Power Gate:**
```bash
php artisan atlas:self-improvement:proposal-backlog \
  --proposal=prop_01HXXXX --evaluate --json --strict
```

**Projetar closed loop:**
```bash
php artisan atlas:self-improvement:closed-loop \
  --proposal=prop_01HXXXX --json --strict
```

**Medir resultado pós-Obra:**
```bash
php artisan atlas:self-improvement:measure-result \
  --proposal=prop_01HXXXX \
  --obra=550e8400-e29b-41d4-a716-446655440000 \
  --before=@before-snapshot.json \
  --after=@after-snapshot.json \
  --reviewer=vitorepf \
  --reason="major improvement: provider capacity now reaches maturity 9 with full evidence" \
  --json --strict
```

**Próximo ciclo recomendado (latest result):**
```bash
php artisan atlas:self-improvement:next-cycle --latest --json --strict
```

**Endpoint HTTP — listar backlog:**
```bash
curl -H "X-Atlas-Token: $TOKEN" \
  "http://localhost:8000/atlas-code/self-improvement/proposals?status=pending_human_review"
```

**Endpoint HTTP — closed loop:**
```bash
curl -H "X-Atlas-Token: $TOKEN" \
  "http://localhost:8000/atlas-code/self-improvement/proposals/prop_01HXXXX/closed-loop"
```

**Endpoint HTTP — measure result:**
```bash
curl -X POST -H "X-Atlas-Token: $TOKEN" -H "Content-Type: application/json" \
  "http://localhost:8000/atlas-code/self-improvement/proposals/prop_01HXXXX/measure-result" \
  -d '{"obra_id":"...","before_snapshot":{...},"after_snapshot":{...},"reviewer":"...","reason":"..."}'
```

## Limites

- Sources do backlog: apenas `manual` e `operator` funcionam end-to-end na v1. Outras sources (postmortem/rivals/regression_sentinel/trust_ledger) ficam aceitas no schema mas exigem hook implementado em release futuro.
- Tauri commands nativos não implementados nesta versão (HTTP fallback documentado e estável).
- Sem nova migration; toda persistência via Storage local + ledger best-effort.
- Cockpit existente da activation é embedded como section 3; não há extração de "Section" separada (mantém o painel atual operável standalone).
- Smoke UI manual: sem testes E2E desktop novos (harness inexistente).
- Tasks de bloco F1 (extract section) e F4 (lint+build) tiveram tratamento pragmático: section embedding direto + lightningcss build issue pré-existente isolado (tsc verde).

## Proximas Acoes

1. Implementar hook automático: regression sentinel → `createProposal` com source=regression_sentinel quando severity=severe.
2. Implementar hook automático: trust ledger band drop → sugestão de proposal source=trust_ledger.
3. Dashboard de "rule candidates promovidos" agregando learning_packet.new_rule_candidate ao longo do tempo.
4. Tauri commands nativos para mutações Level 7 (`bridge_list_self_improvement_proposals`, etc) — HTTP fallback continua válido como hoje.
5. Integração Cartografia: drill-down do `evidence_refs[]` para abrir doc/ledger event correspondente em outra surface.
6. Expor "Iniciar Fast Path" como ação separada com confirmação dupla + budget approval quando dispatcher estiver pronto (continua bloqueado na Level 7 v1).
