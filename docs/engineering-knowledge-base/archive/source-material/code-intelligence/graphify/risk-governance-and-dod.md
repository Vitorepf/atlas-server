---
id: graphify-risk-governance-and-dod
type: engineering_knowledge
title: "Graphify Risk Governance And DoD"
description: "Riscos, mitigacoes e Definition of Done para qualquer colheita do Graphify no Atlas."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/atlas-harvest-map.md"
  - "docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md"
  - "docs/ap/AP-684-graphify-external-graph-harness.md"
---

# Graphify Risk Governance And DoD

## Purpose

Graphify is useful enough that Atlas should study it seriously.

It is also broad enough that importing it carelessly would violate Atlas'
architecture principles. This document defines the guardrails.

## Primary Risks

### 1. Parallel Runtime Risk

Graphify has its own CLI, MCP server, hooks, watchers, exports, provider calls,
global graph, and cache.

Risk:

- Atlas accidentally gains a second operational system beside the Kernel.

Mitigation:

- no production use of Graphify commands unless wrapped by Atlas.
- no hooks/watchers in P0.
- no direct MCP server exposure.
- every feature becomes an AP-owned Atlas service or command.

### 2. Provider Bypass Risk

Graphify can call Claude and other LLMs directly.

Risk:

- provider usage bypasses Atlas Decide, AP-99, budgets, privacy, receipts, and
  Evidence Ledger.

Mitigation:

- direct provider calls are forbidden in Atlas production.
- semantic extraction must use Atlas provider drivers.
- every provider call needs Decision Receipt v2.
- provider/model/token/cost metadata must be captured.

### 3. Memory Pollution Risk

Graphify can infer semantic edges from weak or noisy context.

Risk:

- inferred edges become treated as real memory.

Mitigation:

- preserve extracted/inferred/ambiguous labels.
- require source refs.
- route uncertain edges through Curator/Human Review.
- never auto-promote graph output into long-term memory.

### 4. Privacy And Source Leakage Risk

Graphs can reveal file paths, private docs, notes, secrets, and internal
relationships.

Risk:

- raw graph exports leak sensitive context.

Mitigation:

- source allowlists.
- secret scanning before extraction/export.
- private path redaction where needed.
- no default commit of graph dumps.
- explicit export commands only.

### 5. False Authority Risk

A graph looks authoritative even when extraction is incomplete.

Risk:

- agents overtrust the graph and skip source verification.

Mitigation:

- graph answers must include confidence and source refs.
- docs must say graph context is an orientation layer.
- code changes still need tests and architecture validation.

### 6. Performance Risk

Large repos, PDFs, media, and semantic extraction can be expensive.

Risk:

- slow scans, high provider cost, or large artifacts.

Mitigation:

- incremental fingerprints.
- bounded concurrency.
- cache reuse.
- max corpus size.
- per-domain budgets.
- benchmark before enabling broad scans.

### 7. License And Provenance Risk

Graphify is open source, but copying code still requires license discipline.

Risk:

- untracked upstream code enters Atlas.

Mitigation:

- preserve upstream provenance.
- review license before file-level copy.
- prefer reimplementation.
- document copied snippets explicitly if any are ever used.

## Security Checklist

Any AP-684 implementation must verify:

- no `shell=True` or equivalent unsafe execution.
- no source code execution during extraction.
- no symlink traversal.
- path traversal guarded.
- URL fetch guarded against SSRF.
- max file size and total corpus size enforced.
- secrets excluded.
- binary/media handling explicit.
- HTML/Markdown output sanitized.
- provider calls disabled unless policy allows them.

## Data Governance Checklist

Graph evidence must carry:

- graph snapshot id.
- corpus fingerprint.
- extraction version.
- source path or source id.
- source line/range when available.
- relation type.
- confidence.
- extracted/inferred/ambiguous classification.
- provider/model only when used.
- created_at.
- event_id when emitted to ledger.

## Definition Of Done For P0/P1

For a minimal Atlas-native graph snapshot:

- docs describe the scope.
- schema is stable.
- fixture corpus exists.
- extraction is deterministic.
- no provider call is required.
- output has source refs.
- output has confidence labels.
- tests cover at least one code language and one doc source.
- docs-health passes.
- architecture validate passes.
- no raw graph dump is committed as authority.

## Definition Of Done For Claude-Aided Extraction

For semantic extraction using Claude:

- provider path goes through Atlas Decide.
- Decision Receipt v2 records provider/model/budget/source scope.
- output schema is validated.
- failed/truncated chunks are explicit.
- no direct upstream `llm.py` call is used.
- extraction results are evidence candidates.
- Curator/Human Review controls promotion.
- AP-99 metrics can compare provider quality and cost.

## Definition Of Done For Agent Query Tools

For agent-facing graph tools:

- auth and policy gates exist.
- query result is bounded by token/source budget.
- every node/edge has source refs.
- inferred edges are visually/textually marked.
- tool response can be cited by a receipt.
- tool cannot mutate memory or policy.

## Definition Of Done For Constelacao Use

For visual constellation use:

- positions are generated from governed graph/memory signals.
- source reasons are inspectable.
- isolated/low-confidence points are marked as such.
- manual drag does not become semantic truth.
- no task/due-date pressure is introduced.
- lineage can show capture/source/proposal/result.

## Review Cadence

Graphify harvest should be reviewed whenever:

- upstream Graphify changes version.
- Atlas changes provider policy.
- AP-99 changes provider performance contracts.
- Memory Triage changes promotion rules.
- Code Intelligence graph schema changes.

## Enterprise Conclusion

Graphify can make Atlas stronger only if it remains subordinate to Atlas
governance.

The winning pattern is:

```text
Graphify ideas -> Atlas-native AP -> tests -> evidence -> curator -> human
review -> controlled rollout
```

Anything else risks turning useful source material into architectural debt.
