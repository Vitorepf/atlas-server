---
id: graphify-claude-atlas-operating-model
type: engineering_knowledge
title: "Graphify Claude Atlas Operating Model"
description: "Como Graphify deve melhorar o uso do Claude no Atlas sem virar memoria paralela ou fluxo paralelo."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/architecture-and-pipeline.md"
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/atlas-harvest-map.md"
  - "docs/ap/AP-684-graphify-external-graph-harness.md"
---

# Graphify Claude Atlas Operating Model

## Purpose

This document defines the correct relationship between:

- Atlas as the governed intelligence system.
- Claude as a powerful provider/runtime.
- Graphify as graph-based context compression.

The important point:

```text
Graphify can make Claude much better at using Atlas context.
Graphify must not make Claude the owner of Atlas memory.
```

## The Correct Mental Model

Claude should not receive the whole repo blindly when a graph can identify the
right slice first.

The ideal loop is:

```text
Atlas corpus snapshot
-> graph extraction
-> graph analysis
-> small source-linked context slice
-> Claude reasoning
-> Atlas receipt/evidence
-> Curator or human review
-> optional promotion to memory/docs/code
```

This creates a compounding effect:

- Atlas gives Claude better context.
- Claude produces better analysis or code.
- Atlas stores only the useful, governed result.
- future Claude calls receive cleaner context.

## What Graphify Improves For Claude

### Orientation

Claude can start from:

- important nodes.
- communities.
- bridge nodes.
- shortest paths.
- relevant docs.
- relevant code symbols.

This reduces the expensive phase where an agent explores blindly.

### Prompt Precision

Instead of:

```text
Read the codebase and figure out X.
```

Atlas can provide:

```text
Use these 12 nodes, these 4 source refs, this AP, these tests, and this
graph-detected dependency path.
```

That is more professional, cheaper, and easier to audit.

### Cross-Domain Discovery

Graphify-style bridge detection can help Claude see relationships that normal
folder structure hides:

- docs to code.
- APs to tests.
- provider policy to runtime behavior.
- source-material research to implementation gaps.

This is aligned with the Atlas "Constelacao" goal, but in production it must be
explainable through source refs.

### Question Generation

Claude can use graph gaps to ask better questions:

- "This doc claims X, but no code symbol supports it."
- "This service has many references but no AP owner."
- "This edge is inferred and needs confirmation."

These should become Curator/Human Review items, not raw chat noise.

## What Claude Must Not Do

### Claude Must Not Decide Provider Policy

Even if Graphify's `llm.py` can call Claude directly, Atlas production must not.

Claude usage must go through:

- Atlas Decide.
- provider policy.
- budget policy.
- privacy policy.
- Decision Receipt v2.
- Evidence Ledger.

### Claude Must Not Promote Memory Alone

Claude may propose:

- memory candidate.
- doc correction.
- graph edge.
- code-intelligence finding.
- source-material summary.

Claude must not silently promote those into Atlas memory.

### Claude Must Not Hide Source Scope

Every Claude response that used graph context must preserve:

- graph snapshot id.
- source refs.
- extraction method.
- provider/model.
- token/cost metadata where available.
- confidence labels.

### Claude Must Not Replace Human Knowledge Surface

The Human Knowledge Surface is curated context and review. It is not raw
provider memory.

If Claude has its own memory or managed-agent features in the future, that can
improve Claude's efficiency, but Atlas still owns:

- canonical memory.
- operational truth.
- policy.
- audit.
- privacy.
- review.

## Recommended Claude Session Protocol

When using Claude/Codex against Atlas after AP-684 matures, the session should
begin with a graph orientation step:

1. Load canonical docs for the target AP or subsystem.
2. Query the graph for relevant nodes and communities.
3. Pull the smallest source-linked context slice that can answer the task.
4. Ask Claude to reason within that slice and list uncertainty.
5. Convert output into code/docs/tests/proposals under Atlas governance.
6. Record the graph slice and source refs in Evidence Ledger when runtime is
   involved.

## Prompt Pattern

Atlas should generate prompts shaped like:

```text
You are working inside Atlas AP-684.

Use this graph context:
- graph_snapshot_id: ...
- corpus_fingerprint: ...
- relevant_nodes: ...
- relevant_edges: ...
- source_refs: ...
- confidence_notes: ...

Rules:
- do not assume inferred edges are true.
- cite source refs for architecture claims.
- propose changes only inside allowed files.
- if the graph is insufficient, ask for a graph query or source read.
```

This is how Graphify becomes useful without becoming a rogue runtime.

## Claude Code / Codex Benefit

For coding agents, the benefit is huge:

- fewer wrong files touched.
- better understanding of AP boundaries.
- less duplicate implementation.
- easier handoff between parallel agents.
- stronger "pronto vs nao pronto" audits.
- faster review of whether docs match code.

This directly supports the user's intended Atlas operating style: multiple
agents can help without stepping on the main Codex because the graph gives them
bounded context and ownership.

## Relation To Dreams Or Provider Learning

If Claude exposes managed-agent reflection or "dream" style features, Atlas can
use them as provider-side optimization. That is a separate layer.

The correct stack is:

```text
Provider-side learning improves Claude behavior.
Atlas memory improves system behavior.
Graph context improves each task's prompt.
Evidence Ledger preserves audit.
Curator decides what should improve next.
```

These layers compound, but they are not the same memory.

## Enterprise Guardrail

Every Graphify-assisted Claude flow must answer:

- What corpus was used?
- What graph snapshot was used?
- What source refs support the answer?
- Which edges were extracted vs inferred?
- Which provider/model reasoned over the slice?
- Was any provider call authorized?
- Did anything get promoted to memory, docs, code, or policy?

If those cannot be answered, the flow is not Atlas-grade.
