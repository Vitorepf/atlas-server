---
id: graphify-atlas-harvest-map
type: engineering_knowledge
title: "Graphify Atlas Harvest Map"
description: "Mapa do que Atlas deve aproveitar do Graphify, como aproveitar, e o que rejeitar."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md"
  - "docs/ap/AP-684-graphify-external-graph-harness.md"
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/implementation-roadmap.md"
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/module-inventory.md"
---

# Graphify Atlas Harvest Map

## Purpose

This document answers the practical enterprise question:

```text
What exactly should Atlas take from Graphify, and how should it take it without
breaking Atlas governance?
```

The answer is selective harvest.

Atlas should not clone Graphify into runtime as a second brain. Atlas should
extract the best patterns and implement them as AP-owned, Ledger-aware,
policy-governed Code Intelligence capabilities.

## Highest-Value Harvests

### 1. Graph-First Context Compression

Graphify's strongest idea is that a graph can be a navigational map for an
agent. This reduces blind context loading.

Atlas implementation target:

- build a code/docs graph snapshot.
- allow agents to query relevant nodes/communities.
- pass small, source-linked context slices to Claude/Codex.
- record what graph slice was used as evidence.

Expected benefit:

- faster orientation.
- fewer duplicated explorations.
- lower token use.
- better one-shot implementation because the agent starts with topology.

### 2. Static Code Extraction Coverage

Graphify's language extractors are valuable research material.

Atlas implementation target:

- start with PHP/Laravel, Blade, TypeScript/JavaScript, Swift, Python, Markdown.
- create fixtures for each language before claiming coverage.
- emit Atlas-native entities: module, class, function, route, command, service,
  event, schema, doc link, AP link.

Expected benefit:

- cleaner understanding of the existing Atlas codebase.
- better architecture validation.
- safer parallel Codex work.

### 3. Bridge And Surprising Connection Analysis

Graphify's `analyze.py` is aligned with Atlas Curator and Constelacao.

Atlas implementation target:

- detect bridge nodes between architecture domains.
- detect orphaned docs or code entities.
- detect surprising but source-backed connections.
- convert findings into reviewable Curator proposals.

Expected benefit:

- better discovery.
- fewer forgotten architectural fragments.
- stronger semantic navigation without manual folders.

### 4. Suggested Questions

Graphify generates questions from ambiguous edges, weak connections, and low
cohesion communities.

Atlas implementation target:

- turn low-confidence graph gaps into Inbox/Curator review items.
- ask humans targeted questions only when the answer can improve memory/docs.
- avoid creating anxiety-style task spam.

Expected benefit:

- Atlas learns faster from high-leverage human clarification.
- the system asks fewer low-quality questions.

### 5. Agent Query Tools

Graphify's MCP server is a practical sketch of what agents need.

Atlas implementation target:

- `query_graph`.
- `get_node`.
- `get_neighbors`.
- `get_community`.
- `shortest_path`.
- `source_refs`.
- `explain_context_slice`.

Expected benefit:

- Claude/Codex can inspect Atlas topology without brute-force grep.
- agent answers become easier to audit.

### 6. Cache And Fingerprint Model

Graphify's SHA/content-cache pattern is important for cost and repeatability.

Atlas implementation target:

- corpus fingerprint.
- extractor version.
- provider/model version where used.
- deterministic cache key.
- Evidence Ledger event for cache reuse.

Expected benefit:

- lower extraction cost.
- replayable analysis.
- reduced drift between agents.

### 7. Token Benchmark Framing

Graphify frames graph context as token reduction.

Atlas implementation target:

- benchmark raw-read prompts vs graph-guided prompts.
- measure answer quality, context size, latency, cost, and correction rate.
- connect results to AP-99 Provider Performance Contract.

Expected benefit:

- objective proof that graph context improves agent work.
- provider selection can consider graph-aided performance.

## What Atlas Should Not Take Directly

### Do Not Import Direct Provider Calls

Graphify can call Claude and other LLMs directly.

Atlas must not let that become a production path. Provider calls belong to:

- Atlas Decide.
- Provider policy.
- AP-99.
- Decision Receipt v2.
- Evidence Ledger.
- Quality Gates.

### Do Not Adopt Uncontrolled Hooks

Graphify can install git hooks and watchers.

Atlas should avoid this in P0/P1. Hooks and watchers can create invisible state
changes. Any future watcher must be explicit, pausable, rate-limited, and
Ledger-visible.

### Do Not Commit Raw Graph Dumps By Default

Graphify encourages committing graph outputs for team use.

Atlas should instead keep derived graph artifacts under governed storage rules.
Raw graphs can contain private paths, inferred relations, and sensitive context.

### Do Not Treat Inferred Edges As Truth

Graphify labels confidence, but an agent can still overtrust an inferred edge.

Atlas must preserve:

- extracted vs inferred.
- confidence score.
- source refs.
- provider/model.
- human review state.

### Do Not Use Global Graph As Atlas Memory

Graphify has global graph utilities.

Atlas memory is richer and stricter: triage, promotion, contradiction, decay,
privacy, domain governance, and human review. A global graph is an artifact, not
the memory core.

## Final Harvest Rule

Copy ideas aggressively.

Copy files only with explicit license review, provenance, AP ownership, tests,
and a reason why reimplementation is worse.

In most cases, Atlas should reimplement the behavior in its own architecture
rather than paste upstream files.
