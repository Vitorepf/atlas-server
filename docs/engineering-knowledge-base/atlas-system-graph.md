---
id: atlas-system-graph
type: engineering_knowledge
title: Atlas System Graph
status: active
category: architecture
priority: 100
summary: Canonical contract for the human and AI-readable graph of Atlas systems, programs, modules, artifacts, dependencies, status and evolution paths.
tags:
  - atlas
  - system-graph
  - obsidian
  - architecture
  - governance
capabilities:
  - atlas_system_graph
  - obsidian_atlas_vault
  - architecture_navigation
  - module_dependency_graph
decisions:
  - Atlas System Graph is the visual and navigable map of Atlas, not the executable source of truth.
  - Repo docs remain canonical; AtlasVault/Obsidian receives managed human-facing graph projections.
  - Every important Atlas system, program, module, artifact and dependency should become a graph node with typed links.
  - The graph exists to help humans and IAs understand what exists, what depends on what, what is obsolete and what should evolve next.
maintenance:
  - Update when a new top-level system, evolution program or module taxonomy is promoted.
  - Keep node templates stable so Obsidian graph, AI context packs and future graph exporters stay compatible.
  - Do not let Vault notes override repo docs; link back to canonical repo docs.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md
  - docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/vault/README.md
  - docs/engineering-knowledge-base/vault/contracts.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
owner: atlas-ai
layer: 0.5-documentation
line_limit: 360
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-system-graph

graph_title: Atlas System Graph

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas System Graph
canonical_name: Atlas System Graph
technical_name: atlas-system-graph
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-system-graph.md

repo_paths:
  - docs/engineering-knowledge-base/atlas-system-graph.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-system-graph.md
evidence_refs:
  - symbol: AtlasSystemGraphService
  - command: atlas:aaeos:system-graph
  - test: AtlasSystemGraphTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

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
# Atlas System Graph

## Purpose

Atlas System Graph is the living map of Atlas.

It lets Vitor and any AI see:

- the total hierarchy of Atlas;
- which systems are above, below or beside each other;
- which programs are active, future, obsolete or blocked;
- which modules depend on other modules;
- which artifacts prove implementation;
- which decisions created a path;
- which next module should be built, replaced, repaired or removed.

## Authority

The graph is a navigation and reasoning surface.

```text
Repo docs / Postgres / code / tests = operational authority
AtlasVault / Obsidian graph = human visual understanding and review
Chat history = source material only
```

No Vault graph node may override a canonical repo document. If there is conflict,
read `atlas-ai-canonical-architecture-index.md`.

## Maturity Levels

```text
L0 Atlas System Graph: contract, hierarchy and schema.
L1 Atlas Living Architecture Graph: real Obsidian nodes with links/status.
L2 Atlas Executable Architecture Graph: graph validated against code/evidence.
L3 Atlas Strategic Evolution Graph: graph recommends priorities and risks.
L4 Atlas Self-Programming Graph: graph supports governed self-modification.
```

L1 is governed by `system-graph/living-architecture-graph-contract.md`.

## Canonical Top Hierarchy

```text
Atlas
├── Atlas Sovereign System
├── Atlas Evolution System
├── Atlas AI Kernel System
├── Atlas Obras System
├── Atlas Programming / Forge System
├── Atlas Memory / Vault System
├── Atlas Evidence System
├── Atlas Documentation Operating System
├── Atlas Governance / Policy System
├── Atlas Domain Systems
├── Atlas Runtime / Capability System
└── Atlas Product Surface System
```

## Sovereign System

```text
Atlas Sovereign System
├── Strategic Constitution
├── Operator Model
├── Autonomy Graph
├── Constraint System
├── Capital Stack
└── Life / Business Flywheel
```

Role: defines why Atlas exists, what it must protect and what kind of autonomy
it should increase.

## Evolution System

```text
Atlas Evolution System
├── Atlas Self-Construction OS
│   ├── Atlas Agent Control Plane
│   ├── Work Splitter
│   ├── Scope Validator
│   ├── AI Implementation Packet
│   └── Multi-Session Readiness Gate
├── Atlas Self-Programming OS
│   ├── Self-Modification Policy
│   ├── Architecture Mutation Engine
│   ├── Proposal Simulator
│   ├── Self-Programming Decision Receipts
│   └── Autonomous Repair Loop
└── Learning / Self-Improvement
```

Role: governs how Atlas changes, builds itself and eventually programs itself.

## Kernel System

Role: central decision pipeline. Surface, provider, runtime and tools do not
decide; the Kernel decides through policy, receipt, evidence and gates.

