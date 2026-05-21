---
id: atlas-ai-obras-data-model-and-production-graph
type: engineering_knowledge
title: Atlas Obras - Data Model And Production Graph
status: active
category: architecture
priority: 100
summary: Data model and graph rules for modeling Obras as structured production systems rather than folders.
tags:
  - atlas-ai
  - obras
  - data-model
  - production-graph
capabilities:
  - obras_data_model_and_production_graph
  - production_graph
decisions:
  - Postgres is the live state source; Markdown is a projection/export, not the brain.
  - Obras must be modeled as a graph of production relationships.
maintenance:
  - Update before changing Obras tables, relationships, projections or storage ownership.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md
  - docs/engineering-knowledge-base/obras/contracts-and-invariants.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-obras-data-model-and-production-graph

graph_title: Atlas Obras - Data Model And Production Graph

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Obras - Data Model And Production Graph
canonical_name: Atlas Obras - Data Model And Production Graph
technical_name: atlas-ai-obras-data-model-and-production-graph
cartography_type: module
canonical_source: docs/engineering-knowledge-base/obras/data-model-and-production-graph.md

repo_paths:
  - docs/engineering-knowledge-base/obras/data-model-and-production-graph.md

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
  - obras

evidence:
  - docs/engineering-knowledge-base/obras/data-model-and-production-graph.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - obras

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
# Atlas Obras - Data Model And Production Graph

## Storage Principle

Obras is not a folder full of Markdown files.

Correct architecture:

```text
Postgres = live structured state
Object storage = PDFs, DOCX, images, attachments and generated outputs
Markdown/docs = projection, export, handoff or canonical documentation
Obsidian/Vault = promoted human knowledge and long-lived thinking material
Evidence Ledger = append-only proof
```

## Core Entities

- `obras`;
- `obra_nodes`;
- `obra_notes`;
- `obra_tasks`;
- `obra_sources`;
- `obra_claims`;
- `obra_decisions`;
- `obra_feedbacks`;
- `obra_versions`;
- `obra_outputs`;
- `obra_gate_templates`;
- `obra_gate_runs`;
- `obra_evidence_events`;
- `obra_ai_sessions`;
- `obra_context_packs`;
- `obra_assets`;
- `obra_dependencies`;
- `obra_metrics`.

## Architecture Layers

Obras requires five implementation layers:

### Layer 1 - Product UI

- Obras list;
- Obra page;
- Overview;
- Structure;
- Composer;
- Sources;
- Tasks;
- Decisions;
- Versions;
- Gates;
- Outputs;
- Foundry View;
- Sovereign View.

### Layer 2 - Core Backend

- Obra Service;
- Node Service;
- Source Service;
- Task Service;
- Decision Service;
- Version Service;
- Gate Service;
- Evidence Service;
- Output Service;
- Portfolio Service.

### Layer 3 - Atlas AI Harness

- Intent Parser;
- Context Builder;
- Skill System;
- Model Router;
- Policy Engine;
- Quality Gate Runner;
- Repair Loop;
- Memory Delta;
- Learning Loop;
- Trace System.

### Layer 4 - Execution Runtime

- Research Runtime;
- Writing Runtime;
- Code Runtime;
- Document Runtime;
- Planning Runtime;
- Review Runtime;
- Output Runtime.

### Layer 5 - Governance

- Permissions;
- Audit;
- Approvals;
- Risk Policy;
- Data Policy;
- Provider Policy;
- Human Checkpoints.

## Minimum L0 Table Shape

`obras` must eventually contain:

- id, title, type, domain, status;
- objective, description, priority, deadline;
- current_phase, next_action, risk_level;
- owner_id, created_at, updated_at, archived_at.

Minimum relationship extension:

- a node table or equivalent structured child entity;
- a generic relation/edge path for future notes, tasks, sources, decisions,
  versions, gates and outputs;
- append-only event path for evidence/history.

## Production Graph Relationships

Important relationships:

- Obra has Nodes;
- Node has Tasks;
- Node has Notes;
- Node uses Sources;
- Source supports Claims;
- Claim appears in Node;
- Decision changes Obra;
- Feedback creates Task;
- Gate validates Version;
- Output derives from Version;
- Evidence records Event;
- Asset derives from Output;
- Obra depends on Obra.

The core graph:

```text
Source -> Evidence -> Claim -> Section -> Version -> Output
Decision -> Task -> Change -> Gate -> Approval
Obra -> Asset -> Other Obra -> Portfolio
```

## Node Model

Nodes represent sections or structural units:

- overview, chapter, spec, module, decision area, appendix;
- parent/child hierarchy;
- status;
- position;
- current version;
- owner and reviewers.

Node statuses:

- empty;
- draft;
- in construction;
- needs source;
- needs decision;
- in review;
- approved;
- published.

## Source Registry

Sources must track:

- title, author, year, type;
- URL or file;
- summary;
- relevant excerpts;
- linked nodes;
- reliability;
- status.

Source statuses:

- captured;
- read;
- fichada;
- approved;
- rejected;
- used.

## Decisions

Formal decisions must track:

- what was decided;
- why;
- evidence;
- assumptions/inferences;
- tradeoffs;
- risks;
- responsible person;
- date;
- active/revised/revoked status.

## Versions

Versions must track:

- semantic version or sequence;
- changed content;
- change summary;
- author;
- date;
- gates run;
- pending issues;
- feedback source.

Examples:

- v0.1 idea;
- v0.2 structure;
- v0.3 draft;
- v0.4 review;
- v1.0 delivery;
- v1.1 post-feedback.

## Evidence Events

Evidence events include:

- Obra created;
- source added/approved;
- decision taken;
- task completed;
- gate executed/failed/approved;
- version published;
- feedback received;
- output generated;
- AI used;
- model used;
- context pack used;
- human approval.

## Workspace Projection

A filesystem projection may exist for export/handoff:

```text
.obras/
  atlas-self-construction-os/
    obra.yaml
    overview.md
    structure.md
    decisions.md
    sources.md
    tasks.md
    gates.md
    evidence.md
    outputs/
```

This projection must never be the sole runtime source of truth.

## Resumo

Data model and graph rules for modeling Obras as structured production systems rather than folders.

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
