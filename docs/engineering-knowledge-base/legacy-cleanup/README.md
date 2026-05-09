---
id: legacy-cleanup-readme
type: engineering_knowledge
title: Legacy Cleanup README
status: active
category: documentation-governance
priority: 88
summary: Entry point for Atlas legacy documentation cleanup, inventory, execution waves and handoff.
tags:
  - atlas
  - documentation
  - cleanup
capabilities:
  - legacy_documentation_cleanup
decisions:
  - Legacy cleanup is governed by focused child docs and compact owner indexes.
  - The archived full reports are source material, not day-to-day authority.
maintenance:
  - Update when a child cleanup doc is added or retired.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
  - docs/engineering-knowledge-base/legacy-cleanup/inventory-summary.md
  - docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md
  - docs/engineering-knowledge-base/legacy-cleanup/waves-and-gates.md
  - docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md
---

# Legacy Cleanup README

This folder holds focused documentation for cleaning, promoting, redirecting and
archiving legacy Atlas docs.

## Read Order

| Step | Doc | Use |
|---|---|---|
| 1 | `legacy-documentation-cleanup-report.md` | Active report index. |
| 2 | `inventory-summary.md` | Cleanup classes and current status. |
| 3 | `executed-promotions.md` | What was already promoted or archived. |
| 4 | `legacy-documentation-cleanup-plan.md` | Active plan index. |
| 5 | `waves-and-gates.md` | How to execute a cleanup wave. |
| 6 | `handoff-checklist.md` | Final report and validation checklist. |

## Ownership

Legacy cleanup is a documentation governance workflow. It can update canonical
docs only when the change is a stable decision promotion or a redirect fix.

## Source Material

Full historical audit files live in:

- `archive/source-material/legacy-documentation-cleanup-report-full-2026-05-08.md`
- `archive/source-material/legacy-documentation-cleanup-plan-full-2026-05-08.md`

Use them only when the compact docs do not contain enough historical detail.

## Rule For AI Sessions

An AI session must not create a new canonical architecture path from a legacy
doc. It must first ask: which current owner doc should receive this decision?
