---
id: atlas-ai-obras-patamares-l0-l5
type: engineering_knowledge
title: Atlas Obras - Patamares L0 To L5
status: active
category: architecture
priority: 100
summary: Full maturity ladder for Obras from a basic screen to Atlas Sovereign OS.
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
maintenance:
  - Update before changing Obra maturity levels, readiness definitions or implementation order.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/data-model-and-production-graph.md
  - docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md
  - docs/engineering-knowledge-base/obras/implementation-roadmap.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 320
---

# Atlas Obras - Patamares L0 To L5

## L0 - Tela Obras

Goal: create Obra as a first-class Atlas entity.

Required fields:

- id, name, type, domain, objective;
- status, deadline, priority, next step;
- description, created_at, updated_at.

Types:

- academic, technical, product, strategic, creative;
- educational, cognitive, financial, health, operational;
- research, documentation.

Initial statuses:

- Idea, Planned, In Construction, In Review, Blocked, Finished, Archived.

L0 gate:

- name exists;
- objective exists;
- type exists;
- status exists;
- next step exists.

L0 is ready when Atlas can create, list, open, edit status, update next step
and archive Obras.

## L1 - Workspace Vivo

Goal: transform Obra from static card into a living production workspace.

Inside each Obra:

- Overview;
- Structure;
- Notes;
- Sources;
- Tasks;
- simple decisions;
- AI sessions;
- basic versions.

Structure example for Atlas AI Kernel:

```text
Vision
Problem
Architecture
Pipeline
Context Pack
Skill System
Quality Gates
Evidence Ledger
Roadmap
Documentation
```

Notes and tasks must connect to Obra nodes. Sources must have type, link/file,
summary, status and linked section. AI sessions must know `obra_id`, section,
session objective, used context and generated result.

L1 gates:

- clear objective;
- minimum structure;
- next step;
- tasks linked to structure;
- notes linked to Obra;
- updated summary.

L1 is ready when Atlas preserves context by Obra, associates notes/tasks/sources,
generates Obra summary, shows progress and uses AI inside Obra context.

## L2 - Obras Enterprise

Goal: make Obras reliable, auditable and governable.

Capabilities:

- permissions and roles;
- formal decisions;
- versions;
- Evidence Ledger;
- Quality Gates;
- approvals;
- feedbacks;
- full history;
- AI policies.

Roles:

- Owner, Editor, Reviewer, Approver, Viewer, Auditor, External Collaborator.

For personal Atlas:

- Owner: Vitor;
- Reviewer: Atlas AI;
- Approver: Vitor;
- Auditor: Evidence Ledger.

Decision Receipts must include decision, rationale, evidence, inferences,
tradeoffs, risks, owner, date and status.

Evidence events include Obra created, source added/approved, decision made,
task completed, gate run, version published, feedback received, output
generated, AI/model/context used and human approval.

L2 is ready when Atlas controls permissions, versions, decisions, evidence,
feedbacks, gate runs and traceable outputs.

## L3 - ObraOS

Goal: transform Obras from governed workspace into end-to-end execution system.

Formula:

```text
Simple intention -> Spec -> Plan -> Execution -> Review -> Quality Gates
-> Repair Loop -> Human Approval -> Delivery -> Evidence -> Learning
```

Core components:

- Intent Parser;
- Spec Driver;
- Planner;
- Executor;
- Reviewer;
- Repair Loop;
- Human Checkpoints;
- Output Renderer.

Autonomy levels:

- A0 suggests only;
- A1 suggests and organizes;
- A2 executes with confirmation;
- A3 executes safe parts alone;
- A4 executes full flows with checkpoints;
- A5 supervised autonomy with strong policy.

Initial personal Atlas target: A2/A3. Enterprise A4 requires logs, permissions
and reversibility.

L3 gates:

- spec exists;
- plan exists;
- deliverables generated;
- gates ran;
- critical failures repaired;
- decisions registered;
- final version created;
- next step or closure defined.

L3 is ready when Atlas receives an intention and conducts a simple Obra to a
validated delivery.

## L4 - Atlas Foundry

Goal: manage a strategic portfolio of Obras that become assets.

Foundry decides:

