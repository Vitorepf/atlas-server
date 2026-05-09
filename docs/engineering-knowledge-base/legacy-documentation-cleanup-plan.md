---
id: legacy-documentation-cleanup-plan
type: engineering_knowledge
title: Legacy Documentation Cleanup Plan
status: active
category: documentation-governance
priority: 90
summary: Compact execution plan for future Atlas legacy cleanup waves, with safety rules, phases, gates and validation.
tags:
  - atlas
  - documentation
  - cleanup
  - plan
capabilities:
  - legacy_documentation_cleanup
  - documentation_archive_governance
  - source_material_promotion
decisions:
  - Cleanup work must protect runtime, canonical architecture and documentation authority.
  - Source material is promoted, merged, archived or quarantined; it does not compete with canonical docs.
  - Delete is a separate human-approved action after redirect and reference audit.
maintenance:
  - Use this plan before touching legacy docs or resolver/source-material folders.
  - Add detailed future waves to focused child docs, not to this index.
related_paths:
  - docs/engineering-knowledge-base/legacy-cleanup/README.md
  - docs/engineering-knowledge-base/legacy-cleanup/waves-and-gates.md
  - docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
  - docs/engineering-knowledge-base/archive/source-material/legacy-documentation-cleanup-plan-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/archive/README.md
---

# Legacy Documentation Cleanup Plan

This is the active cleanup plan index. The full original plan remains archived at
`docs/engineering-knowledge-base/archive/source-material/legacy-documentation-cleanup-plan-full-2026-05-08.md`.

Use this document when a session needs to continue cleanup without creating
documentation drift, duplicate authority or accidental deletion.

## Safety Rules

1. Do not delete files in a first cleanup wave.
2. Do not move a document without redirect or archived source path.
3. Do not edit master architecture docs unless the canonical index requires it.
4. Do not promote Obsidian/AtlasVault content without privacy review.
5. Do not treat prompts, provider bootstrap files or task plans as source of truth.
6. Do not mix runtime implementation and documentation cleanup unless explicitly scoped.
7. Do not add new responsibilities to an oversized doc.

## Required Preflight

Before changing legacy docs:

```bash
git status --short
php artisan atlas:ai:docs-split-plan --owner=legacy_cleanup --json
rg -n "target-doc-or-slug" docs app config database routes tests scripts
```

The session must know:

- which doc is canonical;
- which legacy source is being touched;
- whether the work is promotion, merge, redirect, archive or quarantine;
- which validation commands will prove the result.

## Execution Waves

| Wave | Purpose | Detail |
|---|---|---|
| 0 | Freeze authority | Confirm README, START_HERE, canonical index and Documentation OS. |
| 1 | Promote stable decisions | Convert valuable source material into small KB docs. |
| 2 | Merge partial duplicates | Patch existing owner docs, then redirect legacy source. |
| 2.5 | Normalize domain docs | Ensure Finance, Personal Development and future domains stay Layer 4. |
| 3 | Redirect and archive | Mark old docs as historical with canonical replacement. |
| 4 | Quarantine delete candidates | Preserve first; delete only in a future reviewed PR. |
| 5 | Human Knowledge Surface | Keep vault/personal material private and curated. |

Detailed execution gates live in `legacy-cleanup/waves-and-gates.md`.

## Invariants

| Invariant | Verification |
|---|---|
| Runtime untouched | `git diff --name-only` should not include runtime paths unless scoped. |
| Canonical docs protected | Conflicts are resolved through `atlas-ai-canonical-architecture-index.md`. |
| Redirect before delete | Archived docs point to canonical replacements. |
| Privacy before promotion | Personal/vault content is redacted before KB use. |
| Fair benchmark isolated | Strict provider benchmarks are not mixed with Atlas-supercharged claims. |
| Worktree respected | Concurrent changes are not reverted. |

## Promotion Definition Of Done

Each promoted item must:

- have frontmatter;
- declare authority and related paths;
- synthesize stable decisions instead of copying source material;
- preserve the legacy source link;
- fit the line limits from Documentation OS;
- pass docs-health and architecture validation.

## Delete Definition Of Done

Deletion is blocked unless all are true:

- the document had redirect or archive status for at least one release/wave;
- `rg` finds no live references;
- `git log --follow` was reviewed;
- valuable material was promoted or explicitly rejected;
- Vitor approved deletion explicitly.

## Handoff

Use `legacy-cleanup/handoff-checklist.md` for PR/session handoff. At minimum,
the final message must report:

- files promoted, archived or redirected;
- source material preserved;
- validation commands run;
- remaining split-required docs, if any.

## Validation

```bash
atlas engineering knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
```

## Maintenance Rule

If this plan grows again, split the new material into a child doc under
`legacy-cleanup/` and keep this file as an operational index.
