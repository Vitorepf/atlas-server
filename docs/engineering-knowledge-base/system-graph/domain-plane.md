---
id: domain-plane
type: engineering_knowledge
title: Domain Plane
status: active
category: kernel
priority: 84
summary: Plano lateral de dominios cognitivos que alimenta Domain Profile Flow.
tags: [atlas, kernel, domain-plane]
capabilities: [domain_plane]
decisions:
  - Dominios sao fontes de perfil cognitivo, nao provedores e nao produtos.
maintenance:
  - Atualizar quando dominios forem adicionados ou aposentados.
related_paths:
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/programming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: domain-plane
graph_title: Domain Plane
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/domain-plane.md
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/programming.md
allowed_changes:
  - Adicionar dominios com contrato canonico.
forbidden_changes:
  - Tratar dominio como decision-maker.
depends_on:
  - business-context
flows_to:
  - domain-profile-flow
unlocks:
  - domain-profile-flow
governs:
  - domain-profile-flow
evidence:
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/programming.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: medium
next_actions:
  - Expandir mapa visual dos dominios na Cartografia.
---

# Domain Plane

## Resumo

Domain Plane e o plano lateral de dominios cognitivos do Atlas.

## Papel no Atlas

Ele oferece os perfis de programacao, pesquisa, escrita, decisao, marketing e outros dominios usados pelo Kernel.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Alimenta `domain-profile-flow`.

## Contratos

Entrada: catalogo de dominios. Saida: dominio selecionavel e seus limites. Invariante: dominio nao decide provider.

## Fluxo

Domain Profile Flow consulta Domain Plane para escolher o perfil operacional adequado.

## Regras para IA

IA deve consultar o dominio correto antes de montar contexto e gates.

## Escopo de Implementacao

Permitido: docs de dominios e perfis. Proibido: acoplar dominio a modelo especifico.

## Dependencias

- `domains/README`
- `domains/programming`

## Evidencias

- `docs/engineering-knowledge-base/domains/README.md`
- `docs/engineering-knowledge-base/domains/programming.md`

## Riscos

- Tudo cair em programacao por default.
- Dominios demais sem contrato operacional.

## Exemplos

Programming ativa SDD, Forge, diff, gates e evidence; Research ativa busca, fontes e curadoria.

## Proximas Acoes

Representar Domain Plane como lane lateral na Cartografia.

