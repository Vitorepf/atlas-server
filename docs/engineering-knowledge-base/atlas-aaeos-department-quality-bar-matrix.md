---
id: atlas-aaeos-department-quality-bar-matrix
type: engineering_knowledge
title: Atlas AAEOS Department Quality Bar Matrix
status: active
category: atlas-ai
priority: 101
summary: Matriz canonica de SLA / SLO / quality bar por departamento e por nivel de maturidade. Define o que e "L5 Department" mensuravelmente: P50/P95 latency, taxa de blocker real, taxa de rollback, evidence completeness, cert hash freshness. Substitui prosa por thresholds numericos.
tags:
  - atlas-ai
  - quality-bar
  - sla
  - slo
  - department-metrics
  - thresholds
capabilities:
  - department_quality_bar
  - sla_slo_governance
  - measurable_promotion_floor
decisions:
  - Cada departamento tem thresholds numericos por nivel.
  - Thresholds sao floor; runtime mede e gates aprovam.
maintenance:
  - Atualize ao mudar threshold ou metrica.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-department-contract.md
  - docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-aaeos-department-quality-bar-matrix
graph_title: Atlas AAEOS Department Quality Bar Matrix
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas AAEOS Department Quality Bar Matrix
canonical_name: Atlas AAEOS Department Quality Bar Matrix
technical_name: atlas-aaeos-department-quality-bar-matrix
cartography_type: matrix
canonical_source: docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md
owner: atlas-ai
product_name: Atlas AAEOS Department Quality Bar Matrix
internal_product_name: AAEOS Department Quality Bar Matrix
runtime_acronym: AAEOS-QBM
technical_runtime: atlas.aaeos.quality_bar
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md
  - app/Services/Ai/Aaeos/AtlasAaeosThresholdComparator.php
  - app/Services/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizer.php
  - app/Services/Ai/Aaeos/AtlasAaeosDepartmentQualityBarLevelClassifier.php
  - tests/Unit/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizerTest.php
allowed_changes:
  - Refinar thresholds, adicionar metrica.
forbidden_changes:
  - Permitir promote sem hit no threshold.
depends_on:
  - atlas-agentic-engineering-os-department-contract
  - atlas-aaeos-department-maturity-matrix
flows_to:
  - atlas-autonomy-ladder-promotion-runbook
unlocks:
  - dept-quality-bar-runtime
governs:
  - atlas_ai.aaeos.quality_bar
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md
implementation_state: spec
evidence_refs:
  - symbol: AtlasAaeosQualityBarService
  - command: atlas:aeos:department-status
  - test: AtlasAaeosQualityBarServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - matrix
  - quality-bar
quality_gates:
  - all-departments-have-thresholds
  - all-levels-defined
failure_modes:
  - Threshold ausente.
  - Metrica nao implementada.
observability_signals:
  - dept_quality_bar_breach_count
next_actions:
  - Implementar `php artisan atlas:aaeos:quality-bar --json`.
---
# Atlas AAEOS Department Quality Bar Matrix

## Resumo

Quality bar mensuravel por departamento por nivel.

## Papel no Atlas

T2.3 maturity matrix diz qual nivel; este doc diz **o que prova nivel**.

## Onde Se Encaixa

```text
atlas-agentic-engineering-os-department-contract (define depto)
  +-- atlas-aaeos-department-maturity-matrix (estado atual)
  +-- atlas-aaeos-department-quality-bar-matrix (este doc, thresholds)
```

## Contratos

### Quality bar por departamento por nivel (snapshot)

#### Dev

| Nivel | Latency p95 | Tests pass rate | Scope violation rate | Repair loop avg |
|-------|-------------|-----------------|---------------------|-----------------|
| L0-L1 | <=120s | >=0.85 | <=0.05 | <=2 |
| L2 | <=90s | >=0.92 | <=0.02 | <=1.5 |
| L3 | <=60s | >=0.96 | <=0.01 | <=1 |
| L4+ | <=45s | >=0.98 | 0 | <=0.5 |

#### Forge

| Nivel | Obra completion rate | Cert pass rate | Rollback rate | Multi-agent collision rate |
|-------|---------------------|----------------|---------------|----------------------------|
| L0-L2 | >=0.7 | >=0.85 | <=0.10 | <=0.05 |
| L3 | >=0.85 | >=0.93 | <=0.04 | <=0.02 |
| L4 | >=0.93 | >=0.97 | <=0.02 | <=0.01 |
| L5+ | >=0.97 | >=0.99 | <=0.005 | 0 |

#### Security

| Nivel | False positive rate | False negative count | Mean time to deny |
|-------|---------------------|----------------------|-------------------|
| L0-L2 | <=0.20 | <=2/mo | <=10s |
| L3 | <=0.10 | <=1/mo | <=5s |
| L4+ | <=0.05 | 0 | <=2s |

#### Review

| Nivel | Findings catch rate | Cycle time p95 | Veto false rate |
|-------|---------------------|----------------|-----------------|
| L0-L2 | >=0.7 | <=24h | <=0.1 |
| L3 | >=0.85 | <=8h | <=0.05 |
| L4+ | >=0.95 | <=2h | <=0.02 |

#### QA

| Nivel | Coverage p50 | Regression catch rate | Contract test count |
|-------|--------------|-----------------------|---------------------|
| L0-L2 | >=0.6 | >=0.7 | >=0 |
| L3 | >=0.75 | >=0.9 | >=10 |
| L4+ | >=0.85 | >=0.97 | >=30 |

#### Memory

| Nivel | Promotion accuracy | Quarantine rate | Retrieval latency p95 |
|-------|--------------------|------------------|------------------------|
| L0-L2 | >=0.75 | <=0.2 | <=2s |
| L3 | >=0.88 | <=0.1 | <=1s |
| L4+ | >=0.95 | <=0.05 | <=400ms |

(Demais departamentos com thresholds analogos; ver registro completo via comando.)

### Schema (`atlas.aaeos.quality_bar.v1`)

```text
{
  "schema": "atlas.aaeos.quality_bar.v1",
  "department_id": "<id>",
  "level": "L<n>",
  "thresholds": [
    {"metric": "<id>", "comparator": ">=|<=", "value": <number>, "unit": "<unit>"}
  ],
  "evaluated_window_days": 30,
  "evaluator_service": "<service_class>"
}
```

## Fluxo

```mermaid
flowchart LR
  Eval[runtime metrics window=30d]
  Eval --> Compare[compare vs threshold]
  Compare -->|all met| Promote[eligible promotion]
  Compare -->|breach| Block[block promotion + signal]
```

## Regras para IA

- Promocao bloqueada se ANY metric breach.
- Threshold breach gera signal `dept_quality_bar_breach_count`.
- Re-eval mensal automatico.

## Escopo de Implementacao

`AtlasAaeosQualityBarService`, comando `atlas:aaeos:quality-bar --json`.

## Dependencias

Maturity Matrix (T2.3), Department Contract (T1.2).

## Evidencias

Comando + dashboards + ledger.

## Riscos

Threshold otimista, metrica nao instrumentada, breach silencioso.

## O que este doc NAO e

Nao registra estado (T2.3); define thresholds.

## Exemplos

Forge L4 requer obra_completion_rate >=0.93. Rate atual 0.91 -> bloqueia promocao L5.

## Proximas Acoes

1. Implementar comando.
2. Telemetria por departamento.
3. Integrar com cockpit.
