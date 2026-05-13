---
id: domain-profile-flow
type: engineering_knowledge
title: Domain Profile Flow
status: active
category: kernel
priority: 88
summary: Seleciona dominio cognitivo, sistema operacional vertical e fluxo executivo antes de montar contexto.
tags: [atlas, kernel, domain, flow]
capabilities: [domain_profile, flow_selection]
decisions:
  - Dominio cognitivo e separado de Business Context e de provider.
maintenance:
  - Atualizar quando novos dominios ou fluxos executivos forem promovidos.
related_paths:
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/programming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: domain-profile-flow
graph_title: Domain Profile Flow
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/domain-profile-flow.md
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/programming.md
allowed_changes:
  - Adicionar dominios com contratos e gates.
forbidden_changes:
  - Usar dominio como atalho para escolher provider.
depends_on:
  - business-context
  - domain-plane
flows_to:
  - context-builder
unlocks:
  - context-builder
governs:
  - domain-plane
evidence:
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/programming.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Documentar flows executivos de programacao usados pelo Atlas Code.
---

# Domain Profile Flow

## Resumo

Domain Profile Flow escolhe o dominio cognitivo e o fluxo executivo adequado para a tarefa.

## Papel no Atlas

Ele impede que o Atlas use o mesmo comportamento para programacao, pesquisa, marketing, escrita, financas ou decisao estrategica.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Depende de `business-context` e `domain-plane`, alimenta `context-builder`.

## Contratos

Entrada: contexto de negocio e intent. Saida: dominio, perfil vertical e fluxo executivo. Invariante: dominio nao e provider.

## Fluxo

Business Context informa ambiente. Domain Plane oferece dominios. O flow selecionado define contexto e gates.

## Regras para IA

IA deve declarar o dominio usado quando a tarefa virar implementacao ou decisao operacional.

## Escopo de Implementacao

Permitido: taxonomia de dominios e fluxos. Proibido: bypassar Policy Profile.

## Dependencias

- `business-context`
- `domain-plane`
- `domains/programming`

## Evidencias

- `docs/engineering-knowledge-base/domains/README.md`
- `docs/engineering-knowledge-base/domains/programming.md`

## Riscos

- Misturar domain com produto.
- Fluxo errado aplicar gates errados.

## Exemplos

Atlas Code usa dominio `programming` e fluxos SDD/Forge para transformar intent em obra executavel.

## Proximas Acoes

Publicar matriz dominio -> fluxo -> gates.

