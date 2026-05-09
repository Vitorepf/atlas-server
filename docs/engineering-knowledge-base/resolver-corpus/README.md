---
id: atlas-ai-resolver-corpus-readme
type: engineering_knowledge
title: Atlas AI Resolver Corpus README
status: active
category: architecture
priority: 86
summary: Entry point for focused resolver corpus audit docs.
tags:
  - atlas-ai
  - resolver-corpus
capabilities:
  - resolver_corpus_governance
decisions:
  - Resolver corpus docs are source material governed by KB promotion rules.
maintenance:
  - Update when child docs are added or resolver material changes class.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/resolver-corpus/p0-promotions.md
  - docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md
---

# Atlas AI Resolver Corpus README

## Purpose

This folder prevents old resolver material from being forgotten or accidentally
treated as current authority.

## Docs

| Doc | Use |
|---|---|
| `p0-promotions.md` | Stable P0 decisions already promoted into canonical docs. |
| `policy-profile-model.md` | Domain/Profile/Policy model extracted from resolver source material. |

## Source Rule

Resolver files are historical source material. The current source of truth is
the KB owner doc referenced by each promotion.
