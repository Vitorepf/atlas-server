---
id: atlas-patamar-4-substrato-cognitivo-autonomo
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Patamar 4 - Substrato Cognitivo Autonomo
slug: atlas-patamar-4-substrato-cognitivo-autonomo
status: building
implementation_state: runtime_available_integration_partial
category: cognition
priority: 98
summary: Doc-mae Patamar 4 que conecta Constitutional Kernel, Admission, self-model, reconciliation, TEOS-I4, swarm, TDC e closures em um mapa operacional honesto.
tags: [atlas-ai, patamar-4, cognition, autonomy, governance]
capabilities: [patamar4_state_map, autonomous_cognition_substrate, patamar4_runtime_inventory, patamar4_smoke_envelope]
decisions:
  - Este doc e mapa/contrato mae; cada runtime filho mantem sua propria autoridade operacional.
  - Patamar 4 nao pode ser declarado completo enquanto gateway critical path, UI consumers e trust ledger integration estiverem pendentes.
  - Loop fechado exige Kernel, Admission, Evidence/AURG e receipts append-only.
maintenance:
  - Atualizar quando qualquer runtime Patamar 4 mudar schema, storage, command ou integration state.
  - Manter pendencias honestas separadas de runtime disponivel.
risk_level: critical
owner: atlas-ai
graph_id: atlas-patamar-4-substrato-cognitivo-autonomo
graph_title: Atlas Patamar 4 - Substrato Cognitivo Autonomo
graph_world: atlas
graph_layer: module
graph_kind: system
graph_parent: atlas-cognition-operating-system
graph_status: building
graph_source: repo
depends_on:
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
  - atlas-cognitive-function-atlas
  - atlas-autonomous-reconciliation-runtime
  - atlas-teos-i4-counterfactual-tree
  - atlas-swarm-conductor
  - atlas-temporary-domain-composition
flows_to: [atlas-cognition-operating-system]
unlocks: [patamar4_state_snapshot, patamar4_loop_smoke, autonomous_cognition_substrate_map]
governs: [patamar4_runtime_inventory, patamar4_state_envelope]
authority_class: substrate
related_paths:
  - docs/engineering-knowledge-base/atlas-patamar-4-substrato-cognitivo-autonomo.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - docs/engineering-knowledge-base/atlas-cognitive-function-atlas.md
  - docs/engineering-knowledge-base/atlas-autonomous-reconciliation-runtime.md
  - docs/engineering-knowledge-base/atlas-teos-i4-counterfactual-tree.md
  - docs/engineering-knowledge-base/atlas-swarm-conductor.md
  - docs/engineering-knowledge-base/atlas-temporary-domain-composition.md
  - app/Services/Ai/Patamar4/AtlasPatamar4StateService.php
  - app/Console/Commands/AtlasPatamar4StatusCommand.php
  - app/Console/Commands/AtlasPatamar4RunLoopOnceCommand.php
  - app/Http/Controllers/AtlasPatamar4StateController.php
  - tests/Feature/Integration/AtlasPatamar4LoopIntegrationTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-patamar-4-substrato-cognitivo-autonomo.md
  - app/Services/Ai/Patamar4/AtlasPatamar4StateService.php
evidence:
  - app/Services/Ai/Patamar4/AtlasPatamar4StateService.php
  - app/Console/Commands/AtlasPatamar4StatusCommand.php
  - app/Console/Commands/AtlasPatamar4RunLoopOnceCommand.php
  - app/Http/Controllers/AtlasPatamar4StateController.php
  - tests/Feature/Integration/AtlasPatamar4LoopIntegrationTest.php
  - tests/Feature/Console/AtlasPatamar4RunLoopOnceCommandTest.php
  - tests/Feature/Http/AtlasPatamar4StateControllerTest.php
required_tests:
  - "php artisan test tests/Feature/Integration/AtlasPatamar4LoopIntegrationTest.php tests/Feature/Console/AtlasPatamar4RunLoopOnceCommandTest.php tests/Feature/Http/AtlasPatamar4StateControllerTest.php"
  - "php artisan atlas:cognition:scorecard --strict --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Wire AtlasDecideGatewayConsultationService into AiGatewayService critical path.
  - Add UI/mobile/desktop consumers for Patamar 4 state.
  - Integrate trust ledger into Autonomy Admission.
