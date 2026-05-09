---
id: atlas-ai-architecture-audit-readme
type: engineering_knowledge
title: Atlas AI Architecture Audit README
status: active
category: architecture
priority: 88
summary: Entry point for the focused Atlas AI architecture audit docs.
tags:
  - atlas-ai
  - architecture
  - audit
capabilities:
  - architecture_audit
decisions:
  - Architecture audit findings are split into focused docs to keep AI reading fast.
maintenance:
  - Update when audit child docs are added or retired.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/architecture-audit/canonical-findings.md
  - docs/engineering-knowledge-base/architecture-audit/capability-ownership-map.md
  - docs/engineering-knowledge-base/architecture-audit/programming-pipeline-target.md
---

# Atlas AI Architecture Audit README

## Purpose

This folder preserves the important findings from the architecture audit without
forcing every AI session to read the historical 500-line report.

## Docs

| Doc | Use |
|---|---|
| `canonical-findings.md` | What truths already exist and where disorder appeared. |
| `capability-ownership-map.md` | Which Atlas subsystem owns each capability. |
| `programming-pipeline-target.md` | Target shape for unifying `dev`, `forge`, `fix`, `continue`, app and workers. |

## Rule

Do not use this audit folder to create new architecture roots. Findings must be
converted into APs, domain docs or owner-doc patches.
