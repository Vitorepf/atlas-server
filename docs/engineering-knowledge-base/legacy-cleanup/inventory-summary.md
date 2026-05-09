---
id: legacy-cleanup-inventory-summary
type: engineering_knowledge
title: Legacy Cleanup Inventory Summary
status: active
category: documentation-governance
priority: 87
summary: Compact inventory model for legacy Atlas documentation classes, risks and current handling.
tags:
  - atlas
  - documentation
  - inventory
capabilities:
  - legacy_documentation_inventory
decisions:
  - Inventory classes describe handling, not automatic deletion.
  - Human vault material can be high-value while still being non-operational source.
maintenance:
  - Update when a cleanup class changes or a new legacy family is discovered.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
  - docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
---

# Legacy Cleanup Inventory Summary

## Classes

| Class | Meaning | Default action |
|---|---|---|
| `keep_canonical` | Already active authority or official support doc. | Keep and link from index/README if missing. |
| `promote_to_kb` | Contains stable decision not yet canonical. | Create small KB doc or patch owner doc. |
| `merge_into_existing` | Valuable but duplicates an owner doc. | Merge only missing decisions, then redirect. |
| `archive_with_redirect` | Historical plan, prompt, report or old spec. | Preserve with canonical replacement. |
| `archived_quarantine` | Possible delete candidate. | Keep until explicit delete review. |
| `archived_projection` | Provider/agent projection. | Preserve as historical, never source of truth. |
| `human_vault_only` | Personal, constitutional, research or sensitive note. | Keep in human surface; promote only reviewed excerpts. |

## Current Families

| Family | Handling |
|---|---|
| Canonical KB docs | Keep under `docs/engineering-knowledge-base`. |
| Deprecated KB stubs | Keep with redirect; do not expand. |
| `docs/` operational runbooks | Keep if actively useful; point to KB owner. |
| `resolver-o-que-vale-a-pena` | Historical governed corpus; not direct authority. |
| Provider bootstrap files | Archived projections; useful for history only. |
| Superpower plans/specs | Preserve as source material or redirect to canonical AP/doc. |
| AtlasVault/Obsidian notes | Human Knowledge Surface, curated sync only. |

## Decision Criteria

| Question | If yes |
|---|---|
| Is it listed by README, START_HERE or canonical index? | `keep_canonical` |
| Does it contain a decision without canonical equivalent? | `promote_to_kb` |
| Does it duplicate an owner doc but add useful detail? | `merge_into_existing` |
| Is it a prompt, plan, old report or completed spec? | `archive_with_redirect` |
| Is it personal, sensitive or research-heavy? | `human_vault_only` |
| Does it appear obsolete and unreferenced? | `archived_quarantine`, never immediate delete |

## Risk Rules

- `human_vault_only` does not mean low value.
- `archived_quarantine` does not mean delete approved.
- `promote_to_kb` means synthesize the decision; do not paste the full source.
- Any class change must preserve enough traceability for future audits.
