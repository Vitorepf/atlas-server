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

All commands below are read-only and keep `execution_allowed=false`.

| Command | Purpose |
|---|---|
| `php artisan atlas:ai:self-construction --json` | Readiness, docs, maturity, build graph, priority bias and safety contract. |
| `php artisan atlas:ai:self-construction --meta-sdd --json` | Candidate Meta-SDD packet with assumptions, priority, tasks and gates. |
| `php artisan atlas:ai:self-construction --receipt-preview --json` | Preview receipt with allowed/forbidden scope, rollback and evidence. |
| `php artisan atlas:ai:self-construction --traceability --json` | Required-doc reachability, tag and layer audit. |
| `php artisan atlas:ai:self-construction --promotion-gate --json` | Consolidated promotion recommendation for human-reviewed planning. |
| `php artisan atlas:ai:self-construction --execution-candidate --json` | Deterministic Phase 5 candidate for docs/tests/report scope only. |
| `php artisan atlas:ai:self-construction --approval-packet --json` | Human review packet with checklist, reviewers and decision fields. |
| `php artisan atlas:ai:self-construction --receipt-draft --json` | Unsigned receipt draft with hash and preview signature. |
| `php artisan atlas:ai:self-construction --execution-preflight --json` | Expected blocked preflight while no valid human signature exists. |
| `php artisan atlas:ai:self-construction --signature-request --json` | Signable payload, hashes, signer roles and confirmations. |
| `php artisan atlas:ai:self-construction --execution-runbook --json` | Post-signature ordered steps, stop conditions, evidence, gates and rollback. |
| `php artisan atlas:ai:self-construction --evidence-packet --json` | Required proof template, claim checks and failure policy for a future signed run. |
| `php artisan atlas:ai:self-construction --completion-readiness --json` | Blocks false completion until signed execution evidence exists. |
| `php artisan atlas:ai:self-construction --residual-risk --json` | Classifies residual blockers before promotion or completion claims. |
| `php artisan atlas:ai:self-construction --handoff-packet --json` | Gives the next operator hashes, blockers, commands and forbidden hot scope. |
| `php artisan atlas:ai:self-construction --next-action --json` | Selects the next safe action while execution remains blocked. |
| `php artisan atlas:ai:self-construction --surface-matrix --json` | Lists every command surface, schema and read-only invariant. |
| `php artisan atlas:ai:self-construction --external-blockers --json` | Reports hot-file blockers outside Self-Construction ownership. |
| `php artisan atlas:ai:self-construction --cold-lane-certification --json` | Certifies the Self-Construction cold lane with external blockers separated. |
| `php artisan atlas:ai:self-construction --operator-checklist --json` | Orders the next human/operator review steps without signing or execution. |
| `php artisan atlas:ai:self-construction --promotion-blockers --json` | Consolidates promotion and completion blockers without execution. |
| `php artisan atlas:ai:self-construction --readiness-digest --json` | Emits a compact hashable handoff digest for operators and other AIs. |
| `php artisan atlas:ai:self-construction --governance-scorecard --json` | Scores governed readiness while execution, promotion and completion stay blocked. |
| `php artisan atlas:ai:self-construction --integrity-manifest --json` | Bundles governed packet hashes for audit and handoff integrity checks. |
| `php artisan atlas:ai:self-construction --continuation-token --json` | Emits a compact audited resume token with must-run and must-not-touch constraints. |
| `php artisan atlas:ai:self-construction --ownership-boundary --json` | Declares cold allowed files, hot forbidden scopes and required operator behavior. |
| `php artisan atlas:ai:self-construction --phase-ledger --json` | Summarizes phase status, hard blocks and promotion boundaries. |

None of these surfaces signs, patches, approves, persists approval, mutates
policy, touches hot runtime files or enables autonomous self-programming.