- which Obras should exist;
- order and priority;
- expected return;
- dependencies and risks;
- how one Obra becomes an asset for another.

Components:

- Portfolio Graph;
- Asset Theory;
- Strategic Scoring;
- Opportunity Cost Engine;
- Spin-off Engine;
- Kill / Pause / Scale decisions.

Example priority response:

```text
Priority 1: Atlas AI Kernel
Reason: unlocks the other Obras.

Priority 2: Atlas Constitution
Reason: defines laws, identity and quality.

Priority 3: Skill System
Reason: enables repeatable high-quality operations.

Priority 4: Atlas Educacional MVP
Reason: first vertical product with clear value.
```

Asset types:

- knowledge, product, code, reputation, money, network;
- process, infrastructure, documentation, personal capability, brand.

Strategic Scoring criteria:

- strategic impact;
- effort;
- urgency;
- dependencies;
- risk;
- financial return;
- cognitive return;
- operational return;
- reuse potential;
- alignment with Atlas vision.

Spin-off examples from `Atlas Constitution`:

- public manifesto;
- internal documentation;
- Atlas AI laws system;
- article about cognitive autonomy;
- governance template.

Kill / Pause / Scale decisions:

- continue;
- pause;
- kill;
- split;
- merge;
- scale;
- transform into product;
- archive.

L4 gates:

- why should this Obra exist?
- which asset does it create?
- which greater objective does it serve?
- what is the opportunity cost?
- what does it depend on?
- what does it unlock?
- what risk does it introduce?
- how do we know it was worth it?

L4 is ready when Atlas maps the portfolio, prioritizes Obras, detects
dependencies and dispersion, suggests spin-offs and recommends pause/kill/scale.

## L5 - Atlas Sovereign OS

Goal: govern Vitor's autonomy ecosystem through Obras.

Foundry asks which Obras should exist. Sovereign OS asks which ecosystem must
exist so Vitor continuously creates the right Obras without damaging health,
relationships, integrity or stakeholders.

Components:

- Strategic Constitution;
- Operator Model;
- Autonomy Graph;
- Constraint System;
- Capital Stack;
- Life/Business Flywheel;
- Strategic Review.

Strategic Constitution contains:

- mission;
- principles;
- laws;
- constraints;
- anti-objectives;
- priorities;
- stakeholders;
- decision patterns.

Operator Model contains:

- energy;
- attention;
- skills;
- limits;
- strengths;
- weaknesses;
- routine;
- emotional state;
- execution capacity;
- dispersion patterns;
- high-performance patterns.

Autonomies:

- cognitive, financial, technical, operational;
- educational, emotional, relational, strategic.

Capital stack:

- financial, intellectual, technical, social, reputational;
- physical, emotional, operational.

Capital examples:

- Atlas AI Kernel increases technical, intellectual and operational capital.
- Personal brand increases reputational and social capital.
- Sellable product increases financial and operational capital.

Life/Business Flywheel:

```text
Study -> Obra -> Product -> Revenue -> Reinvestment -> Infrastructure
-> More capacity -> Better Obras -> More reputation -> More opportunities
```

Strategic Review questions:

- what did we build?
- what became an asset?
- what was waste?
- what increased autonomy?
- what reduced energy?
- what should be stopped?
- what should become product?
- what should become routine?
- what should become law?

L5 gates:

- no strategy may increase money while destroying health;
- no strategy may increase productivity while destroying primary relationships;
- no strategy may increase speed while reducing integrity;
- no strategy may create many Obras without focus;
- no strategy may depend on untested assumptions;
- no strategy may lack success metric or reversal plan.

L5 is ready when Atlas connects Obras to life strategy, increases autonomy,
protects human constraints, reviews portfolio/operator state and transforms
deliveries into assets.

## Final Level Definitions

- L0: "I have a place to register what I am building."
- L1: "Each Obra has context, structure, notes, sources and tasks."
- L2: "Each Obra has governance, versions, decisions, gates and evidence."
- L3: "Atlas conducts an Obra from intention to delivery."
- L4: "Atlas chooses, prioritizes and composes Obras as strategic assets."
- L5: "Atlas governs the ecosystem that turns Obras into autonomy, capital,
  reputation, knowledge and operational power."
