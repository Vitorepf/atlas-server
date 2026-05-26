---
title: Atlas Antifragility Composition Metric
slug: atlas-antifragility-composition-metric
status: building
risk_level: medium
graph_parent: atlas-cognition-operating-system
depends_on:
  - atlas-cognition-operating-system
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
  - atlas-autonomous-reconciliation-runtime
  - atlas-teos-i4-counterfactual-tree
authority_class: read_model
forbidden_changes:
  - estimate_provider_capability_n
  - claim_winner_or_rivals
  - synthesize_n_from_external_benchmarks
schema:
  - atlas.antifragility.composition_metric.v1
---

# Atlas Antifragility Composition Metric

Mede honestamente o multiplicador **M** do wrapper (resultado = N × M, equação canônica do Atlas). NÃO mede ou estima N (capacidade do provider) — fica em zero claim sobre comparação externa.

## Componentes de M (cada ∈ [0,1])
- `m_scorecard` = overall/10 (estrutura ready)
- `m_observability` = log10(1 + admissions + ticks + trees + consults) / 3 (atividade runtime)
- `m_governance` = 1 - (violations / total_events) (kernel intactness)
- `m_density` = subsystem_count / canonical_baseline (cobertura)

M composto = média geométrica dos componentes.

## Claim policy hardcoded
- benchmark_claim_allowed=false
- rivals_claim_allowed=false
- superiority_claim_allowed=false
- provider_capability_estimated=false

## Storage
Stateless (não persiste).

## CLI (futura — hoje só via state aggregator)
Snapshot exposto em `GET /atlas/patamar4/state.antifragility`.
