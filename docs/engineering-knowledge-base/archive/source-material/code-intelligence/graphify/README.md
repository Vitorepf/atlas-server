---
id: graphify-enterprise-dissection-index
type: engineering_knowledge
title: "Graphify Source Material: Enterprise Dissection Index"
description: "Indice governado da disseccao enterprise do Graphify para AP-684 External Graph Harness."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md"
  - "docs/ap/AP-684-graphify-external-graph-harness.md"
  - "dissecar/graphify/README.md"
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/graph-schema-and-evidence-contract.md"
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/one-shot-implementation-brief.md"
---

# Graphify Source Material: Enterprise Dissection Index

This package is the governed Atlas dissection of Graphify.

It exists so Atlas can harvest the best ideas from Graphify without copying an
external runtime into the Kernel by accident, without creating a parallel memory
system, and without letting provider-specific behavior bypass Atlas Decide,
Decision Receipt v2, Evidence Ledger, Quality Gates, or Human Review.

## Source Pin

Audited upstream:

- Repository: `https://github.com/safishamsi/graphify`
- Package name: `graphifyy`
- Audited version/tag: `v0.7.11`
- Audited commit: `adf96da28379b17daf6481294ef7f6e0359481b2`
- Remote HEAD verified on: `2026-05-09`
- Atlas lab copy: `dissecar/graphify/upstream`
- Atlas lab archive: `dissecar/graphify/upstream-archive`

Important: the Atlas authority is not the upstream README. The Atlas authority is
AP-684 plus the active code-intelligence contract. This source package is
evidence and design material.

## One-Sentence Model

Graphify turns a mixed corpus of code, docs, PDFs, images, audio, and video into
a graph that agents can query before spending tokens reading the whole corpus.

For Atlas, the most valuable idea is not the UI or CLI. The valuable idea is the
context-compression layer:

```text
raw corpus -> extracted entities/relations -> graph -> clusters/bridges/questions
-> agent-ready context slices -> governed Atlas proposal/evidence
```

## Read Order

1. [Architecture And Pipeline](architecture-and-pipeline.md)
2. [Module Inventory](module-inventory.md)
3. [Atlas Harvest Map](atlas-harvest-map.md)
4. [Graph Schema And Evidence Contract](graph-schema-and-evidence-contract.md)
5. [Capability Taxonomy](capability-taxonomy.md)
6. [Implementation Roadmap](implementation-roadmap.md)
7. [Claude Atlas Operating Model](claude-atlas-operating-model.md)
8. [Evaluation And Benchmarks](evaluation-and-benchmarks.md)
9. [Risk Governance And DoD](risk-governance-and-dod.md)
10. [One Shot Implementation Brief](one-shot-implementation-brief.md)

## Atlas Stance

Graphify is useful to Atlas in four ways:

- It shows a practical graph-first workflow for agent context.
- It provides concrete extractors, reports, exports, MCP query tools, and cache
  patterns that can inspire Atlas-native implementation.
- It gives a good operating model for reducing Claude/Codex context waste.
- It gives a schema/evidence bridge for turning graph output into reviewed
  Atlas candidates.
- It exposes risks Atlas must govern before any production use.

Graphify is not, by itself:

- Atlas memory.
- Atlas Graph RAG.
- Atlas Decide.
- A trusted provider.
- A runtime allowed to change policy.
- A replacement for Evidence Ledger.

## Enterprise Rule

Any Graphify harvest must be converted into an Atlas-native slice with:

- AP ownership.
- Canonical docs.
- schemas/events where relevant.
- Evidence Ledger replay compatibility.
- Quality Gate checks.
- tests.
- no hidden side effects.

No direct `graphify` command, git hook, MCP server, LLM call, or graph export may
become a production dependency unless Atlas explicitly wraps it behind Kernel
policy and a Decision Receipt.
