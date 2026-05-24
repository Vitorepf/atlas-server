---
id: atlas-ai-router-runtime-enterprise-upgrade
type: engineering_knowledge
title: Atlas AI Router Runtime Enterprise Upgrade
status: active
category: atlas-ai
priority: 100
summary: Meta canonica para transformar o Atlas AI Router de contrato/documentacao em runtime enterprise bonito: flow decisions persistidas, specialist flows auditaveis, delegation, telemetry, receipts, Desktop/API UX e testes completos.
tags:
  - atlas-ai
  - router
  - specialist-flows
  - enterprise-upgrade
  - telemetry
capabilities:
  - atlas_ai_router_runtime
  - specialist_flow_routing
  - flow_decision_receipts
  - flow_delegation
  - router_telemetry
decisions:
  - Atlas AI Router deve virar runtime real, nao apenas contrato documental.
  - Router decide `flow_id`; flow executa.
  - Flow decision canonica usa `atlas_dev`, `atlas_research`, `atlas_explain`, `atlas_debug`, `atlas_review`, `atlas_plan`, `atlas_conversation`, `atlas_forge`.
  - Compatibilidade com flows legados `programming.*` deve ser preservada por ponte, nao por ruptura.
  - Enterprise bonito exige decisao auditavel, UX visivel, delegation explicito, telemetry e testes de matriz.
  - Meta 6 entrega o pipeline AIOS (Intent -> Objective -> Domain Router -> Flow Router -> Policy/Evidence Gates -> Runtime Dispatch -> Decision Receipt) em tabelas separadas `ai_atlas_*` para nao colidir com o `ai_router_decisions` legado de provider routing.
  - Dispatch v1 nunca chama provider/browser/API real; o status do dispatch e `simulated`, `planned` ou `blocked` com `receipt_hash` deterministico. Execucao real fica para Tool Runtime (Meta 5) e Domain Company Runtimes (Meta 7+).
maintenance:
  - Atualize este doc quando o runtime do Router, specialist flows, delegation ou Desktop/API UX mudarem.
  - Nao coloque detalhes internos de Atlas Dev ou Forge aqui; este doc governa roteamento e handoff.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
  - app/Services/Ai/Router/AtlasAiRouterService.php
  - app/Services/Ai/Router/AtlasAiRouterDecision.php
  - app/Models/AiRouterDecision.php
  - database/migrations/2026_05_18_030000_create_ai_atlas_router_runtime_tables.php
  - app/Models/AiAtlasIntentClassification.php
  - app/Models/AiAtlasRouterDecision.php
  - app/Models/AiAtlasFlowRoute.php
  - app/Models/AiAtlasRuntimeDispatch.php
  - app/Models/AiAtlasDecisionReceipt.php
  - app/Services/Ai/RouterRuntime/RouterRuntimeCanon.php
  - app/Services/Ai/RouterRuntime/IntentKernelService.php
  - app/Services/Ai/RouterRuntime/ObjectiveRoutingService.php
  - app/Services/Ai/RouterRuntime/DomainRouterService.php
  - app/Services/Ai/RouterRuntime/FlowRouterService.php
  - app/Services/Ai/RouterRuntime/RuntimeDispatchService.php
  - app/Services/Ai/RouterRuntime/DecisionReceiptService.php
  - app/Services/Ai/RouterRuntime/RouterPolicyBridgeService.php
  - app/Services/Ai/RouterRuntime/RouterEvidenceBridgeService.php
  - app/Services/Ai/RouterRuntime/RouterRuntimeReadinessService.php
  - app/Services/Ai/RouterRuntime/RouterRuntimeControlPlaneService.php
  - app/Console/Commands/AtlasAiRouterRuntimeCommand.php
  - tests/Concerns/CreatesRouterRuntimeTables.php
  - tests/Feature/Ai/RouterRuntime/
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-router-runtime-enterprise-upgrade
graph_title: Atlas AI Router Runtime Enterprise Upgrade
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-router-flow-routing-contract-v1
graph_status: active
graph_source: repo
human_name: Atlas AI Router Runtime Enterprise Upgrade
canonical_name: Atlas AI Router Runtime Enterprise Upgrade
technical_name: atlas-ai-router-runtime-enterprise-upgrade
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
allowed_changes:
  - Refinar fases, DoD, tests e rollout conforme o runtime amadurecer.
  - Adicionar specialist flows novos quando houver contrato e handler.
