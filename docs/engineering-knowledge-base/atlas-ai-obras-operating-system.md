---
id: atlas-ai-obras-operating-system
type: engineering_knowledge
title: Atlas AI Obras Operating System
status: active
category: architecture
priority: 100
summary: Canonical architecture for Obras, the Atlas production primitive that transforms intention into validated, traceable and compounding assets.
tags:
  - atlas-ai
  - obras
  - production-system
  - governance
capabilities:
  - obras_operating_system
  - obraos
  - atlas_foundry
  - atlas_sovereign_os
decisions:
  - Obras is not a TCC feature and not a folder; it is a transversal production primitive.
  - Obras Shared Workspace is the canonical shared office for multi-provider production work.
  - Forge Workspace is the Programming/Atlas Forge specialization of Obras Shared Workspace.
  - Obras complements Documentation OS, Memory, Postgres, Obsidian/Vault, Evidence Ledger, SDD and AP governance.
  - The MVP may be small, but the ontology must not block L5 Sovereign OS.
  - Every Obra must have objective, type, status, next step, structure, quality gates and output intent.
maintenance:
  - Read before creating product flows for long intellectual work, TCCs, books, strategic plans, technical docs, Atlas construction or portfolio strategy.
  - Update when Obra levels, lifecycle, data model, quality gates, AI harness, Foundry or Sovereign governance changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md
  - docs/engineering-knowledge-base/obras/contracts-and-invariants.md
  - docs/engineering-knowledge-base/obras/data-model-and-production-graph.md
  - docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md
  - docs/engineering-knowledge-base/obras/product-ux-and-use-cases.md
  - docs/engineering-knowledge-base/obras/metrics-risks-and-excellence.md
  - docs/engineering-knowledge-base/obras/implementation-roadmap.md
  - docs/ap/AP-692-atlas-obras-operating-system-contract.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-obras-operating-system

graph_title: Atlas AI Obras Operating System

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md

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
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md

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
# Atlas AI Obras Operating System

## Central Thesis

Obras is not merely a screen. The weak version is "a place to organize big
projects." The strong version is:

```text
A system that transforms intention into a validated, traceable and cumulative
final artifact.
```

The TCC case is valid, but only as one example. The real pattern is:

```text
Vitor has a large intention
-> Atlas turns it into an Obra
-> Obra becomes a delivery
-> delivery becomes an asset
-> asset compounds autonomy
```

## Official Definition

Short definition:

```text
Obra is a persistent workspace for producing a relevant artifact with context,
structure, sources, decisions, tasks, versions, quality and delivery.
```

Strong definition:

```text
Obra is the Atlas operational unit for transforming intention into an asset.
```

## What An Obra Can Be

- TCC, article, book, course, research dossier;
- investment thesis, strategic plan, executive report, commercial proposal;
- technical architecture, product, large feature, enterprise documentation;
- personal plan, study system, life strategy;
- Atlas AI Kernel, Atlas Educacional, Constitution, Skill System, Self-Construction OS.

## Market Benchmark

Current products validate parts of this idea but not the full architecture:

- ChatGPT Projects validates persistent context per initiative: chats, files and instructions in one workspace.
- Codex validates intent to execution to tests to delivery in software.
- Notion AI/Agents validates enterprise workspace context, permissions, logs, reversibility, connected apps and retention.
- Asana AI Teammates validates agents operating inside shared project workflows with permissions and checkpoints.

Atlas's space is the synthesis:

```text
Obra = living workspace + agentic execution + evidence + quality + composed strategy.
```

## Relationship To Existing Atlas Systems

Obras must not replace existing systems:

| System | Role |
|---|---|
| Documentation OS | Law and canonical documentation |
| Memory / Cognitive Runtime | Continuity and context reuse |
| Postgres | Structured runtime state |
| Obsidian/Vault | Human knowledge and long-lived knowledge material |
| APs | Structural change governance |
| SDD / Spec OS | Governed implementation flow |
| Evidence Ledger | Append-only proof and audit |
| Self-Construction OS | Atlas building Atlas |
| Obras | Operational unit where knowledge, decisions and execution become deliverables |
| Obras Shared Workspace | Shared office where providers collaborate through artifacts, packets, evidence and integration |

