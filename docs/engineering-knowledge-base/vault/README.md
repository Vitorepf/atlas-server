---
id: atlas-vault-specs-index
type: engineering_knowledge
title: AtlasVault Specs Index
status: active
category: architecture
priority: 97
summary: Local index for Obsidian/AtlasVault as Human Knowledge Surface and Personal Knowledge Workspace.
tags:
  - atlas
  - obsidian
  - atlas-vault
  - index
capabilities:
  - obsidian_atlas_vault
  - managed_human_notes
  - vault_ingestion
decisions:
  - AtlasVault specs are split for AI-readable performance.
  - Obsidian is a human surface; operational memory lives in governed Atlas stores.
maintenance:
  - Keep this index short.
  - Add new Vault child specs here before linking from global indexes.
related_paths:
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/vault/contracts.md
  - docs/engineering-knowledge-base/vault/runbook.md
---

# AtlasVault Specs Index

Read this directory before changing Obsidian, AtlasVault, semantic vault,
managed notes, markdown import/export or vault conflict handling.

## Files

| File | Purpose |
|---|---|
| `contracts.md` | Authority, frontmatter, links, note types, privacy and conflict rules |
| `runbook.md` | CLI/API operations, smoke commands, phase validation and safety gates |

## Rules

- AtlasVault is Human Knowledge Surface, not operational primary source.
- Raw notes never enter providers without index, privacy, redaction and review.
- Atlas-generated notes are managed human projections.
- Conflicts are recorded; human edits are not overwritten silently.
- Open Brain consumes indexed/promoted artifacts, not arbitrary vault files.