forbidden_changes:
  - Fazer Router chamar provider ou aplicar patch.
  - Fazer fluxo individual escolher `flow_id` global de pedido novo.
  - Remover auditabilidade de `flow_origin`, `routing_reason` ou `routing_confidence`.
depends_on:
  - atlas-hyperflow-operation
  - atlas-ai-router-flow-routing-contract-v1
  - atlas-ai-operating-system
  - atlas-dev-efficient-programming-flow-v1
flows_to:
  - atlas_ai_router_runtime
  - atlas_dev
  - atlas_research
  - atlas_explain
  - atlas_debug
  - atlas_review
  - atlas_plan
  - atlas_conversation
  - atlas_forge
unlocks:
  - atlas_ai_as_central_product
  - specialist_flows_runtime
  - auditable_flow_decisions
governs:
  - atlas_ai.router.runtime_enterprise_upgrade
evidence:
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - app/Services/Ai/Router/AtlasAiRouterService.php
  - tests/Unit/Ai/Router/AtlasAiRouterServiceTest.php
contracts:
  - atlas.ai.specialist_flow_execution.v1
  - atlas.ai.router_runtime_readiness.v1
  - atlas.ai.router_runtime_bootstrap.v1
surfaces:
  - GET /ai/interactions/{trace}/flow-status
required_tests:
  - "php artisan test tests/Unit/Ai/Router tests/Feature/Ai/AtlasAiRouterRuntimeTest.php"
  - "php artisan test tests/Feature/Ai/RouterRuntime"
  - "php artisan atlas:ai:router-runtime --action=readiness --json"
  - "php artisan atlas:ai:router-runtime --action=smoke --json"
  - "php artisan atlas:ai:router-runtime --action=control-plane --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este doc antes de implementar o Router Runtime ou specialist flows do Atlas AI.
ai_usage_notes:
  - Use este doc como meta de execucao; use o Router Flow Routing Contract como contrato de comportamento.
quality_gates:
  - flow-decision-persisted
  - router-does-not-execute
  - delegation-explicit
  - specialist-flow-contracts-present
  - telemetry-visible
failure_modes:
  - Router virar executor.
  - Atlas Dev absorver todos os pedidos.
  - Specialist flow responder sem receipt/delegation.
  - UI esconder motivo de roteamento.
observability_signals:
  - flow_id
  - flow_origin
  - command_intent
  - routing_reason
  - routing_confidence
  - alternative_flow_ids
next_actions:
  - Completar specialist flow handlers.
  - Expor RouterDecision no Desktop.
  - Criar matrix tests para attachments, slash commands, workspace missing e delegation.
  - Meta 7+ Domain Runtimes consomem `AiAtlasRuntimeDispatch` via interface canonical para executar trabalho real apos os gates de policy/evidence/tool plan.
  - Ligar Meta 6 ao Mission Foundation: `AiAtlasIntentClassification.mission_id` ja existe e `ObjectiveRoutingService::resolveObjective` resolve mission ref quando presente.
line_limit: 520
---
# Atlas AI Router Runtime Enterprise Upgrade

## Resumo

Meta para deixar o Atlas AI Router em nivel enterprise bonito: funcionando,
auditable, polido e visivel. A entrega nao e so escolher um flow; e mostrar por
que escolheu, persistir decisao, permitir delegation honesto, conectar Atlas Dev
e Forge e criar specialist flows basicos.

## Papel no Atlas

Atlas AI e o produto central. O Router e a camada que transforma uma intencao em
um flow dono. Ele nao executa. Ele decide e entrega handoff.

```text
Atlas AI Surface
-> Atlas AI Router
-> specialist flow
-> runtime / gates / evidence / learning
```

## Onde Se Encaixa

Fica entre a surface Atlas AI e os flows:

- `atlas_dev`
- `atlas_research`
- `atlas_explain`
- `atlas_debug`
- `atlas_review`
- `atlas_plan`
- `atlas_conversation`
- `atlas_forge`

