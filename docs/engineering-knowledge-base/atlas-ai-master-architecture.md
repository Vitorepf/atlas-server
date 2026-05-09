---
id: atlas-ai-master-architecture
type: engineering_knowledge
title: Atlas AI Master Architecture
status: active
category: architecture
priority: 100
summary: Compact enterprise architecture index for Atlas AI as the operational intelligence above providers, surfaces, domains, runtimes, evidence and learning.
tags:
  - atlas-ai
  - master-architecture
  - enterprise-architecture
  - orchestration
capabilities:
  - atlas_ai_master_architecture
  - enterprise_orchestration
  - operational_intelligence
  - domain_profile_orchestration
  - anti_duplication_governance
decisions:
  - Atlas AI is the operational intelligence of Atlas, not a chat, provider wrapper or single harness.
  - Atlas does not compete with Claude, ChatGPT, Gemini or Codex; it replaces direct dependence on them through an upper orchestration layer.
  - New domains are incorporated through onboarding contracts, not ad hoc commands or prompts.
  - AtlasVault is the human knowledge surface, not raw operational truth.
  - This file is an index; child docs own the details.
maintenance:
  - Keep this file compact and link detailed product architecture to master-architecture/* child docs.
  - Update START_HERE.md, README.md and canonical index when authority changes.
  - Use docs-health before and after expanding master architecture.
related_paths:
  - docs/engineering-knowledge-base/master-architecture/planes-and-authority.md
  - docs/engineering-knowledge-base/master-architecture/domain-onboarding.md
  - docs/engineering-knowledge-base/master-architecture/runtime-evidence-learning.md
  - docs/engineering-knowledge-base/master-architecture/competitive-strategy.md
  - docs/engineering-knowledge-base/archive/source-material/master-architecture/atlas-ai-master-architecture-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
---

# Atlas AI Master Architecture

Atlas AI is an evidence-driven operational intelligence. It uses providers as
engines, memory as continuity, tools as actuators, policy as law, domains as
specialization, gates as truth tests, evidence as audit and learning as
evolution.

## Product Thesis

Claude Code, ChatGPT, Gemini and future provider verticals are not the product
center. Atlas is the channel above them:

```text
Provider capability
* Atlas memory/context/tools/gates/evidence/learning
= Atlas output
```

If a provider improves, Atlas should improve by ingesting that provider as a
driver, benchmark, skill, connector or recipe.

## Seven Planes

| Plane | Purpose | Detail |
|---|---|---|
| Control | input, intent, profile, context, policy, decide | `master-architecture/planes-and-authority.md` |
| Domain | Programming, Finance, Marketing, Cognitive, Personal, Curator | `master-architecture/domain-onboarding.md` |
| Runtime | Laravel, Python, Go, Swift, providers, harnesses, tools | `master-architecture/runtime-evidence-learning.md` |
| Evidence | Ledger, projections, packets, replay, audit | `master-architecture/runtime-evidence-learning.md` |
| Learning | memory signals, quality score, Curator proposals | `master-architecture/runtime-evidence-learning.md` |
| Human Knowledge | AtlasVault/Obsidian, review, identity, synthesis | knowledge governance docs |
| Surface | CLI, App, Mobile, API, MCP, Voice | pipeline and surface docs |

## Canonical Flow

Every surface must enter the same Atlas AI pipeline:

```text
Surface -> Input -> Envelope -> Intent -> Business Context -> Domain/Profile/Flow
-> Context -> Policy -> Decide -> Receipt -> Runtime -> Gates -> Repair
-> Evidence -> Learning -> Output
```

## Non-Negotiable Rules

1. Surface does not decide.
2. Provider does not decide.
3. Tool does not decide.
4. Domain does not bypass Policy.
5. Runtime does not execute without Decision Receipt.
6. AtlasVault is curated human knowledge, not raw operational truth.
7. Business/project context is not a cognitive domain.
8. Everything repeated becomes Core.
9. Everything important becomes Evidence.
10. Curator does not auto-apply critical behavior without review.

## Read Next

| Need | Read |
|---|---|
| Plane authority and Control Plane | `master-architecture/planes-and-authority.md` |
| Adding a new domain | `master-architecture/domain-onboarding.md` |
| Runtime, Evidence and Learning | `master-architecture/runtime-evidence-learning.md` |
| How Atlas wins provider launches | `master-architecture/competitive-strategy.md` |
