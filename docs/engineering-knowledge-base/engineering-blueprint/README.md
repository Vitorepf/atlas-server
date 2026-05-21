---
id: atlas-engineering-blueprint-focused-index
type: engineering_knowledge
title: Atlas Engineering Blueprint Focused Index
status: active
category: engineering
priority: 90
summary: Local index for focused Engineering Blueprint runbook, schema and maturity docs.
tags:
  - atlas
  - engineering
  - blueprint
capabilities:
  - engineering_blueprint
decisions:
  - Large Blueprint docs are split into focused indexes and child specs.
maintenance:
  - Update when adding a focused Blueprint child doc.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
  - docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint/schema-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint/maturity-phases.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-engineering-blueprint-focused-index

graph_title: Atlas Engineering Blueprint Focused Index

graph_world: atlas

graph_layer: module

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Engineering Blueprint Focused Index
canonical_name: Atlas Engineering Blueprint Focused Index
technical_name: atlas-engineering-blueprint-focused-index
cartography_type: index
canonical_source: docs/engineering-knowledge-base/engineering-blueprint/README.md

owner: engineering-blueprint

repo_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/README.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - engineering-blueprint

evidence:
  - docs/engineering-knowledge-base/engineering-blueprint/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - index
  - engineering-blueprint

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

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Engineering Blueprint Focused Index

| Doc | Purpose |
|---|---|
| `surfaces-runbook.md` | App, CLI and API operation map. |
| `lifecycle-runbook.md` | Full product lifecycle steps. |
| `schema-contracts.md` | Schema families and invariants. |
| `maturity-phases.md` | Phase matrix and DoD. |

## Rule

Blueprint docs govern Programming's heavy engineering flow. They do not create a
separate product beside `domains/programming.md`; they are the harness/runtime
discipline consumed by Programming.

## Resumo

Local index for focused Engineering Blueprint runbook, schema and maturity docs.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