## Contratos

Contrato principal:

```text
atlas.ai.router.flow_decision.v1
```

O Router usa tambem um kernel deterministico de intencao:

```text
atlas.ai.intent_kernel.v1
```

Esse kernel classifica prompts ambiguos antes da decisao final de flow. Exemplo:
pedido patch-like sem workspace vira `atlas_plan`, nao `atlas_dev`, porque o
sistema precisa primeiro produzir plano, evidencias e recomendacao de execucao.

Campos obrigatorios:

- `flow_id`
- `flow_origin`
- `command_intent`
- `routing_reason`
- `routing_confidence`
- `handoff_payload`
- `alternative_flow_ids`

Persistencia:

- `ai_router_decisions`
- `AiRouterDecision`
- `router_decision` em `AiTraceResource`
- diagnostics em telemetry score components

## Detalhes Extraidos

Detalhes operacionais foram extraidos para docs filhas para manter este contrato legivel e navegavel na cartografia:

- `atlas-ai-router-runtime-enterprise-specialist-flow.md` — runtime contract, execution packet, persistencia e flow-status.
- `atlas-ai-router-runtime-enterprise-readiness-bootstrap.md` — readiness gate, bootstrap e politica inicial de specialist flows.

## Fluxo

1. Surface envia pedido para Atlas AI.
2. Router normaliza input, surface, workspace, attachments e slash command.
3. Router decide `flow_id`.
4. Router injeta `atlas_ai_router` no payload.
5. Runtime do fluxo leve injeta `specialist_flow_runtime`, quando aplicavel.
6. Handler do fluxo injeta `specialist_flow_execution`.
7. Gateway persiste `AiRouterDecision`.
8. Gateway persiste `AiSpecialistFlowExecution`.
9. Prompt Builder projeta o handler contract para o provider.
10. Flow alvo executa ou devolve delegation.
11. UI/API consulta `flow-status` para mostrar flow, motivo, confianca,
    alternativas, receipt, evidencias e proxima acao.

## Regras Para IA

- Router decide flow; flow executa.
- Router nao chama provider.
- Router nao aplica patch.
- Atlas Dev nao pode virar catch-all.
- Forge continua tela/sistema de Obra.
- Compatibilidade `atlas_*` vs `programming.*` deve usar ponte explicita.

## Escopo De Implementacao

| Fase | Entrega | Horas |
| --- | --- | ---: |
| 1 | Router service, DTO, persistencia flow auditavel, resource e telemetry | 12-20h |
| 2 | Integracao `/ai/interactions` + ponte Atlas Dev/Forge | 8-14h |
| 3 | Specialist handlers basicos: conversation, explain, debug, review, research | 25-40h |
| 4 | Delegation explicito entre flows | 8-14h |
| 5 | Desktop/API UX com motivo, confianca, alternativas e flow status | 16-28h |
| 6 | Receipts, docs, tests de matriz e hardening | 20-36h |

Meta enterprise bonita: 100-160h.

## Dependencias

- Atlas AI Router Flow Routing Contract.
- Atlas AI Operating System.
- Atlas Dev Efficient Programming Flow.
- Atlas Code/Forge routes para Obra.
- AiGatewayService, AiTrace, AiRouterDecision e telemetry.

## Evidencias

Evidencia minima:

- `AtlasAiRouterService` com unit tests.
- `ai_router_decisions` persistindo `flow_id` e motivo.
- `/ai/interactions` injeta RouterDecision, Specialist Flow Runtime e Specialist Flow Execution.
- `AiTraceResource` expõe RouterDecision, runtime, execution e execution record persistido.
- telemetry inclui Router e Specialist Flow diagnostics.
- docs-health passa.

## Riscos

- Quebrar compatibilidade com `programming.dev`.
- Duplicar Domain Catalog em vez de criar ponte.
- Roteamento automatico mandar patch sem workspace para Dev.
- UI esconder baixa confianca.
- Flows novos nascerem sem contrato proprio.

## Exemplos

Workspace -> `atlas_dev`; diff -> `atlas_review`; traceback -> `atlas_debug`; plano -> `atlas_plan`; pesquisa -> `atlas_research`; conversa -> `atlas_conversation`; Atlas Code -> `atlas_forge`.

