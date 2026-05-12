---
id: atlas-vault-cartography-schema
type: engineering_knowledge
title: Atlas Vault Cartography Schema
status: active
category: architecture
priority: 98
summary: Index for the Atlas Vault Cartography contract, split into source-authority/frontmatter contracts and implementation runbook.
tags:
  - atlas
  - obsidian
  - atlas-vault
  - cartography
  - schema
  - source-authority
capabilities:
  - atlas_vault_cartography
  - vault_visualization
  - architecture_navigation
  - live_documentation
decisions:
  - The official documentation is the truth; cartography is the interface that makes truth navigable and verifiable.
  - Technical and architectural content lives in repo docs; human reflective content lives in AtlasVault.
  - The cartography reads real source files and never creates a synced projection between repo and vault.
  - Semantic frontmatter remains canonical; visual fields are additive and live under the `graph_*` namespace.
maintenance:
  - Keep this file as an index only.
  - Put schema rules in the contracts child spec.
  - Put migration, readers, watchers and rollout in the runbook child spec.
related_paths:
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-runbook.md
  - docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
  - docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md
  - docs/engineering-knowledge-base/vault/contracts.md
  - docs/engineering-knowledge-base/vault/README.md
owner: atlas-ai
layer: 0.5-documentation
schema_version: 1
---

# Atlas Vault Cartography Schema

This index points to the focused specs for the Atlas Vault Cartography.
Read it before changing cartography, AtlasVault graph parsing, repo/vault
source authority, or `graph_*` visual frontmatter.

## Canon

The cartography is a reader, navigator and auditor over two independent
canonical sources:

| Source | Authority |
|---|---|
| Repo docs | Architecture, contracts, operations, Kernel, Runtime, policies and auditable blockers |
| AtlasVault | Human memory, books, philosophy, marginalia, stories, hypotheses and reflective writing |

The Atlas App Cartography is never a source of truth. It renders real files,
shows their origin and path, and fails closed when a file is missing.

## Split Specs

| File | Purpose |
|---|---|
| `atlas-vault-cartography-schema-contracts.md` | Source authority, semantic fields, `graph_*` visual fields, examples, compatibility and anti-canon rules |
| `atlas-vault-cartography-schema-runbook.md` | Reader/watcher model, live documentation flow, migration phases, validation tooling and deferred questions |

## Non-Negotiables

- Do not mirror repo docs into AtlasVault.
- Do not treat AI narration as source truth; the human audits the real file.
- Do not use `graph_source: vault` for canonical architectural pipelines,
  lanes, steps or components.
- Do not introduce fields outside `graph_*` for visual concerns.
- Do not render missing files as truth; use `missing_source`.

## Validation

After changing cartography specs:

```bash
atlas engineering knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --json
```
