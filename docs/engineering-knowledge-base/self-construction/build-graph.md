---
id: atlas-ai-self-construction-build-graph
type: engineering_knowledge
title: Atlas Self-Construction Build Graph
status: active
category: architecture
priority: 100
summary: Dependency graph for constructing Atlas in the right order.
tags:
  - atlas-ai
  - self-construction
  - build-graph
capabilities:
  - self_construction_os
  - dependency_governance
decisions:
  - Atlas must build high-leverage dependencies before surface features.
  - Self-programming depends on memory, retrieval, SDD, evidence, gates and drift detection.
maintenance:
  - Update before reprioritizing Atlas roadmap, runtime slices or self-programming work.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-build-graph

graph_title: Atlas Self-Construction Build Graph

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/build-graph.md

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
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/build-graph.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - module
  - self-construction

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
# Atlas Self-Construction Build Graph

The build graph prevents Atlas from implementing impressive-looking features
before the foundations that make them reliable.

## Core Dependencies

```text
Documentation OS
-> Knowledge Governance
-> Evidence Ledger
-> Code Intelligence
-> Cognitive Runtime
-> Research Self-Improvement Runtime
-> Spec Operating System
-> Tool Runtime / Quality Gates
-> Self-Construction OS Runtime
-> Voice/Mobile/Product autonomy
-> Strategic self-programming
```

## Dependency Table

| Capability | Depends On | Why |
|---|---|---|
| Memory | Evidence, privacy, source truth | Avoid remembering wrong or unsafe context. |
| Retrieval | Knowledge DB, Code Intelligence, source ranking | Bring the right context instead of stuffing prompts. |
| Long sessions | Memory, compaction, handoff, drift metrics | Preserve quality over time. |
| Research runtime | Source registry, evidence lake, claim verification | Prevent hallucinated evolution. |
| SDD runtime | Context, business rules, receipts, gates | Turn intent into safe implementation. |
| Self-programming | SDD, Evidence, Drift, Tool Runtime, rollback | Let Atlas change itself safely. |
| Voice realtime | Kernel, surface adapter, runtime certification | Make voice a surface, not a parallel brain. |
| Mobile product | API/surface contracts, auth, realtime, UX gates | Make the product usable without bypassing core. |

## Build Order Law

When work competes, prefer the block that improves multiple downstream
capabilities.

Priority examples:

- memory/retrieval beats decorative UI;
- SDD runtime beats isolated feature coding;
- evidence/drift beats extra autonomy;
- research verification beats unverified implementation ideas;
- quality gates beat new provider wrappers.

## Blocking Rule

Do not promote a dependent capability if its prerequisite is below the required
maturity.

Example:

```text
Self-programming cannot exceed L5 while SDD runtime, Evidence Ledger, rollback
and drift detection are below L5.
```

## Build Graph Packet

Every construction spec must include:

```yaml
build_graph:
  target_capability:
  prerequisites:
  downstream_capabilities:
  blocked_by:
  unlocks:
  maturity_before:
  maturity_after:
```

## Drift Signal

If implementation creates a capability outside the build graph, emit drift:

```text
Capability exists without canonical dependency placement.
```

The correction is either to attach it to the graph or remove/defer it.

## Resumo

Dependency graph for constructing Atlas in the right order.

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
