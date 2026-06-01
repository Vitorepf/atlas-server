---
id: atlas-autonomy-ladder-promotion-runbook
type: engineering_knowledge
title: Atlas Autonomy Ladder Promotion Runbook
status: active
category: atlas-ai
priority: 101
summary: Runbook canonico que define criterios EXECUTAVEIS para promocao entre os 8 niveis da Autonomy Ladder do Atlas (L0 Assist ate L7 Self-Evolving). Cada nivel tem entry criteria, exit criteria mensuraveis, evidencia obrigatoria, dual signature requirements e rollback automatico em caso de regressao.
tags:
  - atlas-ai
  - autonomy-ladder
  - promotion-criteria
  - runbook
  - governance
  - self-improvement
  - dual-signature
capabilities:
  - autonomy_promotion_governance
  - measurable_promotion_criteria
  - automatic_demotion_runtime
  - dual_signature_enforcement
  - autonomy_evidence_audit
decisions:
  - Promocao L<n> -> L<n+1> exige criterios mensuraveis declarados aqui; sem criterio nao ha promocao.
  - Promocao L4+ exige dual signature operador + Architect.
  - Demote automatico se metrica regride por >2 ciclos consecutivos.
  - Trust Ledger e a fonte de verdade para historico de promocoes.
maintenance:
  - Atualize antes de adicionar nivel novo, mudar criterio, alterar threshold ou modificar dual signature policy.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-mission-control-cockpit-spec.md
  - docs/engineering-knowledge-base/atlas-trust-ledger-canonical.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomy-ladder-promotion-runbook
graph_title: Atlas Autonomy Ladder Promotion Runbook
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas Autonomy Ladder Promotion Runbook
canonical_name: Atlas Autonomy Ladder Promotion Runbook
technical_name: atlas-autonomy-ladder-promotion-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
owner: atlas-ai
product_name: Atlas Autonomy Ladder Promotion Runbook
internal_product_name: AAEOS Autonomy Ladder Promotion
runtime_acronym: AAEOS-ALPR
technical_runtime: atlas.aaeos.autonomy_ladder
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
allowed_changes:
  - Refinar criterios, thresholds, evidencia exigida.
forbidden_changes:
  - Permitir promocao L4+ sem dual signature.
  - Remover demote automatico em caso de regressao.
depends_on:
  - atlas-agentic-engineering-os
  - atlas-mission-control-cockpit-spec
flows_to:
  - atlas-trust-ledger-canonical
  - atlas-aaeos-department-maturity-matrix
unlocks:
  - autonomy-promotion-runtime
  - automatic-demotion-runtime
governs:
  - atlas_ai.autonomy_ladder.promotion
evidence:
  - docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
implementation_state: partial
evidence_refs:
  - symbol: AtlasAutonomyLadderRuntimeService
  - command: atlas:autonomy:ladder
  - test: AtlasAutonomyLadderRuntimeServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - runbook
  - autonomy
quality_gates:
  - all-8-levels-have-entry-and-exit-criteria
  - all-thresholds-measurable
  - dual-signature-l4-plus-enforced
  - demote-on-regression-implemented
failure_modes:
  - Promocao sem criterio mensuravel.
  - Demote nao acionado em regressao.
  - Single signature em L4+.
observability_signals:
  - autonomy_current_level
  - autonomy_promotion_attempts
  - autonomy_demote_count
  - autonomy_dual_signature_failures
next_actions:
  - Implementar `AtlasAutonomyLadderRuntimeService` com promote, demote, criteria evaluation.
  - Implementar `php artisan atlas:autonomy:status --json`.
---
# Atlas Autonomy Ladder Promotion Runbook

## Resumo

Runbook canonico com criterios executaveis para promover entre os 8 niveis da Autonomy Ladder. Cada nivel: entry criteria, exit criteria mensuraveis, evidencia, dual signature, demote automatico.

## Papel no Atlas

A doc-mae lista L0-L7 como conceito. Este runbook **transforma conceito em criterio executavel** que `AtlasAutonomyLadderRuntimeService` consulta antes de aprovar promocao.

## Onde Se Encaixa

```text
atlas-agentic-engineering-os
  +-- atlas-autonomy-ladder-promotion-runbook (este doc)
  +-- atlas-trust-ledger-canonical (historico)
```