Initial nodes: Surface Plane, Surface Adapter, Atlas Input, Operation Envelope,
Intent/Routing, Business Context, Domain/Profile/Flow, Context Builder,
Policy/Profile, Atlas Decide, Decision Receipt, Runtime/Executor, Quality
Gates, Repair/Escalation, Evidence Ledger, Learning/Proposals and Output
Renderer. The build contract contains the full node table.

## Obras System

Role: transforms intention into validated artifacts, then assets, then strategic
autonomy. Obras is where long-running intellectual and technical production
lives.

Initial nodes: L0 Tela Obras, L1 Workspace Vivo, L2 Obras Enterprise, L3
ObraOS, L4 Atlas Foundry and L5 Atlas Sovereign OS.

## Programming / Forge System

Role: specialized programming engine for heavy code work, multi-agent work,
SDD, implementation packets and integration.

Initial nodes: Programming Domain, Atlas Programming Forge Flow, SDD Core,
Forge Workspace, Atlas Agent Control Plane, Work Splitter, Scope Validator,
AI Implementation Packet, Execution Workspace and Quality/Integration Gates.

## Support Systems

Support systems cover Memory/Vault, Evidence, Documentation and Governance.
Their initial child nodes live in `node-catalog-and-build-contract.md`.

## Domains, Runtimes And Surfaces

Domain, Runtime and Surface nodes cover product areas, capabilities and user
interfaces. Their detailed expansion belongs in the build contract or focused
domain docs, not in this root map.

## Node Types

Every graph node must have one primary type:

| Type | Meaning |
|---|---|
| `system` | large permanent operating area |
| `program` | strategic evolution effort with phases and outputs |
| `module` | reusable capability inside a system/program |
| `submodule` | smaller implementation unit |
| `artifact` | doc, spec, code package, output, template or report |
| `decision` | durable architectural or strategic decision |
| `evidence` | proof, test, run, ledger event or validation |
| `risk` | known failure mode or constraint |
| `obsolete` | replaced or deprecated element |

## Relationship Types

Use explicit relationship labels in frontmatter and readable wiki links in body.

| Relation | Meaning |
|---|---|
| `parent` | direct container |
| `contains` | children owned by this node |
| `depends_on` | must exist before this works |
| `unlocks` | becomes possible after this node |
| `feeds` | supplies context, data or evidence |
| `governs` | defines policy for another node |
| `implements` | code/artifact realizes a spec |
| `replaces` | supersedes an older node |
| `blocked_by` | cannot progress without another node/action |
| `spin_off` | next Obra/program created from this output |

## Status And Color Semantics

Recommended Obsidian colors/tags:

| Status | Tag | Meaning |
|---|---|---|
| `active` | `#status/active` | governs current work |
| `building` | `#status/building` | implementation in progress |
| `planned` | `#status/planned` | approved, not implemented |
| `future` | `#status/future` | desired later |
| `blocked` | `#status/blocked` | blocked by dependency/decision |
| `implemented` | `#status/implemented` | exists with evidence |
| `obsolete` | `#status/obsolete` | replaced, do not build on |
| `archive` | `#status/archive` | preserved history |

Suggested color mental model:

- green: active/implemented;
- blue: system/module;
- yellow: planned/building;
- red: risk/blocked/obsolete;
- purple: sovereign/evolution programs.

## Module Note Template

Use `system-graph/node-catalog-and-build-contract.md` and the Vault template
`AtlasVault/_templates/atlas-system-graph-module-template.md` for the exact node
shape. Every node must include type, status, parent, dependencies, unlocks,
canonical doc, evidence, risks and next actions when known.

## Vault Projection Rules

The AtlasVault projection should live under:

```text
AtlasVault/00-constituicao/atlas-system-graph.md
AtlasVault/_templates/atlas-system-graph-module-template.md
```

Rules:

- use Obsidian wiki links for nodes: `[[Atlas Self-Construction OS]]`;
- keep the Vault note human-readable and visual;
- link each major node back to canonical repo docs;
- do not store secrets, credentials or raw provider context;
- mark speculative nodes as `future` or `planned`;
- never let a Vault note become the only source for implementation.

## Build Phases

Use `system-graph/node-catalog-and-build-contract.md` as the implementation
packet for another AI session. It defines target vault path, file names,
frontmatter, initial node catalog, dependencies and validation checklist.

## Definition Of Done

Atlas System Graph v1 is ready when:

- repo contract exists and is linked from canonical indexes;
- AtlasVault root note exists in iCloud vault;
- module template exists in AtlasVault;
- every top-level Atlas system has a node;
- active programs and modules have parent, status, canonical doc and next action;
- another AI can read the graph and know where to implement without chat memory.

## Resumo

Canonical contract for the human and AI-readable graph of Atlas systems, programs, modules, artifacts, dependencies, status and evolution paths.

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
