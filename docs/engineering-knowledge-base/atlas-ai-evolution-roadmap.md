---
id: atlas-ai-evolution-roadmap
type: engineering_knowledge
title: Atlas AI Evolution Roadmap
status: active
category: roadmap
priority: 95
summary: Operational index for Atlas AI evolution. Keeps the final-product backlog governed by Kernel, Evidence Ledger, Context Builder, provider performance, self-improvement and documentation law.
tags:
  - atlas-ai
  - roadmap
  - evolution
  - provider-performance
  - context-builder
capabilities:
  - atlas_ai_evolution_roadmap
  - provider_performance_contract
  - hybrid_context_builder
  - self_improvement_evolution
decisions:
  - This document is an index, not a parallel architecture.
  - Evolution ideas must extend existing Kernel, Context Builder, Evidence Ledger, Provider Strategy, Policy/Profile and Curator contracts.
  - New large material must be added as child docs under evolution/ or AP specs, never appended here.
maintenance:
  - Read atlas-ai-canonical-architecture-index.md before implementing any phase.
  - Read atlas-ai-documentation-operating-system.md before expanding roadmap docs.
  - Use docs/engineering-knowledge-base/evolution/README.md as the implementation bootstrap.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-phase-0-audit.md
  - docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
  - docs/engineering-knowledge-base/evolution/README.md
  - docs/engineering-knowledge-base/archive/source-material/evolution/atlas-ai-evolution-roadmap-full-2026-05-08.md
---

# Atlas AI Evolution Roadmap

This is the compact operational entry point for Atlas evolution. The original
long source was archived at
`archive/source-material/evolution/atlas-ai-evolution-roadmap-full-2026-05-08.md`
and remains available for audit.

## Non-Negotiable Frame

Atlas is not a wrapper around Claude, Codex, Gemini or ChatGPT. Atlas is the
upper orchestration layer that absorbs provider progress and multiplies it
through local memory, domain context, receipts, evidence, gates and curation.

Therefore:

1. provider launches become inputs to Atlas, not existential threats;
2. capabilities go through Kernel and Domain contracts;
3. repeated patterns move to Core;
4. all important outcomes become Evidence;
5. Curator proposes evolution, but does not self-apply critical behavior.

## Read Order

| Need | Read |
|---|---|
| Evolution bootstrap | `evolution/README.md` |
| Hybrid RAG and context | `evolution/context-builder-roadmap.md` |
| Provider performance and model routing | `evolution/provider-performance-roadmap.md` |
| Advanced frontier backlog | `evolution/advanced-capabilities-backlog.md` |
| Personal longitudinal intelligence | `evolution/personal-longitudinal-roadmap.md` |
| Execution waves and AP handoff | `evolution/implementation-handoff.md` |

## Current Implementation Spine

| Phase | Contract | Status |
|---|---|---|
| Phase 0 | AP-99 Provider Performance Contract | implemented/read-model path |
| Phase 1 | AP-100 Context Pack Manifest Reflection | implemented |
| Phase 1 | AP-101 Retrieval Router | next natural Context Builder increment |
| Phase 2 | Self-RAG and reflection gates | planned |
| Phase 2 | Graph RAG explicit/observed/inferred | planned |
| Phase 3 | Personal longitudinal memory | planned, privacy gated |
| Phase 4 | Proactive Curator and adaptive routing | planned, proposal-only first |

## Authority

| Topic | Owner |
|---|---|
| Kernel invariants | `atlas-ai-kernel-architecture.md` |
| Product planes | `atlas-ai-master-architecture.md` |
| Provider/model choice | `evolution/provider-performance-roadmap.md` + AP-99 |
| Context retrieval | `evolution/context-builder-roadmap.md` |
| Future power backlog | `evolution/advanced-capabilities-backlog.md` |
| Personal memory | `evolution/personal-longitudinal-roadmap.md` |
| Implementation order | `evolution/implementation-handoff.md` |

## Implementation Rule

If a contract already exists, extend it. If a table/event/service already
exists, normalize the payload there. Do not create a new subsystem with a new
name for the same function.
