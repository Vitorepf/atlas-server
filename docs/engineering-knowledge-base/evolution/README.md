---
id: atlas-ai-evolution-roadmap-index
type: engineering_knowledge
title: Atlas AI Evolution Roadmap Index
status: active
category: roadmap
priority: 95
summary: Bootstrap for implementing Atlas evolution without creating parallel architecture or provider-wrapper fragility.
tags:
  - atlas-ai
  - roadmap
  - evolution
capabilities:
  - atlas_ai_evolution_roadmap
  - documentation_governance
  - roadmap_handoff
decisions:
  - This folder is the execution split for atlas-ai-evolution-roadmap.md.
  - Child docs own details; the parent roadmap stays compact.
  - Any new evolution feature must declare Core, Domain, Surface, Runtime, Evidence and Curator impact.
maintenance:
  - Keep each child doc focused and under documentation line limits.
  - Link new APs back here and to the canonical architecture index.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
---

# Evolution Roadmap Index

Use this folder when implementing long-term Atlas evolution. It converts the
large source roadmap into focused, high-performance docs for AI sessions.

## Child Docs

| Doc | Owns |
|---|---|
| `context-builder-roadmap.md` | Vector RAG, Graph RAG, Evidence Replay, Context Pack Cache and retrieval routing |
| `provider-performance-roadmap.md` | AP-99, Dynamic Compute Market, provider telemetry and model routing |
| `advanced-capabilities-backlog.md` | Frontier ideas such as swarms, tool synthesis, simulations and zero-click operations |
| `personal-longitudinal-roadmap.md` | privacy vault, body-cognition memory, life timeline and long-horizon signals |
| `implementation-handoff.md` | AP order, execution gates and agent handoff rules |

## Mandatory Placement Questions

Before coding any item from this folder, answer:

1. Is this Core, Domain, Surface, Runtime, Evidence, Learning or Curator?
2. Which existing contract must be extended?
3. Which events prove it worked?
4. Which policy prevents overreach?
5. Which doc owns future maintenance?

If the answer is unclear, run the feature placement command before writing code.

## Anti-Drift Rules

- Atlas does not compete with providers; it uses and replaces direct provider
  usage through a governed upper layer.
- Provider-specific launches become provider drivers, skills, benchmarks or
  domain recipes.
- No feature may bypass Decision Receipt, Policy/Profile, Evidence Ledger or
  documentation governance.
