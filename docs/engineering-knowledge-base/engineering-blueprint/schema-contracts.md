---
id: atlas-engineering-blueprint-schema-contracts
type: engineering_knowledge
title: Atlas Engineering Blueprint Schema Contracts
status: active
category: contracts
priority: 89
summary: Schema families and invariants for Engineering Blueprint payloads.
tags:
  - atlas
  - engineering
  - schema
capabilities:
  - engineering_blueprint
  - task_contracts
  - qa_evidence
decisions:
  - Blueprint schema evolution must be explicit, versioned, additive when possible and covered by tests.
maintenance:
  - Update when schema versions or payload fields change.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/archive/source-material/engineering-blueprint/contracts-full-2026-05-08.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-engineering-blueprint-schema-contracts

graph_title: Atlas Engineering Blueprint Schema Contracts

graph_world: atlas

graph_layer: module

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: engineering-blueprint

repo_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/schema-contracts.md

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
  - docs/engineering-knowledge-base/engineering-blueprint/schema-contracts.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - contract
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
# Atlas Engineering Blueprint Schema Contracts

## Schema Families

| Schema | Role |
|---|---|
| `atlas.engineering.project_blueprint.v1` | Project-level objective, context, inventory, scenarios, data model, phase plan and QA/review policy. |
| `atlas.engineering.blueprint.v1` | Task-level executable blueprint. |
| `atlas.engineering.task_contract.v1` | Strong task contract with goal, context, scope and acceptance. |
| Inventory payloads | Components, routes, models, dependencies, states and risks. |
| Scenario payloads | Happy paths, edge cases, failure modes and visual/runtime checks. |
| QA evidence | Manual or automated proof tied to acceptance and gates. |
| Review finding | Category, severity, confidence, recommendation and evidence refs. |
| Postgres review | Migration/query/index/lock/performance evidence. |

## Invariants

- `schema_version` is explicit.
- `content_hash` is deterministic.
- Frozen records are immutable.
- Supersede points to the newer version.
- Stale task blueprints are visible when upstream contract/project blueprint changes.
- Blocking gates and missing evidence are directly exposed to surfaces.

## Compatibility

Schema evolution must be additive unless a migration and compatibility adapter
are shipped with tests. App types, API resources and CLI output must evolve in
the same implementation wave.

## Resumo

Schema families and invariants for Engineering Blueprint payloads.

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
