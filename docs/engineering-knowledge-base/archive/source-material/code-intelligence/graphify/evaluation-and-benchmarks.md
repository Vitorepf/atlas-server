---
id: graphify-evaluation-and-benchmarks
type: engineering_knowledge
title: "Graphify Evaluation And Benchmarks"
description: "Como medir se Graphify realmente melhora Claude, Codex e Atlas antes de promover a colheita."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md"
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/claude-atlas-operating-model.md"
  - "docs/ap/AP-684-graphify-external-graph-harness.md"
---

# Graphify Evaluation And Benchmarks

## Purpose

Graphify claims value through token reduction and better graph navigation.

Atlas must prove that value inside Atlas before treating it as core.

## Upstream Benchmark Shape

Graphify's benchmark estimates:

- full corpus tokens.
- graph query subgraph tokens.
- average query token cost.
- reduction ratio.
- per-question reductions.
- graph node and edge counts.

Default sample questions include architecture-style questions such as entry
point, auth flow, error handling, data-to-api links, and core abstractions.

Atlas should treat this as a useful measurement idea, not final proof.

## Atlas Benchmark Dimensions

Atlas must measure at least:

- context tokens.
- provider input/output tokens.
- latency.
- cost.
- answer correctness.
- source citation quality.
- number of files touched.
- test pass rate.
- rework/correction rate.
- hallucinated architecture claims.

The goal is not only cheaper prompts. The goal is better work.

## Claude Evaluation Protocol

For any AP-684 Claude-aided extraction or coding flow, compare:

| Variant | Description |
| --- | --- |
| raw-read | Claude/Codex explores with normal repo search. |
| graph-guided | Claude/Codex receives graph slice first. |
| graph-plus-docs | graph slice plus canonical AP/docs. |

The same task should be run against all variants when practical.

Required evidence:

- prompt/context size.
- graph snapshot id.
- source refs supplied.
- model/provider.
- answer or patch quality.
- validation commands.
- human correction notes.

## Success Thresholds

AP-684 should not claim production benefit unless graph-guided work shows:

- lower average context size.
- equal or better correctness.
- fewer wrong-file edits.
- fewer duplicate docs/code paths.
- better source citations.
- no privacy or provider bypass.

Token reduction alone is not enough.

## Test Surface From Upstream

Graphify upstream has tests for:

- analysis.
- benchmark.
- build.
- cache.
- chunking.
- CLI export.
- clustering.
- confidence.
- dedup.
- detection.
- extraction.
- global graph.
- Google workspace.
- hooks.
- hypergraph.
- import resolution.
- incremental update.
- ingest.
- language coverage.
- LLM backends.
- MCP serving.
- Ollama.
- pipeline.
- query CLI.
- rationale.
- report.
- security.
- semantic similarity.
- transcription.
- validation.
- watch.
- wiki.

Atlas should not mirror all tests immediately. It should harvest the categories
to ensure no capability is adopted without an equivalent guard.

## AP-99 Integration

Provider performance evaluation should include a graph-assisted dimension:

```text
provider + model + graph_context_strategy -> quality/cost/latency/correction
```

This lets Atlas learn not only which provider is best, but which provider is
best when Atlas gives it structured graph context.

## Failure Modes To Measure

Track these explicitly:

- graph slice omitted a required source.
- inferred edge misled the provider.
- source refs were stale.
- graph query returned too much context.
- graph query returned no context.
- provider overtrusted an `AMBIGUOUS` edge.
- report hid privacy-sensitive paths.
- benchmark improved tokens but worsened correctness.

## Final Rule

Graphify becomes core only when measured improvement is replayable.

The standard is:

```text
faster + cheaper + more correct + more auditable + governed
```

Anything less stays source material or scaffold.

