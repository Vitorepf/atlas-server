---
id: atlas-tool-runtime-specs-index
type: engineering_knowledge
title: Atlas Tool Runtime Specs Index
status: active
category: architecture
priority: 98
summary: Local index for Super Tool Runtime registry, policy, evidence, gates, recipes and optional programming tools.
tags:
  - atlas
  - tools
  - runtime
  - index
capabilities:
  - tool_registry
  - tool_policy_engine
  - evidence_store
  - tool_gates
decisions:
  - Tool Runtime docs are split into focused specs for AI-readable performance.
  - Tools are governed capabilities, not ad hoc shell commands.
maintenance:
  - Keep this index short.
related_paths:
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
  - docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md
  - docs/engineering-knowledge-base/tool-runtime/runbook.md
---

# Atlas Tool Runtime Specs Index

Read this directory before adding tools, recipes, normalizers, approvals,
waivers, evidence queries, gates or external coding agents.

## Files

| File | Purpose |
|---|---|
| `contracts.md` | Registry, policy engine, tiers, authority matrix and external agent boundary |
| `evidence-gates.md` | Evidence Store, normalizer, generic gate, release gate and API Contract Harness |
| `catalog-roadmap.md` | Tool families for programming-heavy Atlas and optional backlog |
| `runbook.md` | CLI/API commands, approvals, waivers and validation |

## Rule

No tool bypasses Atlas policy, evidence, gates or provider-safety. Missing
optional tools create auditable `skipped/missing` state instead of silent gaps.
