---
id: atlas-ai-obras-patamares-l0-l5
type: engineering_knowledge
title: Atlas Obras - Patamares L0 To L5
status: active
category: architecture
priority: 100
summary: Compact maturity ladder for Obras from basic registry to Atlas Sovereign OS.
tags:
  - atlas-ai
  - obras
  - maturity-ladder
capabilities:
  - obras_operating_system
  - obraos
  - atlas_foundry
  - atlas_sovereign_os
decisions:
  - Obras must be implemented in layers while preserving the final-state ontology.
  - L5 is strategic direction, not MVP scope.
  - This active doc is the compact authority map; full source detail is archived.
maintenance:
  - Update before changing Obra maturity levels, readiness definitions or implementation order.
  - Keep this file compact; move detailed examples to focused child docs or archive source material.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/data-model-and-production-graph.md
  - docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md
  - docs/engineering-knowledge-base/obras/implementation-roadmap.md
  - docs/engineering-knowledge-base/archive/source-material/obras/patamares-l0-l5-full-2026-05-10.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-obras-patamares-l0-l5

graph_title: Atlas Obras - Patamares L0 To L5

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md

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
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md

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
# Atlas Obras - Patamares L0 To L5

Compact active ladder for implementation and review. The archived full source
keeps extended examples; this file is the operational authority.

## L0 - Tela Obras

Purpose: make `Obra` a first-class Atlas entity.

Minimum contract:
- fields: `id`, `name`, `type`, `domain`, `objective`, `status`, `deadline`,
  `priority`, `next_step`, `description`, timestamps;
- types: academic, technical, product, strategic, creative, educational,
  cognitive, financial, health, operational, research, documentation;
- statuses: Idea, Planned, In Construction, In Review, Blocked, Finished,
  Archived.

Ready when Atlas can create, list, open, edit status, update next step and
archive Obras with required-field gates.

## L1 - Workspace Vivo

Purpose: turn an Obra from a card into a living production workspace.

Workspace sections:
- Overview, Structure, Notes, Sources, Tasks;
- simple Decisions, AI Sessions, basic Versions.

Rules:
- notes, tasks and sources link to Obra nodes;
- AI sessions record `obra_id`, section, objective, context and result;
- each Obra maintains a current summary and next step.

Ready when Atlas preserves context per Obra, associates work artifacts and uses
AI inside the Obra context without losing traceability.

## L2 - Obras Enterprise

Purpose: make Obras reliable, auditable and governable.

Capabilities:
- permissions, roles, approvals and feedback;
- formal decisions and Decision Receipts;
- versions, Evidence Ledger, Quality Gates and full history;
- AI policies and traceable model/context usage.

Default personal roles:
- Owner: Vitor;
- Reviewer: Atlas AI;
- Approver: Vitor;
- Auditor: Evidence Ledger.

Ready when Atlas controls permissions, versions, decisions, evidence, gate runs
and traceable outputs.

## L3 - ObraOS

Purpose: conduct an Obra from intention to validated delivery.

Canonical flow:

```text
Simple intention -> Spec -> Plan -> Execution -> Review -> Quality Gates
-> Repair Loop -> Human Approval -> Delivery -> Evidence -> Learning
```

Core components:
- Intent Parser, Spec Driver, Planner, Executor, Reviewer;
- Repair Loop, Human Checkpoints, Output Renderer.

Autonomy ladder:
- A0 suggests only;
- A1 suggests and organizes;
- A2 executes with confirmation;
- A3 executes safe parts alone;
- A4 executes full flows with checkpoints;
- A5 supervised autonomy with strong policy.

Initial Atlas target is A2/A3. Enterprise A4+ requires logs, permissions and
reversibility.

Ready when Atlas receives an intention and conducts a simple Obra to validated
delivery with spec, plan, gates, repairs, decisions and final version.

## L4 - Atlas Foundry

Purpose: manage a strategic portfolio of Obras that become assets.

Foundry decisions:
- which Obras should exist;
- order, priority, expected return, dependencies and risks;
- how one Obra becomes an asset for another.

Components:
- Portfolio Graph, Asset Theory, Strategic Scoring;
- Opportunity Cost Engine, Spin-off Engine;
- Kill / Pause / Scale decisions.

Asset types:
- knowledge, product, code, reputation, money, network;
- process, infrastructure, documentation, personal capability, brand.

Ready when Atlas maps the portfolio, prioritizes Obras, detects dependencies
and dispersion, suggests spin-offs and recommends pause/kill/scale.

## L5 - Atlas Sovereign OS

Purpose: govern Vitor's autonomy ecosystem through Obras.

Sovereign OS asks which ecosystem must exist so Vitor continuously creates the
right Obras without damaging health, relationships, integrity or stakeholders.

Components:
- Strategic Constitution, Operator Model, Autonomy Graph;
- Constraint System, Capital Stack, Life/Business Flywheel;
- Strategic Review.

Autonomies:
- cognitive, financial, technical, operational;
- educational, emotional, relational, strategic.

Non-negotiable gates:
- no strategy may increase money while destroying health;
- no strategy may increase productivity while destroying primary relationships;
- no strategy may increase speed while reducing integrity;
- no strategy may create many Obras without focus;
- no strategy may depend on untested assumptions;
- no strategy may lack success metric or reversal plan.

Ready when Atlas connects Obras to life strategy, increases autonomy, protects
human constraints, reviews portfolio/operator state and turns deliveries into
assets.

## Final Level Definitions

- L0: "I have a place to register what I am building."
- L1: "Each Obra has context, structure, notes, sources and tasks."
- L2: "Each Obra has governance, versions, decisions, gates and evidence."
- L3: "Atlas conducts an Obra from intention to delivery."
- L4: "Atlas chooses, prioritizes and composes Obras as strategic assets."
- L5: "Atlas governs the ecosystem that turns Obras into autonomy, capital,
  reputation, knowledge and operational power."

## Resumo

Compact maturity ladder for Obras from basic registry to Atlas Sovereign OS.

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
