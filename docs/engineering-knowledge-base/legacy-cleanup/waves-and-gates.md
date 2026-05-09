---
id: legacy-cleanup-waves-and-gates
type: engineering_knowledge
title: Legacy Cleanup Waves And Gates
status: active
category: documentation-governance
priority: 86
summary: Execution waves, gates and rollback rules for continuing Atlas legacy documentation cleanup safely.
tags:
  - atlas
  - documentation
  - cleanup
  - gates
capabilities:
  - legacy_documentation_cleanup
decisions:
  - Cleanup waves must be small, reversible and validated.
  - Runtime changes are outside cleanup scope unless explicitly declared.
maintenance:
  - Update when the cleanup process changes.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
  - docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
---

# Legacy Cleanup Waves And Gates

## Wave 0 - Freeze Authority

Goal: prevent old docs from competing with current governance.

Actions:

- confirm README, START_HERE and canonical index are still the entry points;
- capture dirty worktree;
- decide the owner doc for every legacy source being touched.

Gate: the session can answer "which doc wins in conflict?"

## Wave 1 - Promote Stable Decisions

Goal: move durable decisions into small canonical docs.

Actions:

- extract decision, not transcript;
- add frontmatter and related paths;
- link source material;
- update index only if the new doc is a real authority.

Gate: promoted doc passes line limits and has a clear owner.

## Wave 2 - Merge Partial Duplicates

Goal: remove duplicate authority without losing useful details.

Actions:

- diff legacy source against owner doc;
- patch only missing decisions;
- add redirect or archive note to source.

Gate: no sentence creates a new master flow.

## Wave 2.5 - Normalize Domain Docs

Goal: keep domain specs as Layer 4, not architecture roots.

Actions:

- verify safety boundaries;
- verify domain does not decide provider, policy or runtime;
- connect domain to pipeline, memory and evidence.

Gate: domain remains below Kernel, Master and Pipeline.

## Wave 3 - Redirect And Archive

Goal: preserve history while removing authority confusion.

Required note:

```md
> Status: archived.
> Canonical replacement: `docs/engineering-knowledge-base/...`.
> Cleanup note: preserved for history and link compatibility. Do not use as source of truth.
```

Gate: old doc clearly points to the replacement.

## Wave 4 - Quarantine Delete Candidates

Goal: protect against accidental loss.

Required checks:

```bash
rg -n "filename-or-slug" docs app config database routes tests scripts
git log --follow -- path/to/file.md
```

Gate: delete waits for a separate human-approved change.

## Wave 5 - Human Knowledge Surface

Goal: prevent personal knowledge from becoming raw provider context.

Actions:

- keep sensitive material in AtlasVault/Obsidian or curated sync;
- promote only reviewed excerpts;
- label privacy and source policy.

Gate: provider-safe content is explicit.

## Rollback

If cleanup creates confusion:

1. revert only the cleanup patch;
2. restore the previous redirect/header;
3. keep archived source material;
4. record the cause before retrying.
