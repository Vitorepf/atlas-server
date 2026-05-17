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
  - app/Services/Ai/Router/AtlasAiRouterService.php
  - app/Services/Ai/Router/AtlasAiRouterDecision.php
  - app/Models/AiRouterDecision.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-router-runtime-enterprise-upgrade
graph_title: Atlas AI Router Runtime Enterprise Upgrade
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-router-flow-routing-contract-v1
graph_status: active
graph_source: repo
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
required_tests:
  - "php artisan test tests/Unit/Ai/Router tests/Feature/Ai/AtlasAiRouterRuntimeTest.php"
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

## Specialist Flow Runtime Contract

O Router decide o fluxo, mas fluxos leves tambem precisam carregar um contrato
auditavel antes da resposta do provider. O schema canonico inicial e:

```text
atlas.ai.specialist_flow_runtime.v1
```

Cada runtime contract carrega tambem um recibo deterministico:

```text
atlas.ai.specialist_flow_receipt.v1
```

Implementacao atual:

- `AtlasAiSpecialistFlowRuntimeService`
- `AtlasAiSpecialistFlowExecutionService`
- `specialist_flow_runtime` no payload entregue ao gateway
- `specialist_flow_execution` no payload entregue ao gateway
- `specialist_flow_runtime` em `AiTraceResource`
- `specialist_flow_execution` em `AiTraceResource`
- `score_components.specialist_flow` em `AiTraceMetricAggregator`
- `ai_specialist_flow_executions` como trilha persistida de runtime/execution
- `AiSpecialistFlowExecution` como modelo persistente
- `GET /ai/interactions/{trace}/flow-status` como read model enterprise para Desktop/API
- tests em `AtlasAiSpecialistFlowRuntimeServiceTest`
- coverage API em `AtlasDevRuntimeInteractionApiTest`

Campos minimos:

- `flow_id`
- `owner`
- `flow_origin`
- `command_intent`
- `routing_reason`
- `workspace_present`
- `execution_mode`
- `side_effect_policy`
- `output_contract`
- `required_evidence`
- `forbidden_actions`
- `delegation`
- `receipt`

Campos minimos do `receipt`:

- `receipt_id`
- `contract_hash`
- `issued_by`
- `flow_id`
- `owner`
- `execution_mode`
- `delegation_status`
- `required_evidence`

O `contract_hash` e deterministico para o mesmo contrato. Isso permite comparar
payload, resource e telemetry sem depender de timestamp.

## Specialist Flow Execution Packet

Depois do runtime contract, o Atlas gera um pacote de execucao do handler:

```text
atlas.ai.specialist_flow_execution.v1
```

Esse pacote e o elo entre Router e provider. Ele nao escolhe provider e nao
edita workspace. Ele instrui a resposta do fluxo especializado e deixa a
auditoria pronta.

Campos minimos:

- `status`: `ready_for_provider` ou `delegated`
- `flow_id`
- `handler_id`
- `handler_version`
- `runtime_receipt_id`
- `runtime_contract_hash`
- `delegation`
- `provider_prompt_contract`
- `response_shape`
- `audit_checks`

Handlers iniciais:

- `atlas_research_grounded_answer_handler`
- `atlas_explain_read_only_handler`
- `atlas_debug_triage_handler`
- `atlas_review_findings_first_handler`
- `atlas_plan_engineering_plan_handler`
- `atlas_conversation_direct_handler`
- `atlas_specialist_delegation_handler`

`AiPromptBuilder` deve projetar `specialist_flow_execution` no prompt em uma
secao propria, para que o provider execute o fluxo com contrato visivel. A API e
a telemetry tambem devem expor o execution packet.

## Persistencia de Specialist Flow

O registro persistente canonico e:

```text
ai_specialist_flow_executions
```

Ele guarda:

- `trace_id`
- `router_decision_id`
- `flow_id`
- `handler_id`
- `status`
- `runtime_receipt_id`
- `runtime_contract_hash`
- `delegation_status`
- `delegation_target_flow_id`
- `runtime_payload`
- `execution_payload`
- `receipt`
- `audit_checks`
- `response_shape`

`AiGatewayService` grava esse registro dentro do ciclo de persistencia de trace.
`AiTraceResource` expoe `specialist_flow_execution_record` quando a relacao esta
carregada. `AiTraceMetricAggregator` prefere a tabela persistida e so cai para
`ai_jobs.payload` quando o registro ainda nao existe.

## Flow Status Read Model

O endpoint canonico de UX/auditoria para Desktop e API e:

```text
GET /ai/interactions/{trace}/flow-status
```

Schema:

```text
atlas.ai.flow_status.v1
```

Objetivo:

- entregar uma foto unica do estado do Router + flow especializado
- evitar que o cliente precise reconstruir estado lendo varios campos do trace
- deixar recibo, contract hash, evidencias e proxima acao visiveis para UI
- preservar separacao: Atlas AI mostra status; Atlas Dev/Forge executam seus runtimes

Campos principais:

- `trace`: identificacao e status da interacao
- `state`: estado operacional (`routed`, `runtime_contract_ready`,
  `ready_for_provider`, `audit_recorded`, `atlas_dev_runtime`, `forge_required`)
- `router`: decisao persistida de flow
- `specialist_flow.runtime`: contrato runtime emitido
- `specialist_flow.execution`: pacote do handler
- `specialist_flow.audit_record`: registro persistido de auditoria
- `atlas_dev_runtime`: slice do Atlas Dev quando o Router delegou para Dev
- `audit`: `receipt_id`, `contract_hash`, evidencias exigidas, acoes proibidas e checks
- `telemetry`: resumo de qualidade/eficiencia e `score_components.specialist_flow`
- `ui`: label humano, flag `is_auditable` e `next_action`

