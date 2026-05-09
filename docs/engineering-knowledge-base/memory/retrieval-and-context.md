---
id: atlas-ai-memory-retrieval-and-context
type: engineering_knowledge
title: Atlas AI Memory Retrieval And Context
status: active
category: architecture
priority: 98
summary: Focused contract for deterministic recall, context pack memory refs, budgets, ranking and provider-safe composition.
tags:
  - atlas
  - memory
  - retrieval
  - context-pack
capabilities:
  - context_pack_recall
  - deterministic_recall
  - code_intelligence_index
  - engineering_knowledge_base
decisions:
  - Context is selected deterministically with explicit reasons and budgets.
  - Context packs carry refs, not unbounded raw dumps.
  - Retrieval must prefer provider-safe local sources unless an AP authorizes external/vector systems.
maintenance:
  - Keep ranking and context composition changes here.
  - Update tests when adding new context ref types.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/context-pack.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
---

# Atlas AI Memory Retrieval And Context

## Context Ref Types

| Ref | Source | Required fields |
|---|---|---|
| `memory_refs` | Memory Registry | id, type, scope, priority, reason, provider-safe summary |
| `verbatim_refs` | Verbatim Store | id, privacy class, redacted excerpt, reason |
| `knowledge_refs` | Engineering KB | doc id/path, category, priority, summary, reason |
| `code_refs` | Code Intelligence | module/symbol/path/test relation and reason |
| `provider_projection_refs` | Projection Audit | target, checksum, drift status, operation |
| `open_brain_audit_refs` | Open Brain Audit | requester, export hash, policy and counts |

## Ranking Rules

1. Prefer explicit task/project/session scope over global memory.
2. Prefer accepted decisions over observations.
3. Prefer recent unresolved issues only when relevant to the task.
4. Prefer docs with canonical status over archived/source material.
5. Deduplicate by semantic role and source path/id.
6. Include reason strings so an agent can explain why context was injected.

## Budget Rules

- Context packs should carry compact refs and summaries first.
- Raw code/doc excerpts require explicit need and bounded character budget.
- Verbatim snippets use redacted text and injection scanning.
- Code Intelligence refs point to paths/symbols/tests instead of dumping files.
- If budget is exceeded, drop lowest priority refs and report truncation.

## Forbidden Paths

- No raw private notes from Obsidian by default.
- No provider projection content treated as source truth.
- No vector retrieval silently changing deterministic order.
- No context pack that cannot explain included sources.

## Validation

Context changes should run focused tests for:

- `AiContextPackBuilder`;
- `AtlasMemoryContextComposer`;
- `EngineeringContextPackService`;
- privacy/redaction filters;
- architecture validation.
