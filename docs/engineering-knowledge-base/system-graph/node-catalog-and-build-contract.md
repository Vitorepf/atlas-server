---
id: atlas-system-graph-node-catalog-and-build-contract
type: engineering_knowledge
title: Atlas System Graph Node Catalog And Build Contract
status: active
category: architecture
priority: 99
summary: Initial node catalog and implementation contract for building the Atlas System Graph in AtlasVault/Obsidian and future graph exporters.
tags:
  - atlas
  - system-graph
  - obsidian
  - node-catalog
capabilities:
  - atlas_system_graph
  - graph_node_catalog
  - vault_projection
decisions:
  - The graph must be built from typed nodes, not from one giant narrative note.
  - Top-level systems and active evolution modules must be created before deep leaf nodes.
  - Every node must state parent, status, dependencies, unlocks, canonical doc and next action when known.
maintenance:
  - Update when top-level systems, active programs or graph node fields change.
  - Keep this as the build packet for another AI session.
related_paths:
  - docs/engineering-knowledge-base/atlas-system-graph.md
  - docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/vault/README.md
owner: atlas-ai
layer: 0.5-documentation
line_limit: 300
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-system-graph-node-catalog-and-build-contract

graph_title: Atlas System Graph Node Catalog And Build Contract

graph_world: atlas

graph_layer: system

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas System Graph Node Catalog And Build Contract
canonical_name: Atlas System Graph Node Catalog And Build Contract
technical_name: atlas-system-graph-node-catalog-and-build-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md

repo_paths:
  - docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md

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
  - system-graph

evidence:
  - docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - contract
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

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas System Graph Node Catalog And Build Contract

## Scope

This document tells another AI how to build the actual Obsidian graph nodes for
Atlas System Graph.

The source contract is `atlas-system-graph.md`. This file is the build plan.
The maturity contract for the resulting live graph is
`living-architecture-graph-contract.md`.

## Target Vault

```text
/Users/vitorepf/Library/Mobile Documents/iCloud~md~obsidian/Documents/AtlasVault
```

Recommended graph folder:

```text
00-constituicao/atlas-system-graph/
```

Keep the existing root:

```text
00-constituicao/atlas-system-graph.md
```

## File Naming

Use stable ASCII slugs:

```text
atlas.md
atlas-sovereign-system.md
atlas-evolution-system.md
atlas-self-construction-os.md
atlas-agent-control-plane.md
```

The note title may be human-friendly. The filename should remain stable.

## Required Frontmatter

```yaml
graph_id:
type:
status:
owner:
parent:
contains: []
depends_on: []
unlocks: []
governs: []
implements: []
canonical_doc:
repo_paths: []
evidence: []
risks: []
next_actions: []
tags:
  - atlas-system-graph
```

## Build Order

1. Create folder `00-constituicao/atlas-system-graph/`.
2. Create top node `atlas.md`.
3. Create all top-level systems.
4. Create active evolution programs and modules.
5. Create Kernel pipeline nodes.
6. Create Obras levels.
7. Create support systems.
8. Add dependencies/unlocks.
9. Add canonical docs and repo paths.
10. Run a link check and inspect Obsidian graph visually.

## Top-Level Nodes

| Node | Type | Parent | Status |
|---|---|---|---|
| Atlas | system | none | active |
| Atlas Sovereign System | system | Atlas | active |
| Atlas Evolution System | system | Atlas | active |
| Atlas AI Kernel System | system | Atlas | active |
| Atlas Obras System | system | Atlas | active |
| Atlas Programming Forge System | system | Atlas | active |
| Atlas Memory Vault System | system | Atlas | active |
| Atlas Evidence System | system | Atlas | active |
| Atlas Documentation Operating System | system | Atlas | active |
| Atlas Governance Policy System | system | Atlas | active |
| Atlas Domain Systems | system | Atlas | active |
| Atlas Runtime Capability System | system | Atlas | active |
| Atlas Product Surface System | system | Atlas | active |

## Sovereign Nodes

| Node | Type | Parent | Status |
|---|---|---|---|
| Strategic Constitution | module | Atlas Sovereign System | active |
| Operator Model | module | Atlas Sovereign System | planned |
| Autonomy Graph | module | Atlas Sovereign System | planned |
| Constraint System | module | Atlas Sovereign System | active |
| Capital Stack | module | Atlas Sovereign System | planned |
| Life Business Flywheel | module | Atlas Sovereign System | planned |

## Evolution Nodes

| Node | Type | Parent | Status | Unlocks |
|---|---|---|---|---|
| Atlas Self-Construction OS | program | Atlas Evolution System | building | Atlas Self-Programming OS |
| Atlas Self-Programming OS | program | Atlas Evolution System | future | autonomous governed evolution |
| Learning Self-Improvement | module | Atlas Evolution System | active | proposal loops |
| Atlas Agent Control Plane | module | Atlas Self-Construction OS | building | two Codex, five providers |
| Work Splitter | module | Atlas Self-Construction OS | building | packet distribution |
| Scope Validator | module | Atlas Self-Construction OS | building | safe parallel work |
| AI Implementation Packet | module | Atlas Self-Construction OS | building | provider-neutral execution |
| Multi-Session Readiness Gate | module | Atlas Self-Construction OS | building | parallel sessions |

