---
id: atlas-aaeos-acos-elite-simplify-lane
type: engineering_knowledge
title: AAEOS+ACOS Elite Simplify Lane (24/7 Autônomos)
status: active
category: autonomous-evolution
priority: 95
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
summary: "Lane contínua Autônomos escopada só a AAEOS+ACOS com Contrato Elite: simplificar/defatorar com prova de shrink + consumers intactos + ELEV-31. Motor = atlas:brain:* / atlas:task:*. Nunca mass-delete, nunca KPI de LOC, nunca religar ACDE."
tags:
  - atlas-ai
  - aaeos
  - acos
  - autonomos
  - elite
  - simplification
capabilities:
  - aaeos_acos_elite_simplify_lane
  - scoped_refactor_proof_enforce
  - continuous_simplify_cycle_plan
decisions:
  - Scope slug aaeos_acos cobre Aaeos + AgenticEngineeringOs + AcosMax + Cognition.
  - refactor_proof_mode=enforce e refactor_design_spec_required=true só via scope_overrides.aaeos_acos.
  - Deleção exige SafeDeletionPlanner + ConsumerImpact; Generated AAEOS segue elite compaction quarantine.
  - Schedule do tick default OFF (ATLAS_AAEOS_ACOS_SIMPLIFY_CYCLE_SCHEDULE_ENABLED).
  - Orquestração ≠ soberania zero-operador; workers externos continuam o músculo.
maintenance:
  - Atualizar allowlist quando roots AAEOS/ACOS mudarem.
  - Não alargar scope_overrides para outros scopes sem decisão do operador.
related_paths:
  - config/atlas.php
  - config/atlas_task_governance.php
  - app/Services/Ai/SelfConstruction/Governance/AtlasAaeosAcosLaneScope.php
  - app/Services/Ai/SelfConstruction/ContinuousRuntime/AtlasAaeosAcosSimplifyCyclePlanner.php
  - app/Services/Ai/SelfConstruction/Simplification/AtlasAaeosAcosSimplificationLane.php
  - app/Console/Commands/AtlasAaeosAcosSimplifyCycleCommand.php
graph_id: atlas-aaeos-acos-elite-simplify-lane
graph_title: AAEOS ACOS Elite Simplify Lane
graph_world: atlas
graph_kind: system
graph_parent: atlas-autonomos-live-system
graph_status: active
graph_source: repo
owner: atlas-ai
human_name: AAEOS ACOS Elite Simplify Lane
canonical_name: AAEOS ACOS Elite Simplify Lane
technical_name: atlas-aaeos-acos-elite-simplify-lane
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-aaeos-acos-elite-simplify-lane.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-acos-elite-simplify-lane.md
allowed_changes:
  - Atualizar allowlist, kill switches e contrato elite quando o código da lane mudar.
forbidden_changes:
  - Tratar LOC bruto ou volume de tasks como sucesso.
  - Religar atlas:loop:* / ACDE.
  - Mass-delete AtlasLoop* keep-list ou AAEOS Generated sem quarantine policy.
depends_on:
  - atlas-autonomos-live-system
  - atlas-agentic-engineering-os
  - atlas-cognition-operating-system
flows_to:
  - atlas-open-gaps-regressions-ledger
unlocks:
  - continuous-aaeos-acos-elite-simplify
governs:
  - aaeos-acos-simplify-lane
evidence:
  - app/Console/Commands/AtlasAaeosAcosSimplifyCycleCommand.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Operador arma schedule + brain/task switches quando quiser cadência 24/7.
---

# AAEOS+ACOS Elite Simplify Lane (24/7)

## Onde Se Encaixa

Lane do Autônomos vivo (`atlas:brain:*` → `atlas:task:*`) com alvo de mutação **somente** AAEOS + ACOS.
Não é o Loop/ACDE morto. Não declara soberania zero-operador.

## Contrato Elite (pétreo)

1. Impacto real em órgão AAEOS ou área ACOS (anti-Goodhart).
2. Shrink provado (`AtlasRefactorDeltaProver`) — move-only / wrapper-only recusados em enforce.
3. Consumers intactos (`ConsumerImpactAnalyzer` / SafeDeletionPlanner).
4. ELEV-31: comparar `{não fazer nada, simplificar, remover uma camada}` — **seed bloqueia** evolução estrutural em `aaeos_acos` sem `alternatives_compared` (`AtlasBrainSeedQualityGate`).
5. Confiabilidade sobe ou fica (testes pareados).

**KPIs proibidos:** LOC bruto, task volume, queue depth cosmética, green self-report.

## Allowlist

| Tipo | Paths |
|---|---|
| Código | `app/Services/Ai/Aaeos/`, `AgenticEngineeringOs/`, `AcosMax/`, `Cognition/` |
| Docs | `atlas-agentic-engineering-os*`, `atlas-aaeos-*`, `atlas-cognition-operating-system.md`, `atlas-acos-*` |
| Fora | Forge, Programming, voice, desktop, rivals, cartografia, `routes/api.php` |

## Fluxo

```text
atlas:acos:simplify-cycle plan
  → brain:next aaeos_acos
  → brain:seed (dry → real)
  → replenish se fila seca (sem proxy)
  → brain:worker-prompt + task:worker-prompt
  → worker implementa allowed_files
  → report --commit (refactor_proof enforce nesta lane)
```

## Kill switches

- `ATLAS_BRAIN_MASTER_ENABLED`
- `ATLAS_TASK_SERVING_ENABLED`
- `ATLAS_AAEOS_ACOS_SIMPLIFY_CYCLE_SCHEDULE_ENABLED` (schedule default OFF)

## Exemplos

```bash
php artisan atlas:acos:simplify-cycle plan --json
php artisan atlas:brain:next aaeos_acos --json
php artisan atlas:brain:worker-prompt --scope=aaeos_acos
```

## Lição pétrea

A “defatoração grok” (`cd018c6b3f`) deletou 771 classes e pendurou consumers vivos.
Nesta lane: **de-confusão com prova**, nunca mass-delete.
