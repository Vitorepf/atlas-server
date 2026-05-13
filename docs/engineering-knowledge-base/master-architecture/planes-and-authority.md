---
id: atlas-ai-master-planes-and-authority
type: engineering_knowledge
title: Master Architecture Planes and Authority
status: active
category: architecture
priority: 99
summary: Defines the Atlas AI planes and their authority boundaries so features enter the correct layer.
tags:
  - atlas-ai
  - master-architecture
  - planes
capabilities:
  - enterprise_orchestration
  - operational_intelligence
  - policy_profile_governance
decisions:
  - Planes are authority boundaries, not folders or UI sections.
  - Control Plane compiles the operation; it does not perform domain work.
  - Runtime executes; it never chooses mission or policy.
maintenance:
  - Update when a new plane or authority boundary is promoted.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-master-planes-and-authority

graph_title: Master Architecture Planes and Authority

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: master-architecture

repo_paths:
  - docs/engineering-knowledge-base/master-architecture/planes-and-authority.md

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
  - master-architecture

evidence:
  - docs/engineering-knowledge-base/master-architecture/planes-and-authority.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - master-architecture

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
# Planes and Authority

## Control Plane

Owns normalization and compilation: Input, Intent, Profile, Context, Policy,
Decide, Receipt. It chooses allowed path and constraints.

## Domain Plane

Owns semantic work. A domain defines flows, context needs, gates, memory
projection and evidence shape. It cannot create a private pipeline.

## Runtime Plane

Owns execution: providers, workers, harnesses, tools, Python, Go, Swift and
Laravel services. Runtime executes a receipt.

## Evidence Plane

Owns truth after execution: append-only events, projections, replay, trace,
quality packets and audit.

## Learning Plane

Owns improvement signals: memory promotion, metric trends, Curator proposals,
Rivals evidence and quality calibration.

## Surface Plane

Owns user entry and rendering: CLI, App, Mobile, API, MCP, Voice and local Mac
surfaces. Surface never decides provider, domain, tool or autonomy.

## Resumo

Defines the Atlas AI planes and their authority boundaries so features enter the correct layer.

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
