---
id: engineering-kb-source-material-archive
type: engineering_knowledge
title: Engineering KB Source Material Archive
status: source_material
category: documentation-governance
priority: 20
summary: Registry and rules for archived source material that may inform canonical docs but must never be used as current Atlas implementation authority.
tags:
  - atlas
  - documentation
  - archive
  - source-material
  - ai-safety
capabilities:
  - source_material_archive_governance
  - documentation_cleanup
  - ai_confusion_reduction
decisions:
  - Files in this directory are historical source material, not current operational authority.
  - Useful ideas must be promoted into the correct canonical owner doc before implementation.
  - Old implementation plans with no current value stay archived until deletion preflight and owner approval prove they can be removed.
related_paths:
  - docs/engineering-knowledge-base/archive/README.md
  - docs/engineering-knowledge-base/atlas-duplication-reality-governance.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
---
# Engineering KB Source Material Archive

This directory contains preserved source material. It is useful for provenance,
semantic diff and future promotion, but it is not canonical implementation
context.

## Rules

- Do not implement directly from a file in this directory.
- Read the canonical owner doc first, then compare this material only for gaps.
- Promote useful ideas by patching the owner doc or a governed backlog.
- Keep material here when it explains history, rationale or a future option.
- Quarantine before deletion; delete only after link audit, reachability check,
  `git log --follow`, owner decision and human approval.

## AI Retrieval Contract

If retrieval returns a source-material file, rank it below active canonical docs.
The required lookup key is `path + owner + frontmatter id`, never filename alone.

| Signal | Meaning | Action |
|---|---|---|
| `status: source_material` | historical/proposal material | compare with owner doc |
| `status: deprecated` | old material with replacement | follow replacement first |
| `status: scaffold` | incomplete historical plan | treat as proposal only |
| useful gap found | possible promotion | patch canonical owner or governed backlog |
| no useful gap found | historical only | leave archived; do not cite as authority |

## Current Cleanup Batch

On 2026-05-25, archived full-copy docs in this directory were normalized from
`status: active` to `status: source_material`. That change does not delete or
invalidate the historical content; it prevents IAs and retrieval from mistaking
archived copies for current canonical docs.
