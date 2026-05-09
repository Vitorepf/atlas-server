---
id: atlas-ai-resolver-corpus-audit
type: engineering_knowledge
title: Atlas AI Resolver Corpus Audit
status: active
category: architecture
priority: 99
summary: Compact index for the resolver-o-que-vale-a-pena corpus audit, preserving promotion decisions and source-material handling.
tags:
  - atlas-ai
  - resolver-corpus
  - architecture
  - policy-profile
  - super-tool-runtime
  - atlas-decide
capabilities:
  - resolver_corpus_governance
  - policy_profile_architecture
  - decision_receipt_governance
  - super_tool_runtime_governance
  - domain_profile_orchestration
decisions:
  - The resolver corpus contains valuable source material, but the KB is the operational authority.
  - Domain Profile / Flow Profile remains the canonical way to organize large Atlas domains.
  - Atlas Decide emits Decision Receipts and does not execute domain flows.
  - Super Tool Runtime is Core, not Forge-only or Harness-only.
maintenance:
  - Update when resolver source material is promoted, archived or rejected.
  - Keep this file as an index; use child docs for detail.
related_paths:
  - docs/engineering-knowledge-base/resolver-corpus/README.md
  - docs/engineering-knowledge-base/resolver-corpus/p0-promotions.md
  - docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md
  - docs/engineering-knowledge-base/archive/source-material/atlas-ai-resolver-corpus-audit-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
---

# Atlas AI Resolver Corpus Audit

This is the active compact index for the `resolver-o-que-vale-a-pena` corpus.
The full original audit is preserved at
`archive/source-material/atlas-ai-resolver-corpus-audit-full-2026-05-08.md`.

## Rule

```text
Nothing important stays lost in resolver.
Nothing becomes canonical without classification.
Nothing competes with the KB after promotion or archive.
```

## Read Order

| Need | Read |
|---|---|
| Corpus orientation | `resolver-corpus/README.md` |
| P0 decisions already promoted | `resolver-corpus/p0-promotions.md` |
| Domain/Profile/Policy model | `resolver-corpus/policy-profile-model.md` |
| Historical full audit | archived full audit |

## Classification

| Level | Meaning |
|---|---|
| P0 | Must influence Mother Architecture now. |
| P1 | Implementation reference or near roadmap. |
| P2 | Future idea or immature domain. |
| Archive | Useful history, not active authority. |

## Canonical Result

The promoted resolver material now supports:

- Domain Profile / Flow Profile separation;
- Atlas Decide as operational compiler with Decision Receipt;
- Programming as a domain, not a CLI-only product;
- Super Tool Runtime as shared Core;
- Policy/Profile as layered governance above provider/model choice.

## Anti-Pattern

Do not reopen resolver files to create a new architecture path. Diff them against
the current owner docs, promote only missing decisions, and preserve source links.
