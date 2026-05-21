---
id: atlas-ai-router-runtime-enterprise-specialist-flow
type: engineering_knowledge
title: Atlas AI Router Runtime Enterprise Specialist Flow
status: active
category: atlas-ai
priority: 90
summary: Detalhes extraidos do contrato pai sobre runtime de specialist flow, execution packet, persistencia e read model de flow-status.
tags:
  - atlas-ai
  - router
  - enterprise-upgrade
capabilities:
  - specialist_flow_runtime
  - specialist_flow_execution
  - flow_status_read_model
decisions:
  - Specialist flows carregam contrato, execution packet, recibo e trilha auditavel antes da resposta do provider.
  - Flow-status deve ser read model unico para UI/API.
maintenance:
  - Atualize esta doc quando os detalhes extraidos mudarem no runtime.
  - Mantenha o contrato pai como fonte de decisao arquitetural.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-router-runtime-enterprise-specialist-flow
graph_title: Atlas AI Router Runtime Enterprise Specialist Flow
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-router-runtime-enterprise-upgrade
graph_status: active
graph_source: repo
human_name: Atlas AI Router Runtime Enterprise Specialist Flow
canonical_name: Atlas AI Router Runtime Enterprise Specialist Flow
technical_name: atlas-ai-router-runtime-enterprise-specialist-flow
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-specialist-flow.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-specialist-flow.md
allowed_changes:
  - Refinar detalhes extraidos quando o runtime amadurecer.
forbidden_changes:
  - Contradizer o contrato pai do Router Runtime Enterprise Upgrade.
depends_on:
  - atlas-ai-router-runtime-enterprise-upgrade
flows_to:
  - atlas-ai-router-runtime-enterprise-upgrade
unlocks:
  - auditable_flow_decisions
governs:
  - atlas_ai.router_runtime.specialist_flow
evidence:
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-specialist-flow.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
ai_entrypoints:
  - Leia o contrato pai antes de mudar esta doc filha.
ai_usage_notes:
  - Use esta doc como detalhe operacional, nao como fonte de decisao maior.
quality_gates:
  - details-stay-parent-consistent
failure_modes:
  - Doc filha divergir do contrato pai.
observability_signals:
  - docs_health_status
next_actions:
  - Manter esta doc sincronizada com implementacao e contrato pai.
line_limit: 520
---
# Atlas AI Router Runtime Enterprise Specialist Flow

## Resumo

Detalhes extraidos do contrato pai sobre runtime de specialist flow, execution packet, persistencia e read model de flow-status.

## Papel no Atlas

Esta doc e uma extensao tecnica do contrato pai `atlas-ai-router-runtime-enterprise-upgrade`. Ela existe para manter a cartografia e os modais humanos claros: o pai explica a decisao; esta filha guarda detalhes operacionais.

## Onde Se Encaixa

Atlas AI Surface -> Router Runtime Enterprise Upgrade -> esta doc filha -> implementacao e testes do runtime.

## Contratos

Contrato pai: `atlas-ai-router-runtime-enterprise-upgrade`.

Contratos detalhados nesta filha: `atlas.ai.specialist_flow_runtime.v1`, `atlas.ai.specialist_flow_receipt.v1`, `atlas.ai.specialist_flow_execution.v1` e `atlas.ai.flow_status.v1`.

## Fluxo

1. Operador ou IA le o contrato pai.
2. Esta doc detalha a parte operacional extraida.
3. Implementacao e testes seguem os limites do pai.
4. Cartografia mostra esta doc como filha, nao como etapa principal.

## Regras para IA

- Nao trate esta doc como substituta do contrato pai.
- Nao misture nomenclatura de patamar, versao, camada, fonte, regra e risco.
- Consulte `atlas-canonical-glossary-and-naming` quando houver duvida de nome.

## Escopo de Implementacao

- Documentar detalhes extraidos.
- Preservar rastreabilidade para o contrato pai.
- Manter texto abaixo do limite para leitura humana e cartografia.

## Dependencias

- `atlas-ai-router-runtime-enterprise-upgrade`.
- `atlas-ai-router-flow-routing-contract-v1`.
- `atlas-canonical-glossary-and-naming`.

## Evidencias

- Esta doc filha.
- Validador `docs-health`.

## Riscos

- Divergir do contrato pai.
- Esconder detalhe importante em doc grande demais.

## Exemplos

Use esta doc quando precisar revisar apenas esta parte do Router Runtime, sem carregar toda a estrategia enterprise.

## Detalhe Tecnico Extraido

## [Specialist Flow Runtime Contract]

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

## [Specialist Flow Execution Packet]

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
- `quality_rubric`
- `completion_checks`
- `failure_modes`

`quality_rubric`, `completion_checks` e `failure_modes` tornam cada specialist
flow profundo o suficiente para ser auditado: o provider recebe o que deve
otimizar, o que precisa estar verdadeiro ao concluir e quais erros operacionais
nao pode cometer.

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

## [Persistencia de Specialist Flow]

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
- `quality_rubric`, `completion_checks` e `failure_modes` dentro de
  `execution_payload`

`AiGatewayService` grava esse registro dentro do ciclo de persistencia de trace.
`AiTraceResource` expoe `specialist_flow_execution_record` quando a relacao esta
carregada. `AiTraceMetricAggregator` prefere a tabela persistida e so cai para
`ai_jobs.payload` quando o registro ainda nao existe.

## [Flow Status Read Model]

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

## Proximas Acoes

- Manter esta doc sincronizada com implementacao e testes.
- Atualizar contrato pai se a decisao arquitetural mudar.