Implementacao atual:

- `AtlasAiFlowStatusReadModel`
- `AiInteractionController::flowStatus`
- rota `/ai/interactions/{trace}/flow-status`
- teste `test_flow_status_endpoint_returns_enterprise_audit_read_model`

## Router Runtime Readiness Gate

O gate canonico para declarar se o Router Runtime + Specialist Flows estao
instalados em nivel enterprise auditavel e:

```text
GET /ai/router-runtime/readiness
```

Schema:

```text
atlas.ai.router_runtime_readiness.v1
```

Esse endpoint e read-only e protegido por `atlas.token`. Ele retorna HTTP 200
quando todos os checks passam e HTTP 503 quando algum requisito estrutural esta
bloqueado.

Checks minimos:

- `router.flows_declared`: todos os flows canonicos existem.
- `router.behavior_smoke`: smoke de roteamento cobre Dev, Explain e Forge.
- `specialist.runtime_contract`: runtime contract e receipt deterministico.
- `specialist.execution_packet`: handler packet com audit checks.
- `persistence.artifacts`: migrations, modelos, relacoes e gateway writer.
- `api.flow_status_read_model`: endpoint/read model de status existe.
- `telemetry.specialist_flow_score_components`: telemetry projeta specialist flow.
- `prompt.specialist_flow_projection`: prompt builder injeta execution packet.
- `docs.canonical_router_runtime_upgrade`: esta doc registra readiness e status.

O gate nao executa provider, nao edita workspace e nao grava dados. Ele e uma
prova operacional de instalacao/contrato para Desktop, outras IAs e operadores.

## Router Runtime Bootstrap

O contrato canonico para Desktop/API renderizar o Router Runtime sem hardcode e:

```text
GET /ai/router-runtime/bootstrap
```

Schema:

```text
atlas.ai.router_runtime_bootstrap.v1
```

Esse endpoint e read-only, protegido por `atlas.token`, e retorna HTTP 200
quando o readiness esta `passed`. Ele inclui:

- resumo do readiness e endpoint para revalidacao
- entrypoints oficiais: criar interacao, flow-status, readiness e bootstrap
- catalogo dos flows canonicos com owner, superficie de execucao e politica de side effect
- slash commands suportados
- estados de UI (`routed`, `ready_for_provider`, `audit_recorded`,
  `atlas_dev_runtime`, `forge_required`, etc.)
- payload contract com slices canonicos (`atlas_ai_router`, `specialist_flow_runtime`,
  `specialist_flow_execution`, `atlas_dev_runtime`)
- UX contract para saber quando mostrar receipt, abrir Forge, mostrar controles Dev
  ou aguardar provider
- fronteiras explicitas entre Atlas AI, Atlas Dev e Atlas Forge

O bootstrap nao substitui o Router. Ele apenas ensina a UI e outras IAs a operar
o Router Runtime com labels, endpoints e limites corretos.

Politica inicial:

- `atlas_research`: resposta source-grounded, com incerteza e referencias.
- `atlas_explain`: explicacao read-only; nao pode alegar patch.
- `atlas_debug`: triagem sem workspace; delega para Atlas Dev quando houver workspace.
- `atlas_review`: review/findings-first; delega para Atlas Dev quando houver workspace.
- `atlas_plan`: plano de engenharia read-only, com riscos, evidencias e recomendacao de execucao.
- `atlas_conversation`: conversa direta, com handoff explicito quando escopo mudar.
- `atlas_dev` e `atlas_forge`: nao recebem `specialist_flow_runtime`; usam seus runtimes
  proprios.

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

## Regras para IA

- Router decide flow; flow executa.
- Router nao chama provider.
- Router nao aplica patch.
- Atlas Dev nao pode virar catch-all.
- Forge continua tela/sistema de Obra.
- Compatibilidade `atlas_*` vs `programming.*` deve usar ponte explicita.

## Escopo de Implementacao

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
- `/ai/interactions` injeta `atlas_ai_router`.
- `/ai/interactions` injeta `specialist_flow_runtime` para fluxos leves.
- `/ai/interactions` injeta `specialist_flow_execution` para fluxos leves.
- `AiTraceResource` expõe RouterDecision.
- `AiTraceResource` expõe Specialist Flow Runtime.
- `AiTraceResource` expõe Specialist Flow Execution.
- `AiTraceResource` expõe Specialist Flow Execution Record persistido.
- telemetry inclui Router e Specialist Flow diagnostics.
- docs-health passa.

## Riscos

- Quebrar compatibilidade com `programming.dev`.
- Duplicar Domain Catalog em vez de criar ponte.
- Roteamento automatico mandar patch sem workspace para Dev.
- UI esconder baixa confianca.
- Flows novos nascerem sem contrato proprio.

## Exemplos

- "Implemente endpoint" + workspace -> `atlas_dev`.
- "Revise esse diff" + diff -> `atlas_review`.
- "Investigue este traceback" -> `atlas_debug`.
- "Planeje antes de implementar" -> `atlas_plan`.
- "Pesquise estado da arte" -> `atlas_research`.
- "Vamos pensar juntos" -> `atlas_conversation`.
- Tela Atlas Code -> `atlas_forge`.

## Proximas Acoes

1. Transformar `specialist_flow_runtime` em handlers executaveis por fluxo.
2. Criar contracts docs proprios para research/explain/debug/review/conversation.
3. Expor RouterDecision e Specialist Flow Runtime no Desktop.
4. Ampliar tests de matriz.
5. Rodar hardening com docs-health, Pint e suites de Atlas AI/Dev.
