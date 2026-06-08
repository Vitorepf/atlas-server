---
id: atlas-decide-gateway-consultation
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Decide Gateway Consultation Hook
slug: atlas-decide-gateway-consultation
status: building
implementation_state: runtime_available_gateway_integration_pending
category: atlas_decide
priority: 91
summary: Hook de consulta ADML para provider resolution que recomenda rota aprendida sem chamar provider, sem override silencioso e sem claim de winner.
tags: [atlas-ai, atlas-decide, gateway, provider-routing, governance]
capabilities: [gateway_route_consultation, learned_route_recommendation, provider_resolution_preflight]
decisions:
  - O hook consulta ADML; ele nao decide chamada provider sozinho.
  - Integracao no AiGatewayService ainda precisa PR/prova especifica.
  - Verdicts nunca autorizam claim de rivals, benchmark ou winner.
maintenance:
  - Atualizar antes de mudar verdicts, ADML scope, storage ou gateway integration.
  - Manter Kernel/Admission antes de recomendacao de rota aprendida.
risk_level: high
owner: atlas-ai
graph_id: atlas-decide-gateway-consultation
human_name: Atlas Decide Gateway Consultation Hook
canonical_name: Atlas Decide Gateway Consultation Hook
technical_name: AtlasDecideGatewayConsultationService
cartography_type: flow
canonical_source: docs/engineering-knowledge-base/atlas-decide-gateway-consultation.md
graph_title: Atlas Decide Gateway Consultation Hook
graph_world: atlas
graph_layer: module
graph_kind: flow
graph_parent: atlas-decide-meta-learning-loop-closure
graph_status: building
graph_source: repo
depends_on: [atlas-decide-meta-learning-loop-closure, atlas-constitutional-kernel, atlas-autonomy-admission]
flows_to: [atlas-patamar-4-substrato-cognitivo-autonomo]
unlocks: [adml_gateway_consultation, learned_provider_route_preflight]
governs: [gateway_consultation_envelopes]
authority_class: composer
related_paths:
  - docs/engineering-knowledge-base/atlas-decide-gateway-consultation.md
  - docs/engineering-knowledge-base/atlas-decide-meta-learning-loop-closure.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - app/Services/Ai/AtlasDecide/AtlasDecideGatewayConsultationService.php
  - app/Console/Commands/AtlasDecideGatewayConsultCommand.php
  - tests/Unit/Ai/AtlasDecide/AtlasDecideGatewayConsultationServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-decide-gateway-consultation.md
  - app/Services/Ai/AtlasDecide/AtlasDecideGatewayConsultationService.php
evidence:
  - app/Services/Ai/AtlasDecide/AtlasDecideGatewayConsultationService.php
  - app/Console/Commands/AtlasDecideGatewayConsultCommand.php
  - tests/Unit/Ai/AtlasDecide/AtlasDecideGatewayConsultationServiceTest.php
evidence_refs:
  - symbol: AtlasDecideGatewayConsultationService
  - command: atlas:atlas-decide:gateway-consult
required_tests:
  - "php artisan test tests/Unit/Ai/AtlasDecide/AtlasDecideGatewayConsultationServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Integrar ao AiGatewayService em PR cirurgico com teste no critical path.
allowed_changes:
  - Add gateway consumer integration with explicit fallback and approval tests.
forbidden_changes:
  - bypass_kernel_or_admission
  - silent_provider_override
  - claim_winner_from_consultation
requires_evidence: true
line_limit: 520
schema:
  - atlas.atlas_decide.gateway_consultation.v1
---

# Atlas Decide Gateway Consultation Hook

## Resumo

Hook para consultar ADML antes da resolucao de provider.

## Papel no Atlas

Recomendar rota aprendida ao gateway sem executar provider nem tomar ownership do gateway.

## Onde Se Encaixa

Fica entre ADML e futuros consumidores como `AiGatewayService`.

## Contratos

Schema `atlas.atlas_decide.gateway_consultation.v1`.

## Fluxo

Kernel -> Admission -> ADML `activeRouteFor()` -> verdict -> JSONL append-only.

## Regras para IA

Nao inserir override silencioso de provider. Nao declarar winner a partir de consultation.

## Escopo de Implementacao

Service, comando e teste unitario existem; integracao no gateway real permanece pendente.

## Dependencias

ADML, Constitutional Kernel e Autonomy Admission.

## Evidencias

`AtlasDecideGatewayConsultationService`, comando e teste unitario.

## Riscos

Confundir recomendacao com decisao final de provider.

## Exemplos

`php artisan atlas:atlas-decide:gateway-consult --task-category=code_generation --role=primary --privacy=public --json`

## Proximas Acoes

Integrar ao gateway real com teste que prove fallback, approval e blocked verdict.

## Verdicts

- `follow_learned_route`
- `free_to_choose`
- `requires_approval`
- `blocked`