The key:

```text
Memory remembers.
Docs govern.
Obras produce.
```

## Canonical Shared Workspace

The official name for the shared office inside this flow is:

```text
Obras Shared Workspace
```

Portuguese label:

```text
Workspace Compartilhado de Obras
```

For Programming/Atlas Forge, the specialization is:

```text
Forge Workspace = Obras Shared Workspace for Programming.
```

This replaces ad hoc provider handoff chains. Gemini, Claude, Codex, local
agents and future providers should exchange governed artifacts through the
workspace, not loose chat context.

## Maturity Ladder

| Level | Name | Function |
|---|---|---|
| L0 | Tela Obras | Register and organize Obras |
| L1 | Workspace Vivo | Preserve context, structure, notes, tasks and sources |
| L2 | Obras Enterprise | Govern with versions, decisions, permissions, evidence and gates |
| L3 | ObraOS | Conduct an Obra from intention to delivery |
| L4 | Atlas Foundry | Manage a strategic portfolio of Obras that become assets |
| L5 | Atlas Sovereign OS | Govern Vitor's autonomy ecosystem through Obras |

## Entity Boundary

Obra is not a note, task or simple project:

| Entity | Role |
|---|---|
| Note | Captures something learned or thought |
| Task | Defines work to do |
| Project | Groups actions to execute |
| Obra | Builds a relevant artifact until it is ready |

## Non-Negotiable Product Law

- If it has no objective, it is not an Obra.
- If it has no next step, it is an idea, not an active Obra.
- If it has no structure, it cannot become a high-quality deliverable.
- If it has no quality gates, it cannot claim high quality.
- If it has no output, it is incomplete.
- If its state exists only in a folder, it is not runtime Obras.
- If AI activity cannot be traced to Obra id, node, context and output, it is loose chat.
- If the output does not become an asset, it has not reached Foundry.
- If the asset does not increase autonomy, it has not reached Sovereign OS.

## Obra Is A Production Graph

Obra must not be modeled as a folder of Markdown files. Markdown can be an
export/projection. The core must be structured state plus relationships:

```text
Source -> Evidence -> Claim -> Section -> Version -> Output
Decision -> Task -> Change -> Gate -> Approval
Obra -> Asset -> Other Obra -> Portfolio
```

## Universal Quality Gates

Every relevant Obra must answer:

1. Is the objective clear?
2. Is the definition of done defined?
3. Is the structure coherent?
4. Does a next step exist?
5. Are important decisions registered?
6. Are sources/evidence linked when necessary?
7. Are risks explicit?
8. Are tradeoffs clear?
9. Is the current version identified?
10. Is the final output defined?
11. Was learning captured?
12. Is the relation to a greater objective registered?

## Lifecycle States

```text
Idea -> Intake -> Specified -> Planned -> In Construction -> In Review
-> Blocked -> Awaiting Approval -> Approved -> Published -> Archived
-> Reopened -> Replaced
```

Important transitions require minimum evidence:

- Idea -> Intake requires minimum objective.
- Intake -> Specified requires spec.
- Specified -> Planned requires phases and deliverables.
- Planned -> In Construction requires next action.
- In Construction -> In Review requires draft or version.
- In Review -> Approved requires gates.
- Approved -> Published requires output.
- Published -> Archived requires learning.

## Output Types

Obras must produce real deliverables:

- Markdown, PDF, DOCX, slides;
- spec, briefing, roadmap, backlog, checklist;
- executive report, technical document, handoff, manifesto;
- course, article, plan.

The Obra does not end in conversation. It ends in output.

## Maximum Principle

Obras is not a feature. Obras is the layer where Atlas proves it can build real
things.

Final criteria:

```text
If the Obra does not become a delivery, it is not complete.
If the delivery does not become an asset, it has not reached Foundry.
If the asset does not increase autonomy, it has not reached Sovereign OS.
```

## Resumo

Canonical architecture for Obras, the Atlas production primitive that transforms intention into validated, traceable and compounding assets.

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
