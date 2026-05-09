---
id: atlas-vault-contracts
type: engineering_knowledge
title: AtlasVault Contracts
status: active
category: architecture
priority: 97
summary: Focused contract for Obsidian/AtlasVault authority, managed note frontmatter, atlas links, privacy and conflict behavior.
tags:
  - atlas
  - obsidian
  - atlas-vault
  - contracts
capabilities:
  - obsidian_atlas_vault
  - managed_human_notes
  - bidirectional_memory_links
  - provider_safe_note_projection
decisions:
  - Obsidian/AtlasVault is Human Knowledge Surface and Personal Knowledge Workspace.
  - Postgres, canonical repo docs, audits and Memory Registry remain operational source of truth.
  - Vault notes can create reviewable candidates, never automatic high-impact memory.
maintenance:
  - Keep schemas and behavioral contracts here.
  - Move long implementation history to archive/source-material.
related_paths:
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/vault/runbook.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - app/Services/Semantic/AtlasVaultManagedNoteService.php
  - app/Services/Semantic/AtlasVaultFrontmatterService.php
  - app/Services/Semantic/AtlasVaultLinkService.php
  - app/Services/Semantic/VaultFileStore.php
---

# AtlasVault Contracts

## Authority

| Layer | Role | Primary source? |
|---|---|---|
| Repo docs | Architecture, contracts, ADRs, runbooks | Yes for engineering knowledge |
| Postgres | Memory, traces, runs, audits, indexed docs/code | Yes for runtime |
| Open Brain | Provider-safe context composition | No; composes primary sources |
| Provider files | `AGENTS.md`, `CLAUDE.md` projections | No |
| Obsidian/AtlasVault | Human writing, reading, review and navigation | No |

The `atlas_vault` Surface Adapter exposes human workspace capabilities only. It
must not declare `memory_recall`, `context_compose` or tool execution authority.

## Directional Flow

| Direction | Meaning |
|---|---|
| Obsidian -> Atlas | Import markdown, classify privacy, index semantic note, create review candidate, optionally promote governed memory |
| Atlas -> Obsidian | Export managed human note, status page, index, audit or reflection for human review |

Open Brain may consume indexed/promoted artifacts. It must not read arbitrary
vault files directly in runtime.

## Managed Frontmatter

Required shape:

```yaml
---
atlas_id: entity_id
atlas_type: memory_entry
atlas_managed: true
sync_status: managed
source: atlas
source_type: atlas_memory_entry
source_id: entity_id
privacy_class: normal
provider_safe: true
redaction_status: clean
canonical: false
created_by: atlas
updated_at: 2026-05-03T00:00:00Z
---
```

Rules:

- boolean fields must parse as real booleans;
- `privacy_class=secret` forces `provider_safe=false`;
- `redaction_status=blocked|needs_review` forces `provider_safe=false`;
- `updated_at` must be ISO-8601 with explicit timezone;
- `canonical=true` is exceptional and usually belongs to repo docs, not vault notes.

## Atlas Links

Supported stable links:

- `atlas://memory/{id}`;
- `atlas://verbatim-memory/{id}`;
- `atlas://semantic-note/{id}`;
- `atlas://task/{id}`;
- `atlas://project/{id}`;
- `atlas://engineering-run/{id}`;
- `atlas://open-brain/audit/{id}`;
- `atlas://trace/{id}`.

IDs must be safe path segments: `A-Za-z0-9._:-`; no slash, spaces, traversal or
ambiguous markdown labels.

## Note Types

| Type | Deterministic path family |
|---|---|
| `memory_entry` | `Atlas/Memory/memory_entry/{slug}-{id}.md` |
| `verbatim_memory` | `Atlas/Memory/verbatim_memory/{slug}-{id}.md` |
| `semantic_note` | `Atlas/SemanticNotes/{slug}-{id}.md` |
| `task` | `Atlas/Tasks/managed/{slug}-{id}.md` |
| `project` | `Atlas/Projects/{slug}-{id}.md` |
| `engineering_run` | `Atlas/Engineering/Runs/{slug}-{id}.md` |
| `open_brain_audit` | `Atlas/OpenBrain/Audits/{slug}-{id}.md` |
| `trace` | `Atlas/Traces/{slug}-{id}.md` |

Custom paths must be relative to the vault, end in `.md` and pass
`VaultFileStore` confinement.

## Managed Body

```md
<!-- ATLAS:MANAGED:START -->
Generated provider-safe human projection.
<!-- ATLAS:MANAGED:END -->

## Manual Notes

Human notes preserved here.
```

The managed block can be regenerated. Manual notes are preserved. Drift outside
allowed blocks becomes conflict.

## Promotion To Memory

A vault note can become Atlas memory only after:

- reusable content is identified;
- scope and memory type are declared;
- privacy/redaction/provider-safety pass;
- duplicates/conflicts are linked;
- source note remains traceable;
- review/audit records the promotion.

## Conflict Contract

Conflict conditions include unmanaged files, invalid frontmatter, missing or
duplicated managed markers, stable frontmatter drift, unsafe paths, existing
`sync_status=conflict`, and divergent notes for the same `atlas_id`.

Expected behavior:

- never overwrite silently;
- mark or queue conflict;
- expose allowed resolution actions;
- require explicit operator action for `adopt`, `archive`, `merge`,
  `regenerate`, `force` or `dismiss`.