## Self-Programming Future Nodes

| Node | Type | Parent | Status |
|---|---|---|---|
| Self-Modification Policy | module | Atlas Self-Programming OS | future |
| Architecture Mutation Engine | module | Atlas Self-Programming OS | future |
| Proposal Simulator | module | Atlas Self-Programming OS | future |
| Self-Programming Decision Receipts | module | Atlas Self-Programming OS | future |
| Autonomous Repair Loop | module | Atlas Self-Programming OS | future |

## Kernel Nodes

| Node | Type | Parent | Status |
|---|---|---|---|
| Surface Plane | module | Atlas AI Kernel System | active |
| Surface Adapter | module | Atlas AI Kernel System | active |
| Atlas Input | module | Atlas AI Kernel System | active |
| Operation Envelope | module | Atlas AI Kernel System | active |
| Intent Routing | module | Atlas AI Kernel System | active |
| Business Context | module | Atlas AI Kernel System | active |
| Domain Profile Flow | module | Atlas AI Kernel System | active |
| Context Builder | module | Atlas AI Kernel System | active |
| Policy Profile | module | Atlas AI Kernel System | active |
| Atlas Decide | module | Atlas AI Kernel System | active |
| Decision Receipt | module | Atlas AI Kernel System | active |
| Runtime Executor | module | Atlas AI Kernel System | active |
| Quality Gates | module | Atlas AI Kernel System | active |
| Repair Escalation | module | Atlas AI Kernel System | active |
| Evidence Ledger | module | Atlas AI Kernel System | active |
| Learning Proposals | module | Atlas AI Kernel System | active |
| Output Renderer | module | Atlas AI Kernel System | active |

## Obras Nodes

| Node | Type | Parent | Status |
|---|---|---|---|
| L0 Tela Obras | module | Atlas Obras System | planned |
| L1 Workspace Vivo | module | Atlas Obras System | planned |
| L2 Obras Enterprise | module | Atlas Obras System | planned |
| L3 ObraOS | module | Atlas Obras System | future |
| L4 Atlas Foundry | module | Atlas Obras System | future |
| L5 Atlas Sovereign OS | module | Atlas Obras System | future |
| Obras Shared Workspace | module | Atlas Obras System | active |
| Forge Workspace | module | Atlas Programming Forge System | building |

## Programming / Forge Nodes

| Node | Type | Parent | Status |
|---|---|---|---|
| Programming Domain | system | Atlas Programming Forge System | active |
| SDD Core | module | Atlas Programming Forge System | active |
| Forge Workspace | module | Atlas Programming Forge System | building |
| Atlas Agent Control Plane | module | Atlas Programming Forge System | building |
| Work Splitter | module | Atlas Programming Forge System | building |
| Scope Validator | module | Atlas Programming Forge System | building |
| AI Implementation Packet | module | Atlas Programming Forge System | building |
| Execution Workspace | module | Atlas Programming Forge System | planned |
| Quality Integration Gates | module | Atlas Programming Forge System | active |

## Core Dependencies

| From | Relation | To |
|---|---|---|
| Atlas Self-Programming OS | depends_on | Atlas Self-Construction OS |
| Atlas Self-Construction OS | depends_on | Atlas Documentation Operating System |
| Atlas Self-Construction OS | depends_on | Atlas AI Kernel System |
| Atlas Agent Control Plane | depends_on | AI Implementation Packet |
| Atlas Agent Control Plane | depends_on | Scope Validator |
| Atlas Agent Control Plane | unlocks | Two Codex Execution |
| Work Splitter | unlocks | Multi Provider Parallel Execution |
| Obras Shared Workspace | feeds | Forge Workspace |
| Forge Workspace | depends_on | Atlas Agent Control Plane |
| Evidence Ledger | governs | Work Products |
| Decision Receipt | governs | Self-Programming Decision Receipts |

## AI Instructions

When building nodes:

- do not invent implementation status;
- use `planned` when uncertain;
- use `future` for Self-Programming nodes not started;
- link every node to at least one parent;
- prefer explicit `depends_on` and `unlocks`;
- keep one node per file;
- never overwrite human edits without review;
- if a node exists, update only missing fields and add evidence.

## Validation Checklist

- Root note exists.
- Template exists.
- Every top-level node exists.
- Every node has `graph_id`, `type`, `status`, `parent` and `canonical_doc`.
- No node has empty `next_actions` unless status is `implemented` or `archive`.
- Obsidian graph shows clusters by system.
- Repo docs remain linked as canonical source.

## Resumo

Initial node catalog and implementation contract for building the Atlas System Graph in AtlasVault/Obsidian and future graph exporters.

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
