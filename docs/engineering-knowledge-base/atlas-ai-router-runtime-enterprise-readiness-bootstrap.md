---
id: atlas-ai-router-runtime-enterprise-readiness-bootstrap
type: engineering_knowledge
title: Atlas AI Router Runtime Enterprise Readiness Bootstrap
status: active
category: atlas-ai
priority: 90
summary: Detalhes extraidos do contrato pai sobre readiness gate, bootstrap read-only e politica inicial dos specialist flows.
tags:
  - atlas-ai
  - router
  - enterprise-upgrade
capabilities:
  - router_runtime_readiness
  - router_runtime_bootstrap
  - router_runtime_ui_contract
decisions:
  - Readiness prova instalacao sem executar provider ou alterar workspace.
  - Bootstrap ensina UI/API a renderizar Router Runtime sem hardcode.
maintenance:
  - Atualize esta doc quando os detalhes extraidos mudarem no runtime.
  - Mantenha o contrato pai como fonte de decisao arquitetural.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-router-runtime-enterprise-readiness-bootstrap
graph_title: Atlas AI Router Runtime Enterprise Readiness Bootstrap
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-router-runtime-enterprise-upgrade
graph_status: active
graph_source: repo
human_name: Atlas AI Router Runtime Enterprise Readiness Bootstrap
canonical_name: Atlas AI Router Runtime Enterprise Readiness Bootstrap
technical_name: atlas-ai-router-runtime-enterprise-readiness-bootstrap
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-readiness-bootstrap.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-readiness-bootstrap.md
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
  - atlas_ai.router_runtime.readiness_bootstrap
evidence:
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-readiness-bootstrap.md
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
# Atlas AI Router Runtime Enterprise Readiness Bootstrap

## Resumo

Detalhes extraidos do contrato pai sobre readiness gate, bootstrap read-only e politica inicial dos specialist flows.

## Papel no Atlas

Esta doc e uma extensao tecnica do contrato pai `atlas-ai-router-runtime-enterprise-upgrade`. Ela existe para manter a cartografia e os modais humanos claros: o pai explica a decisao; esta filha guarda detalhes operacionais.

## Onde Se Encaixa

Atlas AI Surface -> Router Runtime Enterprise Upgrade -> esta doc filha -> implementacao e testes do runtime.

## Contratos

Contrato pai: `atlas-ai-router-runtime-enterprise-upgrade`.

Contratos detalhados nesta filha: `atlas.ai.router_runtime_readiness.v1` e `atlas.ai.router_runtime_bootstrap.v1`.

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

## [Router Runtime Readiness Gate]

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

## [Router Runtime Bootstrap]

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

## Proximas Acoes

- Manter esta doc sincronizada com implementacao e testes.
- Atualizar contrato pai se a decisao arquitetural mudar.
