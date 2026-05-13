---
id: atlas-living-architecture-graph-contract
type: engineering_knowledge
title: Atlas Living Architecture Graph Contract
status: active
category: architecture
priority: 99
summary: Contract for the next maturity level of Atlas System Graph: a live Obsidian graph with real nodes, links, statuses, dependencies, evidence and decision support.
tags:
  - atlas
  - system-graph
  - living-architecture-graph
  - obsidian
  - governance
capabilities:
  - atlas_system_graph
  - atlas_living_architecture_graph
  - architecture_navigation
  - vault_projection
decisions:
  - Atlas Living Architecture Graph is the operational Obsidian realization of Atlas System Graph.
  - It is still a human/AI navigation and decision surface, not the source of executable truth.
  - Each relevant Atlas system, program, module, artifact, risk and decision should become a real linked node.
  - The graph must show status, dependencies, unlocks, evidence and next actions so humans and IAs can decide what to build, replace or stop.
maintenance:
  - Update when graph maturity levels, node update rules or Obsidian projection rules change.
  - Keep this focused on the live Obsidian graph; executable validation belongs to a future Executable Architecture Graph contract.
related_paths:
  - docs/engineering-knowledge-base/atlas-system-graph.md
  - docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md
  - docs/engineering-knowledge-base/vault/README.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
owner: atlas-ai
layer: 0.5-documentation
line_limit: 300
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-living-architecture-graph-contract

graph_title: Atlas Living Architecture Graph Contract

graph_world: atlas

graph_layer: system

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md

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
  - docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md

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
# Atlas Living Architecture Graph Contract

## Definition

Atlas Living Architecture Graph is the next maturity level after Atlas System
Graph.

```text
Atlas System Graph = contract, hierarchy and graph schema.
Atlas Living Architecture Graph = real Obsidian graph with one note per node.
```

It turns the architecture from a document into a navigable, status-aware graph.

## Purpose

The Living Architecture Graph exists so Vitor and any AI can answer:

- what exists inside Atlas, not only the macro name;
- what is above, below or beside a module;
- what is active, planned, future, blocked, obsolete or implemented;
- what depends on what;
- what unlocks the next stage;
- which decisions changed direction;
- which evidence proves a module;
- which node should be evolved, replaced, removed or split.

## Maturity Ladder

```text
L0 Atlas System Graph
   Contract, hierarchy, node model and build plan.

L1 Atlas Living Architecture Graph
   Obsidian nodes, links, status, dependencies, unlocks and next actions.

L2 Atlas Executable Architecture Graph
   Graph connected to code, docs, tests, commands, evidence and health checks.

L3 Atlas Strategic Evolution Graph
   Graph recommends priorities, risks, replacements and next programs.

L4 Atlas Self-Programming Graph
   Graph is used by Atlas to propose and execute governed self-modification.
```

This document governs L1.

## Difference From System Graph

| Layer | Role | Output |
|---|---|---|
| System Graph | defines the map and schema | repo docs and root Vault note |
| Living Architecture Graph | realizes the map as live nodes | many Obsidian notes with links |
| Executable Architecture Graph | validates graph against implementation | code/evidence/status sync |
| Strategic Evolution Graph | reasons over priorities and leverage | recommendations and roadmap |
| Self-Programming Graph | governs self-change execution | proposals, receipts and patches |

The Living Graph is the first level where the Obsidian visual graph becomes
really useful.

## Target Location

```text
/Users/vitorepf/Library/Mobile Documents/iCloud~md~obsidian/Documents/AtlasVault/00-constituicao/atlas-system-graph/
```

Root note:

```text
/Users/vitorepf/Library/Mobile Documents/iCloud~md~obsidian/Documents/AtlasVault/00-constituicao/atlas-system-graph.md
```

Template:

```text
/Users/vitorepf/Library/Mobile Documents/iCloud~md~obsidian/Documents/AtlasVault/_templates/atlas-system-graph-module-template.md
```

## L1 Required Capabilities

The Living Architecture Graph v1 must provide:

1. one markdown note per top-level Atlas system;
2. one markdown note per active evolution program;
3. one markdown note per active Self-Construction module;
4. one markdown note per Kernel pipeline stage;
5. one markdown note per Obras maturity level;
6. stable wiki links between parent, children, dependencies and unlocks;
7. frontmatter with type, status, owner, parent and canonical doc;
8. visible `next_actions` for non-terminal nodes;
9. evidence links where implementation already exists;
10. `planned` or `future` status instead of pretending unknown work is done.

## Node Update Rules

When an AI updates the Living Graph:

- it may create missing node files from the catalog;
- it may fill empty fields using repo docs as evidence;
- it must not overwrite human-written sections without preserving them;
- it must not mark a node `implemented` without evidence;
- it must not use chat memory as canonical evidence;
- it must link back to repo docs, tests, commands or code paths;
- it must prefer `planned` when status is uncertain;
- it must add a next action unless the node is implemented, obsolete or archived.

## Status Meaning In Living Graph

| Status | Meaning In Obsidian |
|---|---|
| `active` | governs current architecture or behavior |
| `building` | currently being implemented or refined |
| `planned` | approved direction, not yet implemented |
| `future` | later stage, not current build target |
| `blocked` | cannot progress until dependency/decision changes |
| `implemented` | exists with evidence |
| `obsolete` | superseded, should not guide new work |
| `archive` | preserved history only |

## Minimum Node Body

Every L1 node should be readable by a human in under one minute:

```md
# Node Name

## Role
One paragraph.

## Position
Parent, siblings and children.

## Dependencies
Wiki links.

## Unlocks
Wiki links.

## Evidence
Canonical docs, code, tests or commands.

## Next Actions
Concrete next step.
```

## First Build Scope

Another Codex can build L1 without touching application code.

Allowed write scope:

```text
AtlasVault/00-constituicao/atlas-system-graph/
AtlasVault/00-constituicao/atlas-system-graph.md
AtlasVault/_templates/atlas-system-graph-module-template.md
```

Forbidden in this L1 build:

- changing app code;
- changing migrations;
- changing providers;
- rewriting canonical repo docs unless explicitly requested;
- marking speculative work as implemented;
- deleting existing Vault notes.

## Why This Is Another Patamar

Atlas System Graph gives the Atlas a map.

Atlas Living Architecture Graph gives Vitor and the AIs a shared mental
workspace where the system can be seen, navigated and reasoned about.

The shift is:

```text
architecture as text
-> architecture as visible connected system
```

This reduces context loss, improves parallel work, makes modules easier to
replace, exposes dependencies and makes future Obras/Forge/Self-Programming work
more governable.

## Definition Of Done

Living Architecture Graph v1 is ready when:

- root Vault note links to the graph folder;
- every top-level system has a node;
- Self-Construction OS and Self-Programming OS have nodes;
- Agent Control Plane, Work Splitter, Scope Validator and AI Implementation
  Packet have nodes;
- all nodes have type, status, parent, canonical doc and next action;
- core dependencies and unlocks are visible in Obsidian graph;
- no node claims implementation without evidence;
- another AI can open the Vault graph and understand where to work next.

## Resumo

Contract for the next maturity level of Atlas System Graph: a live Obsidian graph with real nodes, links, statuses, dependencies, evidence and decision support.

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
