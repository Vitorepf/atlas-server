---
id: atlas-ai-sdd-context-discovery-business-context
type: engineering_knowledge
title: Atlas SDD Context Discovery And Business Context
status: active
category: architecture
priority: 99
summary: Context discovery and business grounding rules for Atlas Spec Operating System.
tags:
  - atlas-ai
  - sdd
  - context-discovery
  - business-context
capabilities:
  - sdd_context_discovery
  - business_context_grounding
decisions:
  - SDD execution starts by reading project, product, architecture, design and code context.
  - Business context prevents Atlas from being only a code generator.
maintenance:
  - Update when context packs, Code Intelligence, business contexts or design-system extraction change.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
  - docs/engineering-knowledge-base/code-intelligence.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-sdd-context-discovery-business-context

graph_title: Atlas SDD Context Discovery And Business Context

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas SDD Context Discovery And Business Context
canonical_name: Atlas SDD Context Discovery And Business Context
technical_name: atlas-ai-sdd-context-discovery-business-context
cartography_type: module
canonical_source: docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md

owner: spec-operating-system

repo_paths:
  - docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md

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
  - spec-operating-system

evidence:
  - docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md
evidence_refs:
  - symbol: AtlasContextDiscoveryAndBusinessContextService
  - command: atlas:aaeos:context-discovery-and-business-context
  - test: AtlasContextDiscoveryAndBusinessContextTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - gear
  - module
  - spec-operating-system

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
# Atlas SDD Context Discovery And Business Context

## Inputs

Atlas should discover:

- repo, branch and active files;
- current route/screen/selection/issue;
- canonical docs and APs;
- product/business docs;
- design system and UI tokens;
- API contracts and routes;
- database schema and migrations;
- tests and fixtures;
- AGENTS/CLAUDE as auxiliary projections only;
- Code Intelligence and Knowledge DB;
- prior specs, receipts and evidence.

## Business Questions

Before spec:

- What user/problem does this serve?
- What business object is affected?
- What action is requested?
- What rule governs it?
- What risk exists?
- What has already been decided?
- What should not change?

## Confidence Classes

Every context item is classified:

- `confirmed_fact`;
- `strong_inference`;
- `hypothesis`;
- `blocking_ambiguity`.

Atlas may execute without asking only when required fields are confirmed or
strongly inferred and risk is low/medium with available gates.

## Resumo

Context discovery and business grounding rules for Atlas Spec Operating System.

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
