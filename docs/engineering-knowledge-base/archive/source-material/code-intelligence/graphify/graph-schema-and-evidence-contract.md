---
id: graphify-graph-schema-and-evidence-contract
type: engineering_knowledge
title: "Graphify Graph Schema And Evidence Contract"
description: "Contrato de schema e evidencia para traduzir Graphify em external_graph_candidate.v1."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md"
  - "docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md"
  - "docs/ap/AP-684-graphify-external-graph-harness.md"
---

# Graphify Graph Schema And Evidence Contract

## Purpose

This document turns Graphify's practical graph shape into an Atlas evidence
contract.

The goal is to keep the useful topology while preventing raw external graph
output from becoming Atlas truth.

## Upstream Extraction Shape

Graphify validates extraction JSON with three major collections:

- `nodes`.
- `edges`.
- optional `hyperedges`.

It also tracks token counters for semantic extraction:

- `input_tokens`.
- `output_tokens`.

Graphify accepts both `edges` and legacy NetworkX `links` in some paths.

## Upstream Node Fields

Required by upstream validator:

- `id`.
- `label`.
- `file_type`.
- `source_file`.

Valid `file_type` values:

- `code`.
- `document`.
- `paper`.
- `image`.
- `rationale`.
- `concept`.

Useful optional fields observed in skills/runtime:

- `source_location`.
- `source_url`.
- `captured_at`.
- `author`.
- `contributor`.
- `community`.
- `norm_label`.

## Upstream Edge Fields

Required by upstream validator:

- `source`.
- `target`.
- `relation`.
- `confidence`.
- `source_file`.

Valid confidence values:

- `EXTRACTED`.
- `INFERRED`.
- `AMBIGUOUS`.

Useful optional fields:

- `confidence_score`.
- `source_location`.
- `weight`.
- `context`.
- internal `_src` and `_tgt` after build.

## Upstream Hyperedge Fields

Graphify skills describe hyperedges with:

- `id`.
- `label`.
- `nodes`.
- `relation`.
- `confidence`.
- `confidence_score`.
- `source_file`.

Atlas should support hyperedges only after pairwise node/edge import is stable.

## Atlas Candidate Envelope

Atlas should not import raw Graphify JSON as canonical data. It should wrap it:

```text
external_graph_candidate.v1
  source_tool
  source_tool_version
  source_commit
  corpus_fingerprint
  scan_root
  allowed_paths
  denied_paths
  generated_at
  extraction_mode
  provider_trace optional
  nodes
  edges
  hyperedges optional
  analysis optional
  artifacts optional
  review_state
```

## Atlas Node Mapping

Each candidate node must become:

- `node_id`.
- `label`.
- `kind`.
- `source_ref`.
- `source_location`.
- `privacy_class`.
- `community_hint`.
- `source_tool_payload`.

`source_ref` is mandatory for promotion. A node without source provenance is
only an opaque artifact and cannot guide implementation.

## Atlas Edge Mapping

Each candidate edge must become:

- `edge_id`.
- `source_node_id`.
- `target_node_id`.
- `relation`.
- `confidence_label`.
- `confidence_score`.
- `source_ref`.
- `evidence_class`.
- `review_state`.
- `promotion_allowed`.

Atlas mapping:

| Graphify | Atlas |
| --- | --- |
| `EXTRACTED` | structural evidence candidate |
| `INFERRED` | reviewable proposal only |
| `AMBIGUOUS` | human-review question only |
| missing confidence | reject or coerce to `AMBIGUOUS` with warning |

## Provider Trace

If Claude or another model was used, Atlas must attach:

- provider.
- model.
- decision_receipt_id.
- input token estimate.
- output token estimate.
- source scope.
- extraction prompt version.
- output validator result.

Without this, semantic extraction is not Atlas-grade.

## Quality Gates

The importer must reject:

- missing `nodes` or invalid node list.
- missing `edges` or invalid edge list.
- node without `id`, `label`, `file_type`, or `source_file`.
- edge without `source`, `target`, `relation`, `confidence`, or `source_file`.
- invalid confidence.
- dangling internal edge unless explicitly external.
- path outside allowlist.
- source under denylist.
- oversize payload.
- provider trace without receipt when provider was used.

## Evidence Principle

Graphify graph output is evidence candidate material.

It can help Claude reason, but it cannot:

- write memory.
- update policy.
- route providers.
- create constellation truth.
- bypass tests.
- bypass human review for inferred facts.

## Why This Is Core

This schema bridge is the difference between:

- Graphify as a powerful Atlas accelerator.
- Graphify as uncontrolled context pollution.

The graph becomes powerful only when every edge can answer: where did this come
from, how confident is it, who reasoned over it, and what is it allowed to do?

