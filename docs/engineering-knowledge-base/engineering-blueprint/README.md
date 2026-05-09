---
id: atlas-engineering-blueprint-focused-index
type: engineering_knowledge
title: Atlas Engineering Blueprint Focused Index
status: active
category: engineering
priority: 90
summary: Local index for focused Engineering Blueprint runbook, schema and maturity docs.
tags:
  - atlas
  - engineering
  - blueprint
capabilities:
  - engineering_blueprint
decisions:
  - Large Blueprint docs are split into focused indexes and child specs.
maintenance:
  - Update when adding a focused Blueprint child doc.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
  - docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint/schema-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint/maturity-phases.md
---

# Atlas Engineering Blueprint Focused Index

| Doc | Purpose |
|---|---|
| `surfaces-runbook.md` | App, CLI and API operation map. |
| `lifecycle-runbook.md` | Full product lifecycle steps. |
| `schema-contracts.md` | Schema families and invariants. |
| `maturity-phases.md` | Phase matrix and DoD. |

## Rule

Blueprint docs govern Programming's heavy engineering flow. They do not create a
separate product beside `domains/programming.md`; they are the harness/runtime
discipline consumed by Programming.
