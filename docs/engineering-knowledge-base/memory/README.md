---
id: atlas-ai-memory-readme
type: engineering_knowledge
title: Atlas AI Memory Specs Index
status: active
category: architecture
priority: 98
summary: Local index for focused Atlas memory, retrieval and Open Brain contracts.
tags:
  - atlas
  - memory
  - index
capabilities:
  - cognitive_immune_gate
  - memory_registry
  - context_pack_recall
  - open_brain_context_injection
decisions:
  - Memory docs are split into focused contracts for AI-readable performance.
  - This directory owns active memory contracts; archived source material is not operational authority.
maintenance:
  - Keep this index short.
  - Add new child specs here before linking from global indexes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
---

# Atlas AI Memory Specs Index

Read this directory when implementing memory, context pack retrieval, provider
projection, Open Brain or MCP context exposure.

## Files

| File | Purpose |
|---|---|
| `cognitive-immune-learning-kernel.md` | Core law for raw capture quarantine, noise filtering, learning signals, memory promotion, forgetting and evals |
| `contracts.md` | Memory Registry, Verbatim Store, Engineering KB, Code Intelligence and provider projection contracts |
| `retrieval-and-context.md` | Deterministic recall, context budgets, context refs and prompt-safe composition |
| `open-brain-mcp.md` | Open Brain API/CLI/MCP/HTTP boundary, audit and no-provider-decision rules |

## Rules

- Memory belongs to Atlas, not providers.
- Raw capture is not memory, evidence, context or decision.
- Every input starts in cognitive quarantine until explicit gates promote it.
- Context packs must be deterministic, budgeted, redacted and explainable.
- Provider projections are generated artifacts, not source of truth.
- Open Brain exports context; it does not decide, execute or promote memory.
- New vector/RAG systems need their own AP/spec before implementation.
