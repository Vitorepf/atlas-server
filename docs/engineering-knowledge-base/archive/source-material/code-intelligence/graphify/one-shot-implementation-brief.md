---
id: graphify-one-shot-implementation-brief
type: engineering_knowledge
title: "Graphify One Shot Implementation Brief"
description: "Brief one-shot para o Codex implementar AP-684 sem duplicar fluxo ou quebrar governanca."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md"
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/implementation-roadmap.md"
  - "docs/ap/AP-684-graphify-external-graph-harness.md"
---

# Graphify One Shot Implementation Brief

## Purpose

This is the handoff brief for the future Codex that implements AP-684.

Read this only after the source-material package and AP-684.

## First Implementation Target

Implement P1 only:

```text
Atlas-native external_graph_candidate.v1 schema and validator.
```

Do not implement Claude/provider extraction in the first pass.

## Allowed First Scope

The first slice may add:

- schema class/value object.
- validator service.
- fixture JSON.
- tests for valid candidate.
- tests for invalid confidence.
- tests for missing source refs.
- tests for denied/private paths.
- docs update if the implementation differs from AP-684.

## Forbidden First Scope

Do not add:

- direct Graphify dependency.
- provider calls.
- hooks.
- watchers.
- MCP server.
- global graph.
- memory writes.
- Context Builder injection.
- Constelacao positions.
- policy/provider routing.

## Candidate Validator Must Check

Required envelope:

- source tool and version.
- source commit or archive hash.
- corpus fingerprint.
- scan root.
- generated at.
- review state.

Required node data:

- id.
- label.
- kind/file type.
- source ref.

Required edge data:

- source node.
- target node.
- relation.
- confidence.
- source ref.

Required gates:

- allowlist path.
- denylist path.
- invalid confidence.
- oversize payload.
- provider trace without receipt.

## Test Names To Aim For

Use local test naming conventions, but cover:

- accepts valid external graph candidate.
- rejects edge without confidence.
- rejects invalid confidence label.
- rejects node without source ref.
- rejects path outside allowlist.
- rejects provider trace without decision receipt.
- treats inferred edge as not promotion eligible.

## Output Behavior

The first implementation should produce a validated candidate artifact only.

It must not:

- propose changes.
- mutate memory.
- call providers.
- render constellation.
- update policy.

## Review Hook

After P1, the next AP slice can read the candidate into Architecture Operations
as a report.

Only after that should Curator findings be added.

## Done Means

The slice is done only when:

- tests pass.
- docs-health passes.
- architecture-validate passes.
- git diff check passes.
- AP-684 status is updated if code exists.
- source material remains source material.

## Why This Is Strict

Graphify is powerful because it compresses context.

Atlas stays powerful because it controls promotion, evidence, policy, and
memory.

The first implementation must protect that boundary.

