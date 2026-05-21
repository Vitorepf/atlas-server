---
id: atlas-ai-obras-product-ux-and-use-cases
type: engineering_knowledge
title: Atlas Obras - Product UX And Use Cases
status: active
category: architecture
priority: 100
summary: Product UX, use cases, entity distinctions, workspace screens and concrete examples for Obras.
tags:
  - atlas-ai
  - obras
  - product-ux
  - use-cases
capabilities:
  - obras_product_ux_and_use_cases
  - product_workspace
decisions:
  - Obras must not be reduced to TCC, but TCC remains a strong validation case.
  - Obras must not be confused with notes, tasks or simple projects.
  - Every AI session inside Obras must produce or improve an artifact, not remain loose conversation.
maintenance:
  - Update before changing Obras screens, UX sections, use cases, entity boundaries or product language.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md
  - docs/engineering-knowledge-base/obras/data-model-and-production-graph.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 320
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-obras-product-ux-and-use-cases

graph_title: Atlas Obras - Product UX And Use Cases

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Obras - Product UX And Use Cases
canonical_name: Atlas Obras - Product UX And Use Cases
technical_name: atlas-ai-obras-product-ux-and-use-cases
cartography_type: module
canonical_source: docs/engineering-knowledge-base/obras/product-ux-and-use-cases.md

repo_paths:
  - docs/engineering-knowledge-base/obras/product-ux-and-use-cases.md

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
  - docs/engineering-knowledge-base/obras/product-ux-and-use-cases.md

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
# Atlas Obras - Product UX And Use Cases

## Difference Between Note, Task, Project And Obra

This separation is mandatory:

| Entity | Question |
|---|---|
| Note | What did I capture, think or learn? |
| Task | What needs to be done? |
| Project | Which set of actions must be executed? |
| Obra | Which relevant artifact am I building until it is ready? |

Example with a TCC:

- Note: "I read an article about AI in education."
- Task: "Write the TCC justification."
- Project: "Finish the TCC by November."
- Obra: "Complete TCC with theme, problem, chapters, sources, decisions,
  versions, review and defense."

The Obra is where everything joins.

## Main Obras Screen

The main screen lists Obras as strategic production cards:

```text
Obras
[ Atlas AI Kernel v1 ]          In Construction   Next: close Context Builder spec
[ Atlas Educacional ]           Planned           Next: define MVP
[ Atlas Constitution ]          In Review         Next: review operational laws
[ TCC: theme ]                  In Construction   Next: validate research problem
[ Course About AI ]             Planning          Next: outline module 1
```

Each card should show:

- name;
- type;
- domain;
- status;
- deadline;
- progress;
- next step;
- main risk;
- current quality;
- last activity;
- source count;
- review pending count;
- Atlas AI alerts.

Example card:

```text
TCC
Type: Academic
Domain: Atlas Educacional
Status: In Construction
Progress: 42%
Main risk: methodology still weak
Next step: validate research problem
Quality Gate: 6/10 approved
Last activity: 2h ago
```

## Inside An Obra

The mature workspace should expose:

1. Overview;
2. Structure;
3. Composer;
4. Sources;
5. Notes;
6. Tasks;
7. Decisions;
8. Feedbacks;
9. Versions;
10. Quality Gates;
11. AI;
12. Output.

The Overview is operational state, not decoration:

- provisional title;
- theme/problem/objective;
- type/domain/institution/stakeholder when relevant;
- owner/reviewer/approver;
- deadline/status/risk;
- current phase;
- next step.

## Composer

Composer is where the Obra is written, assembled or produced. It is not only a
text editor. It must be AI-contextual and tied to Obra state.

Natural actions:

- review this section;
- improve clarity;
- criticize argument;
- find gaps;
- check coherence with objective;
- detect unsupported claims;
- transform notes into section;
- generate questions for reviewer/orientador;
- prepare version for delivery.

Rule:

```text
AI works inside the Obra, with the Obra's sources, decisions, policies and context.
```

## TCC As Validation Case

Obra: TCC

Structure:

- research project;
- monograph/article;
- defense slides;
- references;
- final checklist.

Context:

- course;
- institution;
- academic manual;
- orientador;
- deadline;
- theme;
- problem;
- objectives;
- methodology.

Outputs:

- final document;
- summary;
- slides;
- presentation script;
- likely defense questions.

Ideal status from Atlas:

```text
Status:
- Problem defined, but still broad.
- General objective is coherent.
- Methodology needs validation.
- 8 sources registered, 5 fichadas.
- Introduction is draft.
- Recommended next step: close research problem scope.
- Main risk: scope is too large for the deadline.
```

## Atlas Construction As Validation Case

Obra: Atlas Self-Construction OS

Structure:

- Constitution;
- AI Implementation Packet;
- Work Splitter;
- Scope Validator;
- Reservation Ledger;
- Runtime Executor;
- Evidence Ledger;
- Drift Detector;
- Learning Loop.

This Obra uses:

- docs;
- APs;
- tests;
- command surfaces;
- Decision Receipts;
- Evidence Ledger;
- quality gates.

It is the best first serious pilot because it proves Obras can organize the
construction of Atlas itself.

## Quick Actions

Common actions:

- advance next stage;
- review structure;
- create execution plan;
- generate tasks;
- register decision;
- run quality gates;
- find gaps;
- create output;
- compare versions;
- publish version;
- archive Obra.

Conceptual CLI actions:

```text
atlas obras list
atlas obras open <obra>
atlas obras status <obra>
atlas obras review <obra> --section metodologia
atlas obras gate <obra>
atlas obras publish <obra> --format pdf
```

In the product, these actions should appear as contextual buttons and AI
actions, not raw commands.

## Final UX Snapshot

When opening an Obra, Atlas should show:

```text
Obra: Atlas AI Kernel v1
Status: In Construction
Objective: Create the persistent cognitive core of Atlas AI.
Progress: 42%
Main risk: scope still broad.
Next step: close Context Builder specification.
Gates: 5 approved, 2 pending, 1 failed.
Last decision: Prioritize Kernel before Atlas Educacional.
Outputs:
- Spec v0.3
- Pipeline diagram
- Decision log
- Initial roadmap
```

Fast actions:

- advance next step;
- run quality gates;
- ask Atlas AI for critique;
- generate documentation;
- create output;
- publish version.

## Resumo

Product UX, use cases, entity distinctions, workspace screens and concrete examples for Obras.

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
