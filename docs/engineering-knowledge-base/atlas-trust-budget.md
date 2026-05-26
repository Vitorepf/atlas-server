---
id: atlas-trust-budget
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Trust Budget
slug: atlas-trust-budget
status: building
implementation_state: runtime_available
category: governance
priority: 97
summary: Tiered daily budget (low_risk/medium_risk/high_risk/critical × operator/autonomous_agent/external) com reset diário UTC, rollback explícito e receipt JSONL.
tags: [atlas-ai, governance, trust, budget, rollback, patamar-4]
capabilities: [tiered_budget, daily_reset, action_rollback, append_only_receipt, allow_deny_verdict]
decisions:
  - 4 tiers × 3 operator_class = 12 budget caps canon.
  - Reset por dia UTC (YYYY-MM-DD); sem carry-over.
  - critical tier autonomous_agent = 0 (operator-only).
  - rollback nunca aumenta consumption acima do cap.
  - Mutation do canonical table só via PR + redeploy.
maintenance:
  - Calibrar caps após observação real (não chutar).
  - Auditar receipts JSONL quando operator suspeitar de drift.
risk_level: high
owner: atlas-ai
graph_id: atlas-trust-budget
graph_title: Atlas Trust Budget
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-autonomy-admission
graph_status: building
graph_source: repo
depends_on: [atlas-constitutional-kernel, atlas-autonomy-admission]
flows_to: [atlas-autonomy-admission]
unlocks: [tiered_action_budget, explicit_rollback_audit]
governs: [mutative_action_daily_allowance]
authority_class: budget
related_paths:
  - app/Services/Ai/Governance/AtlasTrustBudgetService.php
  - app/Console/Commands/AtlasTrustBudgetCommand.php
  - tests/Unit/Ai/Governance/AtlasTrustBudgetServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-trust-budget.md
  - app/Services/Ai/Governance/AtlasTrustBudgetService.php
evidence:
  - app/Services/Ai/Governance/AtlasTrustBudgetService.php
  - tests/Unit/Ai/Governance/AtlasTrustBudgetServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/Governance/AtlasTrustBudgetServiceTest.php"
next_actions:
  - Wirar Admission para consultar Trust Budget antes de allow_autonomous.
allowed_changes:
  - Calibrar caps com receipt.
forbidden_changes:
  - carry_over_unused_budget
  - critical_autonomous_above_zero
  - rollback_above_cap
requires_evidence: true
line_limit: 480
schema:
  - atlas.trust_budget.consume_receipt.v1
  - atlas.trust_budget.rollback_receipt.v1
  - atlas.trust_budget.daily_state.v1
---

# Atlas Trust Budget

## Resumo

Caps por (tier × operator_class) × dia UTC. Cada consume gera receipt; cada rollback emite receipt reverso. Verdict canon = allow | deny_budget_exceeded | deny_unknown_tier | deny_unknown_class.

## Papel no Atlas

Operator define explicitamente quanto Atlas pode fazer sozinho por dia sem ser chamado. Critical tier autonomous = 0; medium/high low quando autonomous; alto quando operator manual.

## Onde Se Encaixa

- Consume: `consume({tier, operator_class, action_kind, actor, reason})` → receipt
- Check pre-call: `check(tier, operator_class)` → verdict sem registrar
- Rollback: `rollback(action_id, actor, reason)` → reverso
- State: `state(tier, operator_class)` → cap, consumed_net, remaining

## Contratos

- `atlas.trust_budget.consume_receipt.v1` — receipt do consumo
- `atlas.trust_budget.rollback_receipt.v1` — rollback receipt
- `atlas.trust_budget.daily_state.v1` — estado agregado do dia

## Fluxo

1. Caller (Reconciliation, ASCB.propose, Admission) chama `check(tier, class)` antes
2. Se allow → caller chama `consume(...)` e prossegue
3. Se algo der errado → caller chama `rollback(action_id, ...)`
4. Operator audita via `list` ou `state`

## Regras para IA

- NUNCA consumir tier 'critical' como autonomous_agent — cap=0 sempre.
- NUNCA rollback fora do mesmo dia (rollback ainda funciona, mas conta zero efeito após reset).
- NUNCA mutar canonical_budget runtime.

## Escopo de Implementacao

Service + CLI 6 actions + tests + receipt JSONL append-only.

## Dependencias

- AtlasConstitutionalKernelService (advisor)
- AtlasAutonomyAdmissionService (consumer principal — wiring futuro)

## Evidencias

Service file + tests + CLI + receipts JSONL.

## Riscos

- Operator esquece de rodar rollback após falha → consumed_net inflado. Mitigação: list + audit periódico.
- Race entre 2 callers no mesmo segundo → ambos consume → consumed_net pode passar cap por 1. Mitigação aceita (audit detecta).

## Exemplos

```bash
php artisan atlas:trust-budget --action=canon --json
php artisan atlas:trust-budget --action=consume \
  --tier=medium_risk --operator-class=autonomous_agent \
  --action-kind=ascb_propose --actor=reconciliation \
  --reason="autonomous propose for group aucri" --json
```

## Proximas Acoes

Wire Admission.admit() consultar Trust Budget antes de allow_autonomous.

## Safety

- claim_policy provider-safe.
- Append-only JSONL.
- Operator-only critical tier.
- Reset diário UTC honesto.
