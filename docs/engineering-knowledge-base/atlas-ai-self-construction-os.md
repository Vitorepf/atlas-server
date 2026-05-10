---
id: atlas-ai-self-construction-os
type: engineering_knowledge
title: Atlas AI Self-Construction OS
status: active
category: architecture
priority: 100
summary: Canonical law for Atlas building Atlas through governed research, documentation, SDD, execution, evidence, repair and learning.
tags:
  - atlas-ai
  - self-construction
  - self-programming
  - governance
capabilities:
  - self_construction_os
  - meta_sdd
  - autonomous_implementation_loop
  - capability_maturity_ladder
decisions:
  - Atlas may become self-programming only through documentation-as-law, SDD, receipts, evidence and gates.
  - Self-construction is not vibe coding; it is governed evolution of the system that builds itself.
  - Any AI must be able to continue Atlas construction from canonical docs without relying on chat history.
  - The most powerful form is research -> docs -> spec -> implementation -> tests -> evidence -> learning.
maintenance:
  - Read before changing Atlas core, self-improvement, SDD runtime, memory, research automation, autonomous coding or governance.
  - Update when a new self-programming loop, maturity level, build dependency or core safety gate is promoted.
related_paths:
  - app/Console/Commands/AtlasAiSelfConstructionCommand.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
  - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
  - docs/engineering-knowledge-base/self-construction/constitution.md
  - docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md
  - docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md
  - docs/engineering-knowledge-base/self-construction/build-graph.md
  - docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md
  - docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md
  - docs/engineering-knowledge-base/self-construction/failure-modes.md
  - docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 260
---

# Atlas AI Self-Construction OS

Atlas Self-Construction OS is the law for Atlas building Atlas.

The goal is not "AI writes code". The goal is:

```text
Atlas detects the right gap
-> researches at source-backed quality
-> updates canonical docs
-> compiles SDD
-> implements small governed blocks
-> validates with gates
-> records evidence
-> detects drift
-> proposes learning
-> improves its future construction ability
```

This is how Atlas can become powerful without becoming chaotic.

## Hard Laws

- No self-programming without SDD.
- No SDD without context and source-of-truth docs.
- No Atlas core mutation without Decision Receipt.
- No result without evidence.
- No learning that changes critical behavior without proposal/review.
- No parallel architecture, memory, runtime, provider or daemon outside AP law.
- No implementation priority based on novelty, hype or surface beauty.
- No "complete" claim unless docs, code, tests, evidence and drift checks agree.

## What This Layer Adds

Spec Operating System teaches Atlas how to turn a request into implementation.
Self-Construction OS teaches Atlas how to evolve the system that performs that
implementation.

```text
SDD Core:
  user intent -> spec -> plan -> tasks -> receipt -> patch -> evidence

Self-Construction OS:
  system gap -> research -> docs -> meta-spec -> phased build -> validation
  -> drift -> learning -> maturity promotion
```

## The Highest Form

The most advanced Atlas is not merely self-coding. The highest form is governed
self-construction:

```text
research-backed
documentation-first
spec-driven
receipt-scoped
test-proven
evidence-led
drift-aware
learning-governed
priority-aligned
rollback-capable
```

Autoprogramming without this layer is dangerous. Autoprogramming with this
layer becomes compounding engineering power.

## Authority Map

| Area | Doc |
|---|---|
| Constitution | `self-construction/constitution.md` |
| Meta-SDD | `self-construction/meta-sdd-contract.md` |
| Maturity levels | `self-construction/capability-maturity-ladder.md` |
| Build dependencies | `self-construction/build-graph.md` |
| Priority engine | `self-construction/implementation-priority-engine.md` |
| Autonomous loop | `self-construction/autonomous-implementation-loop.md` |
| Safety contract | `self-construction/self-programming-safety-contract.md` |
| Quality bar | `self-construction/quality-bar-and-metrics.md` |
| Failure modes | `self-construction/failure-modes.md` |
| Builder persona | `self-construction/builder-persona-and-handoff.md` |
| Runtime roadmap | `self-construction/runtime-implementation-roadmap.md` |

## Core Loop

```text
1. Detect gap or opportunity.
2. Classify layer and risk.
3. Research source-backed state of the art.
4. Promote durable findings to docs.
5. Compile Meta-SDD spec.
6. Build plan and tasks.
7. Sign Decision Receipt.
8. Execute smallest safe block.
9. Run gates.
10. Append evidence.
11. Detect drift.
12. Propose learning.
13. Promote maturity only if metrics prove it.
```

## Maturity Target

Atlas is elite when a new AI session can ask:

```text
What is the most important next construction step?
```

and Atlas can answer with:

- current layer;
- missing capability;
- why it matters;
- dependencies;
- spec;
- safe task slice;
- allowed files;
- gates;
- rollback;
- evidence expected;
- residual risk.

## Integration With Existing Atlas

Self-Construction OS depends on:

- Documentation OS for canonical law;
- Knowledge Governance for source truth;
- Cognitive Runtime for memory, retrieval and long sessions;
- Research Self-Improvement Runtime for source-backed evolution;
- Spec Operating System for SDD and receipts;
- Kernel and Evidence Ledger for execution authority;
- Code Intelligence for repo awareness;
- Architecture Validate and docs-health for structural integrity.

## Completion Signal

This layer is complete as documentation when any capable AI can implement Atlas
construction work without relying on conversation memory. It is complete as
runtime only when Atlas can run the autonomous loop with scoped patches,
validated gates, evidence, drift detection and proposal-first learning.

## Current Runtime Surface

Read-only/advisory status is exposed by:

```bash
php artisan atlas:ai:self-construction --json
```

This command reports required docs, maturity, build graph, priority bias, safety
contract and next safe blocks. It does not authorize self-programming writes.

Meta-SDD candidate generation is exposed by:

```bash
php artisan atlas:ai:self-construction --meta-sdd --json
```

This generates a structured candidate packet with assumptions, priority, build
graph, tasks, gates and safety fields. It is read-only and does not execute the
candidate.

Decision Receipt preview is exposed by:

```bash
php artisan atlas:ai:self-construction --receipt-preview --json
```

This prepares a reviewable receipt envelope with allowed/forbidden actions,
files, commands, gates, rollback and evidence. It does not sign execution or
enable self-programming writes.

Self-Construction traceability audit is exposed by:

```bash
php artisan atlas:ai:self-construction --traceability --json
```

This verifies that required Self-Construction docs exist, declare the
self-construction authority, declare the canonical layer where applicable and
are reachable from this root document. It is read-only and does not promote
runtime maturity by itself.

Phase promotion gate is exposed by:

```bash
php artisan atlas:ai:self-construction --promotion-gate --json
```

This consolidates readiness, Meta-SDD, receipt preview and traceability into a
single promotion recommendation. It may mark Phase 5 as a candidate only for
human-reviewed planning. It does not sign execution, apply patches or allow
self-programming.
