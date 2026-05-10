---
id: atlas-ai-self-construction-build-graph
type: engineering_knowledge
title: Atlas Self-Construction Build Graph
status: active
category: architecture
priority: 100
summary: Dependency graph for constructing Atlas in the right order.
tags:
  - atlas-ai
  - self-construction
  - build-graph
capabilities:
  - self_construction_os
  - dependency_governance
decisions:
  - Atlas must build high-leverage dependencies before surface features.
  - Self-programming depends on memory, retrieval, SDD, evidence, gates and drift detection.
maintenance:
  - Update before reprioritizing Atlas roadmap, runtime slices or self-programming work.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Build Graph

The build graph prevents Atlas from implementing impressive-looking features
before the foundations that make them reliable.

## Core Dependencies

```text
Documentation OS
-> Knowledge Governance
-> Evidence Ledger
-> Code Intelligence
-> Cognitive Runtime
-> Research Self-Improvement Runtime
-> Spec Operating System
-> Tool Runtime / Quality Gates
-> Self-Construction OS Runtime
-> Voice/Mobile/Product autonomy
-> Strategic self-programming
```

## Dependency Table

| Capability | Depends On | Why |
|---|---|---|
| Memory | Evidence, privacy, source truth | Avoid remembering wrong or unsafe context. |
| Retrieval | Knowledge DB, Code Intelligence, source ranking | Bring the right context instead of stuffing prompts. |
| Long sessions | Memory, compaction, handoff, drift metrics | Preserve quality over time. |
| Research runtime | Source registry, evidence lake, claim verification | Prevent hallucinated evolution. |
| SDD runtime | Context, business rules, receipts, gates | Turn intent into safe implementation. |
| Self-programming | SDD, Evidence, Drift, Tool Runtime, rollback | Let Atlas change itself safely. |
| Voice realtime | Kernel, surface adapter, runtime certification | Make voice a surface, not a parallel brain. |
| Mobile product | API/surface contracts, auth, realtime, UX gates | Make the product usable without bypassing core. |

## Build Order Law

When work competes, prefer the block that improves multiple downstream
capabilities.

Priority examples:

- memory/retrieval beats decorative UI;
- SDD runtime beats isolated feature coding;
- evidence/drift beats extra autonomy;
- research verification beats unverified implementation ideas;
- quality gates beat new provider wrappers.

## Blocking Rule

Do not promote a dependent capability if its prerequisite is below the required
maturity.

Example:

```text
Self-programming cannot exceed L5 while SDD runtime, Evidence Ledger, rollback
and drift detection are below L5.
```

## Build Graph Packet

Every construction spec must include:

```yaml
build_graph:
  target_capability:
  prerequisites:
  downstream_capabilities:
  blocked_by:
  unlocks:
  maturity_before:
  maturity_after:
```

## Drift Signal

If implementation creates a capability outside the build graph, emit drift:

```text
Capability exists without canonical dependency placement.
```

The correction is either to attach it to the graph or remove/defer it.
