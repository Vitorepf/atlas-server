---
id: intent-routing
type: engineering_knowledge
title: Intent Routing
status: active
category: kernel
priority: 91
summary: Classifica intencao, risco e tipo de tarefa antes de selecionar contexto, dominio e politica.
tags: [atlas, kernel, intent, routing]
capabilities: [intent_routing, task_classification]
decisions:
  - Roteamento define tipo de trabalho; decisao de provider vem depois no Atlas Decide.
maintenance:
  - Atualizar quando novas intents oficiais forem suportadas.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: intent-routing
graph_title: Intent Routing
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/intent-routing.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
allowed_changes:
  - Ajustar taxonomia de intents com evidencia.
forbidden_changes:
  - Escolher modelo ou executar ferramenta nesta etapa.
depends_on:
  - operation-envelope
flows_to:
  - business-context
unlocks:
  - business-context
governs:
  - task-classification
evidence:
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Publicar lista canonica de intents do Atlas Code.
---

# Intent Routing

## Resumo

Intent Routing classifica o que esta sendo pedido antes de montar contexto, dominio e politica.

## Papel no Atlas

Ele evita que todo pedido seja tratado como chat generico. A intencao orienta fluxo, risco, contexto e gates posteriores.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe `operation-envelope` e alimenta `business-context`.

## Contratos

Entrada: envelope operacional. Saida: intent, risco inicial, tipo de tarefa e necessidade de clarificacao. Invariante: provider ainda nao e escolhido.

## Fluxo

Envelope entra. O roteador classifica pedido, identifica ambiguidade e encaminha para contexto de negocio.

## Regras para IA

IA nao deve pular clarificacao quando a intent bloquear seguranca, escopo ou autonomia.

## Escopo de Implementacao

Permitido: classificadores, regras de intent e fallback para clarificacao. Proibido: execucao direta.

## Dependencias

- `operation-envelope`
- `atlas-ai-agent-behavior-contract`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-pipeline.md`
- `docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md`

## Riscos

- Classificacao errada mandar tarefa para fluxo perigoso.
- Intents genericas demais reduzirem qualidade do contexto.

## Exemplos

"Refatora Decide" deve virar intent de programacao, nao conversa livre.

## Proximas Acoes

Mapear intents do Atlas Code para fluxos SDD e gates.

