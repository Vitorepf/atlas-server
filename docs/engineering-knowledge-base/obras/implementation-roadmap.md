---
id: atlas-ai-obras-implementation-roadmap
type: engineering_knowledge
title: Atlas Obras - Implementation Roadmap
status: active
category: architecture
priority: 100
summary: Recommended implementation order for Obras from foundation to Sovereign OS.
tags:
  - atlas-ai
  - obras
  - roadmap
capabilities:
  - obras_operating_system
  - implementation_roadmap
decisions:
  - Implement in phases, but document the final state first.
  - L0-L2 must be solid before ObraOS execution.
  - L5 must not be implemented before the system can produce and govern real assets.
maintenance:
  - Update before changing the implementation order or promoting an Obras runtime phase.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 220
---

# Atlas Obras - Implementation Roadmap

## Principle

The final architecture must be documented before implementation, but runtime
must be built in layers.

```text
Do not shrink the vision.
Do not implement L5 before L0-L2 are real.
```

## Phase A - Foundation

Build:

- Obra entity;
- status;
- type;
- domain;
- objective;
- next step;
- basic structure.

Goal:

```text
Make Obras exist as a first-class Atlas entity.
```

## Phase B - Workspace Vivo

Add:

- notes;
- tasks;
- sources;
- AI sessions;
- Obra summary;
- hierarchical structure.

Goal:

```text
Make Obra carry context.
```

## Phase C - Enterprise Core

Add:

- decisions;
- versions;
- Evidence Ledger;
- Quality Gates;
- feedbacks;
- outputs.

Goal:

```text
Make Obra reliable and auditable.
```

## Phase D - ObraOS

Add:

- Intent Parser;
- Spec Driver;
- Planner;
- Executor;
- Reviewer;
- Repair Loop;
- Human Checkpoints;
- Output Renderer.

Goal:

```text
Make Atlas conduct an Obra to delivery.
```

## Phase E - Foundry

Add:

- Portfolio Graph;
- prioritization;
- dependencies;
- Asset Theory;
- spin-offs;
- kill/pause/scale decisions.

Goal:

```text
Make Obras become composed strategy.
```

## Phase F - Sovereign OS

Add:

- Operator Model;
- Autonomy Graph;
- Capital Stack;
- Constraint System;
- Strategic Review;
- Life/Business Flywheel.

Goal:

```text
Make Obras serve Vitor's complete autonomy.
```

## MVP Warning

The MVP may be small:

- create Obra;
- define objective;
- define type/domain/status;
- define next step;
- define structure;
- attach notes/tasks/sources.

But the data model must already leave room for:

- graph relationships;
- Evidence Ledger;
- decisions;
- gates;
- outputs;
- assets;
- portfolio;
- autonomy strategy.

The MVP may not:

- use a TCC-only model;
- store the Obra only as Markdown;
- omit next step;
- omit structure nodes;
- create AI sessions without `obra_id`;
- claim completion without output or explicit closure.

## First Pilot Recommendation

The first serious pilot should be:

```text
Obra: Atlas Self-Construction OS
```

Why:

- it already has docs, APs, tests, gates and outputs;
- it is complex enough to prove value;
- it directly improves Atlas construction;
- it avoids narrowing Obras to TCC.