## Contratos

### Os 8 niveis canonicos com criterios

| Nivel | Nome | Entry criteria (mensuraveis) | Exit / promotion criteria |
|-------|------|------------------------------|---------------------------|
| L0 | Assist | operador faz tudo, IA so completa codigo | 50 sessoes assist com >=80% acceptance, 0 hallucination grave |
| L1 | Slice Co-Pilot | IA edita 1-3 arquivos com escopo declarado | 20 slices consecutivos verdes, scope_violation_count=0, repair_loop_count<=1 |
| L2 | Multi-Slice Pair | 3-10 arquivos, pair com reviewer | 30 obras pair verdes, regression_catch_rate>=0.9 |
| L3 | Feature Owner | feature completa R3 com gates universais | 15 features cert verde, blocker_in_review<=1 por feature |
| L4 | Obra Owner | obra de uma semana R4 com paralelismo | 5 Obras consecutivas cert verde, 0 rollback no cert phase, dual_signature_count=5 |
| L5 | Department Owner | depto inteiro autonomo (ex.: dev autocontido) | 90 dias sem intervention humana fora de gestures, depto.maturity>=L4 |
| L6 | Multi-Department Conductor | conduz 3+ departamentos simultaneamente | 30 dias com 3+ depts ativos, cross_dept_blocker_resolution_p95<=2h |
| L7 | Self-Evolving | propoe e implementa proprio refator (com gate) | 10 self-construction propostas aprovadas, 0 invariante quebrada, Trust Ledger score >=0.95 |

### Schema canonico (`atlas.autonomy.promotion_request.v1`)

```text
{
  "schema": "atlas.autonomy.promotion_request.v1",
  "request_id": "<uuid>",
  "from_level": "L<n>",
  "to_level": "L<n+1>",
  "evidence": {
    "metric_id": "<value>",
    "obras_count": <int>,
    "regression_count": <int>,
    "blocker_resolution_p95_hours": <float>
  },
  "trust_ledger_score": <float>,
  "operator_signature": "<sig>",
  "architect_signature": "<sig_or_null>",
  "rationale": "<string>",
  "rollback_window_seconds": <int>
}
```

### Demote automatico

Trigger: 2 ciclos consecutivos com qualquer metrica de exit_criteria abaixo de threshold. Acao: demote L<n> -> L<n-1> + Operator notification + receipt automatico.

## Fluxo

```mermaid
stateDiagram-v2
  L0 --> L1: criteria + receipt
  L1 --> L2
  L2 --> L3
  L3 --> L4: criteria + dual_sig
  L4 --> L5: criteria + dual_sig
  L5 --> L6: criteria + dual_sig + Architect
  L6 --> L7: criteria + dual_sig + Architect + Trust>=0.95

  L4 --> L3: regression
  L5 --> L4: regression
  L6 --> L5: regression
  L7 --> L6: regression
```

## Regras para IA

- Promocao L<=3 com single operator signature.
- Promocao L4+ com dual signature (Operador + Architect agent).
- Promocao L6+ adicional Architect human review obrigatorio.
- Demote nunca exige signature; e automatico via metrica.
- Toda promocao registra entry no Trust Ledger.

## Escopo de Implementacao

`AtlasAutonomyLadderRuntimeService`, `AtlasAutonomyMetricsAggregator`, `AtlasAutonomyDemoteWatchdog`.

## Dependencias

Trust Ledger (T4.2), Department Maturity Matrix (T2.3), Mission Control Cockpit (T1.5).

## Evidencias

Comando esperado: `php artisan atlas:autonomy:status --json`. Suite: `tests/Feature/Autonomy/`.

## Riscos

Promocao prematura, demote false-positive, drift entre metrica observada e aplicada.

## O que este doc NAO e

Nao define os 8 niveis em conceito (isso e `atlas-agentic-engineering-os.md`); define **como promover** entre niveis.

## Exemplos

Promocao L3 -> L4 envia `promotion_request.v1` com 15 features cert verde + dual signature; runtime valida e atualiza estado.

## Proximas Acoes

1. Implementar `AtlasAutonomyLadderRuntimeService`.
2. Implementar telemetria por criterio.
3. Implementar demote watchdog.
