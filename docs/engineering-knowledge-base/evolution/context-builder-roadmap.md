---
id: atlas-ai-evolution-context-builder-roadmap
type: engineering_knowledge
title: Context Builder Evolution Roadmap
status: active
category: roadmap
priority: 94
summary: Governed roadmap for hybrid retrieval, Graph RAG, Evidence Replay, Context Pack Cache and Context Builder routing.
tags:
  - atlas-ai
  - context-builder
  - graph-rag
  - vector-rag
capabilities:
  - hybrid_context_builder
  - retrieval_router
  - graph_rag
decisions:
  - Atlas should not choose between Vector RAG and Graph RAG; it routes by intent.
  - Evidence Ledger is the source for replayable operational memory.
  - Knowledge Graph is a projection, not the operational source of truth.
maintenance:
  - Keep retrieval changes tied to Context Builder and Kernel receipts.
  - Do not create parallel memory stores without a projection contract.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-memory-core.md
---

# Context Builder Evolution Roadmap

## Target Shape

Context Builder chooses among:

1. Vector retrieval for semantic similarity;
2. Graph retrieval for relation traversal;
3. Evidence replay for prior decisions and outcomes;
4. Code intelligence for symbols, tests and modules;
5. Memory signals for preferences, lessons and quality trends.

## Routing Rules

| Question type | Primary source | Secondary source |
|---|---|---|
| Factual lookup | Vector retrieval | KB docs |
| Why did we decide X? | Evidence Replay | Decision Receipt chain |
| What breaks if this changes? | Graph RAG | Code Intelligence |
| What should Atlas improve? | Learning signals | Curator proposals |
| What does Vitor need now? | Personal memory projection | Policy/Profile |

## Graph Maturity

| Stage | Meaning |
|---|---|
| Explicit | human or service declares relationship |
| Observed | repeated evidence implies relationship |
| Inferred | model proposes relationship with confidence |
| Approved | proposal accepted and promoted |

Inferred relations never become default context without approval or strong
evidence policy.

## Next APs

| AP | Purpose |
|---|---|
| AP-101 | Retrieval Router: chooses vector/graph/evidence/code/memory per task |
| AP-102 | Retrieval Plan Summary: explain why each context source was used |
| AP-103 | Required Source Availability: block low-confidence runs missing required sources |
| AP-105 | Open Brain Retrieval Self-Improvement: proposes retrieval improvements |

## Gates

- Required-source gate for high-risk tasks.
- Provider-safe redaction before external model calls.
- Context budget accounting in the Decision Receipt.
- Evidence event for retrieval plan and source usage.
