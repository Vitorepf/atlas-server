---
id: atlas-antifragility-composition-metric
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Antifragility Composition Metric
slug: atlas-antifragility-composition-metric
status: building
implementation_state: runtime_available_read_model
category: compounding
priority: 89
summary: Read-model que mede apenas o multiplicador interno M do Atlas a partir de scorecard, eventos e governanca, sem estimar capacidade de provider ou fazer claims externos.
tags: [atlas-ai, compounding, antifragility, metric, claim-policy]
capabilities: [antifragility_m_metric, wrapper_only_composition_metric, provider_claim_lock]
decisions:
  - A metrica mede M, nao N; capacidade de provider externo nunca e estimada.
  - Claims de benchmark, rivals e superiority permanecem false.
  - Atividade runtime entra como observability signal, nao como prova de superioridade.
maintenance:
  - Atualizar antes de mudar componentes de M, claim policy ou state aggregator.
  - Manter testes provando intervalo [0,1] e flags de claim bloqueadas.
risk_level: medium
owner: atlas-ai
graph_id: atlas-antifragility-composition-metric
human_name: Atlas Antifragility Composition Metric
canonical_name: Atlas Antifragility Composition Metric
technical_name: AtlasAntifragilityCompositionMetricService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-antifragility-composition-metric.md
graph_title: Atlas Antifragility Composition Metric
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-cognition-operating-system
graph_status: building
graph_source: repo
depends_on: [atlas-cognition-operating-system, atlas-constitutional-kernel, atlas-autonomy-admission, atlas-autonomous-reconciliation-runtime, atlas-teos-i4-counterfactual-tree]
flows_to: [atlas-patamar-4-substrato-cognitivo-autonomo]
unlocks: [wrapper_only_antifragility_snapshot, patamar4_state_metric]
governs: [antifragility_composition_metric]
authority_class: read_model
related_paths:
  - docs/engineering-knowledge-base/atlas-antifragility-composition-metric.md
  - docs/engineering-knowledge-base/atlas-patamar-4-substrato-cognitivo-autonomo.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - app/Services/Ai/Compounding/AtlasAntifragilityCompositionMetricService.php
  - app/Services/Ai/Patamar4/AtlasPatamar4StateService.php
  - tests/Unit/Ai/Compounding/AtlasAntifragilityCompositionMetricServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-antifragility-composition-metric.md
  - app/Services/Ai/Compounding/AtlasAntifragilityCompositionMetricService.php
evidence:
  - app/Services/Ai/Compounding/AtlasAntifragilityCompositionMetricService.php
  - tests/Unit/Ai/Compounding/AtlasAntifragilityCompositionMetricServiceTest.php
  - tests/Feature/Integration/AtlasPatamar4LoopIntegrationTest.php
evidence_refs:
  - symbol: AtlasAntifragilityCompositionMetricService
  - command: atlas:compounding:antifragility-metric
  - test: AtlasAntifragilityCompositionMetricServiceTest
required_tests:
  - "php artisan test tests/Unit/Ai/Compounding/AtlasAntifragilityCompositionMetricServiceTest.php"
  - "php artisan test tests/Feature/Integration/AtlasPatamar4LoopIntegrationTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Keep metric exposed through Patamar 4 state without external comparison claims.
allowed_changes:
  - Add internal M components only with tests and claim_policy unchanged.
forbidden_changes:
  - estimate_provider_capability_n
  - claim_winner_or_rivals
  - synthesize_n_from_external_benchmarks
requires_evidence: true
line_limit: 520
schema:
  - atlas.antifragility.composition_metric.v1
---

# Atlas Antifragility Composition Metric

## Resumo

Read-model que mede o multiplicador interno M do Atlas, sem medir provider externo.

## Papel no Atlas

Dar um snapshot honesto de antifragilidade operacional para Patamar 4 sem abrir claim de benchmark.

## Onde Se Encaixa

Fica no state aggregator Patamar 4 e consome scorecard, eventos e governanca.

## Contratos

Schema `atlas.antifragility.composition_metric.v1`.

## Fluxo

Scorecard + activity counts + violation ratio + density -> M composto em [0,1].

## Regras para IA

Nao estimar N. Nao dizer que Atlas venceu rival/provider. Nao usar M como benchmark externo.

## Escopo de Implementacao

Service e teste unitario existem; metric e read-only.

## Dependencias

ACOS, Constitutional Kernel, Admission, Reconciliation e TEOS-I4.

## Evidencias

`AtlasAntifragilityCompositionMetricService`, teste unitario e integracao Patamar 4.

## Riscos

Transformar metrica interna em claim publico de superioridade.

## Exemplos

Snapshot exposto via state aggregator Patamar 4.

## Proximas Acoes

Manter claim policy travada enquanto novos componentes internos de M forem adicionados.

## Claim Policy

- `benchmark_claim_allowed=false`
- `rivals_claim_allowed=false`
- `superiority_claim_allowed=false`
- `provider_capability_estimated=false`