## Status De Implementacao (Meta 6)

Meta 6 do Multi-Domain Implementation Sequence esta implementada como backend
do pipeline AIOS (Intent -> Objective -> Domain Router -> Flow Router ->
Policy/Evidence Gates -> Runtime Dispatch -> Decision Receipt). A camada
**decide e simula**; nada aqui chama provider, browser ou API real.

Para nao colidir com a tabela `ai_router_decisions` legada (provider routing
em `app/Services/Ai/Router/AtlasAiRouterService.php`), as tabelas e modelos
Meta 6 sao prefixados `ai_atlas_*`/`AiAtlas*`.

Persistencia (5 tabelas):

- `ai_atlas_intent_classifications` (`atlas.ai.intent_classification.v1`).
- `ai_atlas_router_decisions` (`atlas.ai.router_decision.v1`) com primary
  domain, secondary domains, `routing_mode` em `{lightweight, standard, deep,
  forge, blocked}` e flags `policy_required`, `evidence_required`,
  `tool_plan_required`.
- `ai_atlas_flow_routes` (`atlas.ai.flow_route.v1`).
- `ai_atlas_runtime_dispatches` (`atlas.ai.runtime_dispatch.v1`) com
  `dispatch_status` em `{planned, dispatched, simulated, blocked, failed,
  completed}`.
- `ai_atlas_decision_receipts` (`atlas.ai.decision_receipt.v1`) com
  `receipt_hash` deterministico.

Services (`App\Services\Ai\RouterRuntime`):

- `RouterRuntimeCanon` — enums canonicos: intent_types, routing_modes,
  dispatch_statuses, intent->domain, intent->flow_id, dominios high-risk.
- `IntentKernelService::classify(rawInput, context)` — kernel deterministico
  baseado em keywords (v1).
- `ObjectiveRoutingService::resolveObjective(intent)` — tolerant a ausencia
  de Mission Foundation.
- `DomainRouterService::route(intent)` — decide primary domain, secondary
  domains, routing_mode, policy/evidence/tool flags e receipt_hash.
- `FlowRouterService::decideFlow(decision, intent)` — escolhe `flow_id`
  canonical (`atlas_dev`, `atlas_research`, etc.), profile, gates obrigatorios
  e fallback flows.
- `RuntimeDispatchService::dispatch(decision, flowRoute, intent)` — persiste
  dispatch simulated/planned/blocked, NUNCA executa acao externa.
- `DecisionReceiptService::recordRouterDecision()` / `::recordRuntimeDispatch()`.
- `RouterPolicyBridgeService` e `RouterEvidenceBridgeService` — bridges
  tolerantes a Meta 3/Meta 4 ausentes.
- `RouterRuntimeReadinessService::report()` — schema
  `atlas.ai.router_runtime.readiness.v1`.
- `RouterRuntimeControlPlaneService::snapshot()` — schema
  `atlas.ai.router_runtime.control_plane.v1`.

Comando Artisan:

```bash
/opt/homebrew/bin/php artisan atlas:ai:router-runtime --action=readiness --json
/opt/homebrew/bin/php artisan atlas:ai:router-runtime --action=classify --input="..." --json
/opt/homebrew/bin/php artisan atlas:ai:router-runtime --action=route --input="..." --json
/opt/homebrew/bin/php artisan atlas:ai:router-runtime --action=dispatch --input="..." --json
/opt/homebrew/bin/php artisan atlas:ai:router-runtime --action=smoke --json
/opt/homebrew/bin/php artisan atlas:ai:router-runtime --action=control-plane --json
```

Fora de escopo de Meta 6 (continua em Metas seguintes):

- Tool Runtime real (Meta 5) — Router apenas marca `tool_plan_required`.
- Provider invocation (Meta 7+).
- Browser/terminal/API execution (Tool Factory, Meta 13).
- UI Desktop/API Control Plane (Meta 14).
- Substituicao do Atlas AI Router legado (`AtlasAiRouterService`); ele
  continua governando flow_id legacy de programacao via ponte.

## Proximas Acoes

Concluir execucao externa Claude Code/Codex aprovada, depois acoplar UX pesada.
