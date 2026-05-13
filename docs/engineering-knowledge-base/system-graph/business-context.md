---
id: business-context
type: engineering_knowledge
title: Business Context
status: active
category: kernel
priority: 88
summary: Injeta projeto, produto, empresa, ambiente e contexto de negocio sem substituir o dominio cognitivo.
tags: [atlas, kernel, business-context]
capabilities: [business_context, product_context]
decisions:
  - Business Context influencia privacidade, risco e prioridade; ele nao e dominio cognitivo.
maintenance:
  - Atualizar quando novos contextos de negocio forem documentados.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: business-context
graph_title: Business Context
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/business-context.md
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
allowed_changes:
  - Atualizar campos de projeto, produto e ambiente.
forbidden_changes:
  - Confundir contexto de negocio com dominio cognitivo.
depends_on:
  - intent-routing
flows_to:
  - domain-profile-flow
unlocks:
  - domain-profile-flow
governs:
  - project-context
evidence:
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Ligar Obras reais do Atlas Code ao Business Context.
visual_tags:
  - module
  - module
  - system-graph

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok
---
# Business Context

## Resumo

Business Context adiciona projeto, produto, empresa, ambiente e objetivo operacional ao pedido.

## Papel no Atlas

Ele faz o Kernel entender onde a tarefa vive sem transformar contexto de negocio em dominio tecnico.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe de `intent-routing` e alimenta `domain-profile-flow`.

## Contratos

Entrada: intent e envelope. Saida: contexto de projeto, produto, ambiente, sensibilidade e prioridade. Invariante: nao decide provider.

## Fluxo

Intent encontra Obra, workspace e contexto de negocio. O resultado orienta dominio e perfil de execucao.

## Regras para IA

IA deve diferenciar "para qual negocio" de "qual dominio cognitivo resolve".

## Escopo de Implementacao

Permitido: contexto de Obra, workspace e produto. Proibido: alterar policy sem passar por Policy Profile.

## Dependencias

- `intent-routing`
- `atlas-ai-business-contexts`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-business-contexts.md`

## Riscos

- Contexto obsoleto guiar implementacao errada.
- Business Context conter segredo sem policy adequada.

## Exemplos

Uma tarefa de programacao em Atlas Code deve saber a Obra, repo e objetivo antes de montar contexto.

## Proximas Acoes

Persistir relacao Obra -> Business Context nos endpoints do Atlas Code.
