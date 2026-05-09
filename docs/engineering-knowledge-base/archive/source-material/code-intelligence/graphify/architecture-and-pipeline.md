---
id: graphify-architecture-and-pipeline
type: engineering_knowledge
title: "Graphify Architecture And Pipeline"
description: "Como Graphify funciona, quais contratos produz, e por que isso importa para o Atlas."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md"
  - "docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md"
  - "docs/ap/AP-684-graphify-external-graph-harness.md"
---

# Graphify Architecture And Pipeline

## What Graphify Does

Graphify reads a workspace or knowledge corpus and builds a navigable graph of
files, entities, relationships, clusters, bridges, and questions.

Its practical promise is simple:

```text
Instead of asking an agent to read the repo blindly,
give the agent a graph map first.
```

That is valuable for Atlas because Atlas is designed around governed context,
domain routing, evidence, and learning. A graph map can help the agent reach the
right context faster while preserving the Atlas rule that surfaces and tools do
not decide.

## Pipeline Shape

Graphify documents its pipeline as:

```text
detect -> extract -> build_graph -> cluster -> analyze -> report -> export
```

Atlas translation:

```text
Surface/Adapter sees corpus
-> Context Builder may request code-intelligence graph material
-> Atlas Decide authorizes the harness
-> Runtime extracts graph evidence
-> Ledger records what was derived
-> Curator proposes improvements or gaps
-> Output Renderer shows graph/report/question slices
```

Graphify itself can run outside Atlas. In Atlas, the important rule is that its
behavior must be represented as governed code intelligence, not as a second
Kernel.

## Input Classes

Graphify handles a mixed corpus:

- source code.
- markdown and text docs.
- PDFs and papers.
- images.
- audio and video through transcription.
- spreadsheets and office documents.
- web/arxiv/tweet-like ingested material.

For Atlas, these should map to `Atlas Input` plus `Operation Envelope`, never to
untracked side effects. If a corpus contains private workspace data, the harness
must run under privacy policy and source allowlists.

## Extraction Passes

### Pass 1: Code Structure

Graphify uses Tree-sitter based extraction for many languages. It extracts
files, classes, functions, imports, calls, comments, and language-specific
relationships. This part is local-first and is the safest harvest target.

Atlas value:

- better Code Intelligence.
- better architectural maps.
- faster agent orientation.
- safer codebase questions before implementation.

Atlas caution:

- extraction output is evidence candidate material, not truth.
- static extraction can miss dynamic runtime behavior.
- source ownership and line refs must be preserved.

### Pass 2: Audio And Video

Graphify can transcribe video/audio locally using faster-whisper, with prompts
seeded from graph god nodes. This creates text that can be inserted into the
graph.

Atlas value:

- future Continuous Multimodal Context.
- meeting/video/audio knowledge projection.
- link voice artifacts to code/docs/domain objects.

Atlas caution:

- transcription can be wrong.
- audio/video can contain sensitive material.
- it must not auto-promote to memory without Memory Triage.

### Pass 3: Docs, Papers, Images

Graphify can use LLMs for semantic extraction from docs, PDFs, papers, and
images. It supports direct Claude and other backends.

Atlas value:

- useful pattern for extracting structured relationships from unstructured
  context.
- can power source-material maps for research.
- can feed Curator suggestions.

Atlas caution:

- this is provider traffic.
- output must be labeled by confidence and provenance.
- no provider call may bypass Atlas Decide, budget, privacy, or Evidence Ledger.

## Graph Model

Graphify writes `graph.json` in a node-link style format. The key objects are:

- nodes: files, entities, concepts, media, documents, or extracted objects.
- edges: relationships such as imports, calls, contains, references, semantic
  connections, and inferred links.
- hyperedges: multi-entity relationships when a simple pair is not enough.
- communities: clusters from graph topology.
- analysis metadata: god nodes, surprising connections, suggested questions.

Atlas should not blindly reuse the raw schema as a canonical schema. It should
map any harvested output to Atlas contracts:

- source refs.
- confidence.
- extraction method.
- provider/model when used.
- replayable event ids.
- policy and budget info.

## Clustering And Discovery

Graphify uses Leiden community detection when available, with fallbacks. It then
derives:

- high-centrality nodes.
- bridge nodes.
- surprising connections.
- weakly connected or isolated nodes.
- question prompts for human/agent follow-up.

This is extremely aligned with the Atlas `Constelacao` and `Open Brain` ideas:
it can reveal where separate knowledge islands are connected by structure.

But Graphify's graph is not the same as Atlas semantic memory. It is a harness
view over a corpus. Atlas memory still needs triage, promotion, decay, trust,
contradiction handling, and human-governed privacy.

## Reports And Exports

Graphify can produce:

- `GRAPH_REPORT.md`
- `graph.html`
- `graph.json`
- Obsidian/Wiki exports.
- GraphML.
- Neo4j export.
- SVG/tree HTML views.
- MCP query tools.

Atlas should treat exports as artifacts, not authority. The best Atlas harvest
is the graph/report/query pattern, not the uncontrolled habit of committing
large raw graph dumps into the product repo.

## Agent Query Layer

Graphify includes an MCP server with tools such as:

- query graph.
- get node.
- get neighbors.
- get community.
- god nodes.
- graph stats.
- shortest path.

This is a direct inspiration for an Atlas-native Code Intelligence surface. The
production version must be permissioned, source-aware, and Evidence Ledger aware.

## Caching

Graphify uses content-hash based caching. This is valuable because graph
extraction can be expensive.

Atlas should harvest this as:

- deterministic corpus fingerprint.
- extraction cache key.
- provider extraction cache key when LLMs are used.
- replayable cache provenance.

Cache hits should still be represented as evidence that a prior extraction was
reused.

## Command Surface

Graphify exposes extract, update, query, path, explain, watch, hook, export,
benchmark, ingest, and agent-integration commands.

Atlas must not import that full surface as-is. The safe enterprise shape is a
narrow AP-684 command set for scan, report, query, diff, and proposal, with all
names following existing Atlas CLI patterns.

## Why This Matters For Claude

Claude is powerful, but Claude without a map spends context discovering the
shape of the corpus. Graphify gives a map first:

```text
graph -> relevant nodes/edges/communities -> focused prompt -> better answer
```

For Atlas, that means Claude can become more efficient without becoming the
memory owner. Atlas owns the memory, policy, evidence, and final promotion.

## Final Atlas Interpretation

Graphify should be understood as a source-proven graph harness:

- great for context compression.
- great for architecture navigation.
- great for finding bridges.
- great for source-material digestion.
- not sufficient as the Atlas learning core by itself.

The correct implementation path is to harvest patterns into AP-684 and then use
Atlas Kernel governance to decide what becomes active.