allowed_changes:
  - Add runtime children only with child owner doc, service, tests and clear integration state.
forbidden_changes:
  - claim_patamar4_finished_without_real_runtime
  - bypass_constitutional_kernel
  - allow_autonomous_action_without_admission
  - silent_loop_iteration
requires_evidence: true
line_limit: 520
schema:
  - atlas.patamar4.state.v1
  - atlas.patamar4.smoke_envelope.v1
---

# Atlas Patamar 4 - Substrato Cognitivo Autonomo

## Resumo

Doc-mae do Patamar 4. Ele organiza os runtimes autonomos e closures em um mapa operacional, sem substituir a autoridade dos docs filhos.

## Papel no Atlas

Mostrar como o Atlas detecta gaps, passa por gates, registra evidence e prepara acoes governadas.

## Onde Se Encaixa

Fica sob ACOS e acima dos docs filhos Patamar 4.

## Contratos

Schemas `atlas.patamar4.state.v1` e `atlas.patamar4.smoke_envelope.v1`.

## Fluxo

Reconciliation tick -> Cognitive Function Atlas -> Constitutional Kernel -> Autonomy Admission -> AURG-4D -> optional TEOS/ASCB/Staging paths.

## Regras para IA

Nao declarar Patamar 4 completo enquanto houver pendencias honestas. Nao criar runtime autonomo fora do Kernel/Admission.

## Escopo de Implementacao

State service, controller, status command e loop-once command existem. Algumas integracoes criticas seguem pendentes.

## Dependencias

Constitutional Kernel, Autonomy Admission, Cognitive Function Atlas, Reconciliation, TEOS-I4, Swarm, TDC e closures P1/P2/P3.

## Evidencias

`AtlasPatamar4StateService`, commands Patamar 4, controller e testes de integration/console/http.

## Riscos

Transformar mapa de substrato em claim de autonomia plena.

## Exemplos

`php artisan atlas:patamar4:status --json`

`php artisan atlas:patamar4:run-loop-once --json`

`GET /atlas/patamar4/state?tail=N`

## Proximas Acoes

Fechar gateway critical path, UI consumers e trust ledger integration antes de claim de Patamar 4 completo.

## Mapa dos Runtimes

| Acronym | Runtime | Role |
|---|---|---|
| ACK | AtlasConstitutionalKernelService | Petreo invariant gate |
| AAA | AtlasAutonomyAdmissionService | Risk/autonomy composer |
| ACFA | AtlasCognitiveFunctionAtlasService | ACOS self-model read-model |
| AARR | AtlasAutonomousReconciliationRuntimeService | Reconciliation tick runtime |
| TEOS-I4 | AtlasTeosI4CounterfactualTreeService | Counterfactual tree composer |
| ASWC | AtlasSwarmConductorService | Multi-arm dispatch composer |
| ATDC | AtlasTemporaryDomainCompositionService | TTL cross-domain capsule composer |
| ADGW | AtlasDecideGatewayConsultationService | Gateway consultation hook |
| AACM | AtlasAntifragilityCompositionMetricService | Wrapper-only M metric |
| ACMF-SE | AtlasCognitiveMemoryFabricSchemaEvolutionService | Schema proposal runtime |
| ASCB-EX | AtlasSelfConstructionScaffoldStagingExecutorService | Approved proposal staging |
| ACOP-ACRS | AtlasContextObservabilityToRankingReflexiveBridgeService | Observability-to-ranking bridge |
| AKIF-OCR | AtlasKnowledgeIngestionFabricOcrConfidenceService | OCR confidence wrapper |
| ACL8 | AtlasCompoundingLevel8DistillationService | L7/L8/L9 distillation |

## Persistencia

All runtime ledgers remain local-first append-only JSONL under `storage/atlas/...`.

## Pendencias Honestas

- ADML consultation hook ainda precisa entrar no critical path do gateway real.
- UI/mobile/desktop ainda precisam consumir Patamar 4 state.
- Constitutional Kernel ainda tem classes elastic/runtime documentadas como proxima fatia.
- Trust Ledger integration no Admission continua futura.

## Claim Policy

- `benchmark_claim_allowed=false`
- `rivals_claim_allowed=false`
- `superiority_claim_allowed=false`
- `external_rivals_certification_touched=false`
- `provider_capability_estimated=false`
