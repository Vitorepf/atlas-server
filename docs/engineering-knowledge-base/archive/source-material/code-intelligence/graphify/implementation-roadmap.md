---
id: graphify-implementation-roadmap
type: engineering_knowledge
title: "Graphify Implementation Roadmap"
description: "Ordem segura de implementacao da colheita Graphify dentro do Atlas."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md"
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/atlas-harvest-map.md"
  - "docs/ap/AP-684-graphify-external-graph-harness.md"
---

# Graphify Implementation Roadmap

## Purpose

This roadmap defines the safe order for converting Graphify source material into
Atlas-native capability.

The goal is compounding intelligence without architecture debt.

## P0: Source Authority

Status: active.

Outcome:

- Graphify source pinned.
- dissection documented.
- AP-684 tied to Code Intelligence.
- lab isolated under `dissecar`.

This prevents random copy/paste.

## P1: Graph Snapshot Contract

Implement:

- graph snapshot schema.
- corpus fingerprint.
- source refs.
- extractor version.
- confidence labels.
- no LLM requirement.

DoD:

- deterministic graph over a small fixture.
- no provider calls.
- docs-health and architecture validate pass.

## P2: Query Surface

Implement:

- graph query service.
- neighbor/community/path queries.
- AP/doc/code source refs.
- deterministic tests.

DoD:

- an agent can request a small context slice.
- every slice includes provenance.

## P3: Curator Findings

Implement:

- orphaned docs/code findings.
- ambiguous edge findings.
- bridge/surprising connection findings.
- human-review proposal shape.

DoD:

- no auto-mutation.
- findings are deduped and reviewable.

## P4: Claude-Aided Semantic Extraction

Implement only after P1-P3:

- provider-gated semantic extraction.
- direct Claude disabled outside Atlas provider path.
- receipt records source set, model, token budget, and output schema.
- quality gates reject malformed extraction.

DoD:

- no provider call without Decision Receipt.
- extracted facts are evidence candidates, not automatic memory.

## P5: Constelacao And Open Brain Bridges

Implement:

- graph clusters to constellation positions.
- bridge nodes as cross-domain sparks.
- lineage from graph refs to memory/capture/proposal/result.

DoD:

- visual relation never pretends to be truth.
- user can inspect why two stars are near.

## Domain Priority

Programming comes first because Graphify helps map services, commands, docs,
APs, tests, routes, providers, and architecture boundaries.

Research/Learning comes second after source policy exists.

Marketing can use graph lineage for campaigns and creative systems, but weak
semantic inference must stay review-only.

Finance requires stricter source/date/provider gates.

Personal Development and Health require privacy gates and human confirmation.

## Quality Compounding

Graphify improves Atlas by adding a middle layer:

```text
raw data -> graph map -> targeted context -> better agent output
```

Better agent output can improve implementation quality, documentation quality,
memory candidates, provider measurements, and self-improvement findings.

The compounding effect exists only if Atlas owns the gates. Without gates,
Graphify can amplify noise.

