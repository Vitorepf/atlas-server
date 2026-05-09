---
id: atlas-ai-resolver-corpus-p0-promotions
type: engineering_knowledge
title: Atlas AI Resolver Corpus P0 Promotions
status: active
category: architecture
priority: 86
summary: P0 resolver corpus decisions that were promoted into canonical Atlas AI architecture docs.
tags:
  - atlas-ai
  - resolver-corpus
  - promotions
capabilities:
  - resolver_corpus_governance
  - domain_profile_orchestration
decisions:
  - P0 resolver material has been promoted as compact canonical decisions, not copied wholesale.
maintenance:
  - Update when a P0 source is promoted or superseded.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
---

# Atlas AI Resolver Corpus P0 Promotions

| Source theme | Canonical result |
|---|---|
| Domain Profile Orchestration | `Domain != flow`; `Profile != model preset`; surfaces enter domain/flow profiles. |
| Atlas Decide Final Architecture | Decide compiles intent, risk, context strategy, provider/model policy, gates, fallback and evidence into a receipt. |
| Atlas Programming Product Architecture | Programming is a domain with orchestrator and flows: dev, forge, fix, review, QA, security, refactor. |
| Super Tool Runtime Core | Tool Runtime is shared Core with registry, policy, executor, normalizer, evidence and gates. |
| Gaps/ideas source | Future backlog is governed by `atlas-ai-governed-backlog.md` and domain docs. |

## Promotion Rule

If a resolver source appears to contain missing value, first check the canonical
destination. Patch the owner doc only with the missing stable decision.

## Current Owner Docs

- `atlas-ai-operating-system.md`
- `atlas-ai-pipeline.md`
- `atlas-ai-core-vs-domain.md`
- `atlas-ai-kernel-architecture.md`
- `domains/programming.md`
- `super-tool-runtime-core.md`
