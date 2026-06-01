---
id: atlas-acop-acrs-reflexive-bridge
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas ACOP to ACRS Reflexive Streaming Bridge
slug: atlas-acop-acrs-reflexive-bridge
status: building
implementation_state: runtime_available_bridge
category: cognition
priority: 88
summary: Bridge append-only que transforma sinais do Context Observability Plane em eventos consumiveis pelo Context Ranking System sem mutar ACRS diretamente.
tags: [atlas-ai, acos, acop, acrs, observability]
capabilities: [acop_to_acrs_signal, reflexive_ranking_signal_log, context_health_feedback_bridge]
decisions:
  - ACOP continua dono de observabilidade; ACRS continua dono de ranking.
  - Este bridge emite sinais auditaveis, nao altera pesos de ACRS diretamente.
  - Toda adaptacao de ranking precisa consumir sinais de forma explicita e testada.
maintenance:
  - Atualizar antes de mudar signal kinds, severity, storage ou comando.
  - Manter append-only JSONL e testes de shape/list/summary.
risk_level: medium
owner: atlas-ai
graph_id: atlas-acop-acrs-reflexive-bridge
graph_title: Atlas ACOP to ACRS Reflexive Streaming Bridge
graph_world: atlas
graph_layer: module
graph_kind: flow
graph_parent: atlas-cognition-operating-system
graph_status: building
graph_source: repo
depends_on: [atlas-cognition-operating-system, atlas-context-observability-plane, atlas-context-ranking-system]
flows_to: [atlas-context-ranking-system]
unlocks: [context_observability_ranking_feedback, reflexive_context_health_signal]
governs: [acop_to_acrs_signals]
authority_class: bridge
related_paths:
  - docs/engineering-knowledge-base/atlas-acop-acrs-reflexive-bridge.md
  - docs/engineering-knowledge-base/atlas-context-observability-plane.md
  - docs/engineering-knowledge-base/atlas-context-ranking-system.md
  - app/Services/Ai/Context/AtlasContextObservabilityToRankingReflexiveBridgeService.php
  - app/Console/Commands/AtlasAcopAcrsBridgeCommand.php
  - tests/Unit/Ai/Context/AtlasContextObservabilityToRankingReflexiveBridgeServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-acop-acrs-reflexive-bridge.md
  - app/Services/Ai/Context/AtlasContextObservabilityToRankingReflexiveBridgeService.php
evidence:
  - app/Services/Ai/Context/AtlasContextObservabilityToRankingReflexiveBridgeService.php
  - app/Console/Commands/AtlasAcopAcrsBridgeCommand.php
  - tests/Unit/Ai/Context/AtlasContextObservabilityToRankingReflexiveBridgeServiceTest.php
evidence_refs:
  - symbol: AtlasContextObservabilityToRankingReflexiveBridgeService
  - command: atlas:acop-acrs:bridge
  - test: AtlasContextObservabilityToRankingReflexiveBridgeServiceTest
required_tests:
  - "php artisan test tests/Unit/Ai/Context/AtlasContextObservabilityToRankingReflexiveBridgeServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Provar o consumidor ACRS dos sinais antes de claim de adaptacao automatica de ranking.
allowed_changes:
  - Add ACRS consumer logic with tests proving ACOP remains observability owner.
forbidden_changes:
  - mutate_acrs_directly
  - silent_signal_emission
requires_evidence: true
line_limit: 520
schema:
  - atlas.acop_to_acrs.signal.v1
---

# Atlas ACOP to ACRS Reflexive Streaming Bridge

## Resumo

Bridge que registra sinais ACOP para uso posterior pelo ACRS em um log append-only.

## Papel no Atlas

Fechar o retorno reflexivo entre observabilidade de contexto e ranking sem criar segundo owner de ranking.

## Onde Se Encaixa

Fica entre `AtlasContextObservabilityPlaneService` e `AtlasContextRankingSystemService`.

## Contratos

Schema `atlas.acop_to_acrs.signal.v1`; storage `storage/atlas/acop_to_acrs/signals.jsonl`.

## Fluxo

`emit()` recebe kind/severity -> valida signal kinds -> persiste JSONL -> `summary()` agrega por kind e severity.

## Regras para IA

Nao alterar pesos ACRS diretamente neste bridge. Nao tratar sinal emitido como prova de melhoria de ranking.

## Escopo de Implementacao

Service, comando e teste unitario existem. Consumidor ACRS dos sinais ainda precisa prova propria.

## Dependencias

ACOP, ACRS e ACOS.

## Evidencias

`AtlasContextObservabilityToRankingReflexiveBridgeService`, `AtlasAcopAcrsBridgeCommand` e teste unitario correspondente.

## Riscos

Confundir emission log com adaptacao efetiva de ranking.

## Exemplos

`php artisan atlas:acop-acrs:bridge --action=emit --kind=context_quality --severity=medium --json`

## Proximas Acoes

Adicionar teste de consumo ACRS antes de qualquer claim de ranking auto-adaptativo.

## Signal Kinds

- `context_quality`
- `retrieval_latency`
- `leak_risk`
- `freshness_drift`
- `cost_pressure`
