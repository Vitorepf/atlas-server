---
id: legacy-cleanup-executed-promotions
type: engineering_knowledge
title: Legacy Cleanup Executed Promotions
status: active
category: documentation-governance
priority: 86
summary: Compact record of major Atlas legacy documentation promotions, redirects and preserved source materials.
tags:
  - atlas
  - documentation
  - promotions
capabilities:
  - source_material_promotion
  - documentation_archive_governance
decisions:
  - Executed promotions are historical record; active authority lives in their destination docs.
  - Future sessions should not re-promote the same source without diffing against the destination.
maintenance:
  - Add only wave-level summaries, not full matrices.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
  - docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
---

# Legacy Cleanup Executed Promotions

## 2026-05-05 Promotions

| Source theme | Destination | Result |
|---|---|---|
| Atlas identity, glossary and master prompt | `atlas-ai-layer-0-glossary.md` | Layer 0 identity promoted in compact form. |
| Sessions, compaction and continuity | `atlas-ai-continuity-session-state.md` | Session continuity contract promoted. |
| Telemetry, evidence and efficiency | `atlas-ai-telemetry-evidence-performance.md` | Evidence/performance authority consolidated. |
| Paste image and CLI multimodal | `atlas-ai-cli-multimodal.md` | Multimodal capability moved out of single surface thinking. |
| Mobile gateway, push and inbox | `atlas-ai-mobile-surface-gateway.md` | Mobile surface authority documented. |
| Runtime packets | `atlas-ai-runtime-packets.md` | Packet model promoted. |
| Skill system | `atlas-ai-skill-system.md` | Skill authority documented. |
| Gaps and backlog | `atlas-ai-governed-backlog.md` | Future gaps moved to governed backlog. |
| Mac local agent | `atlas-local-agent-surface.md` | Local surface promoted. |

## Already Canonical Or Preserved

| Family | Handling |
|---|---|
| Engineering Blueprint | Canonical docs under `engineering-blueprint*.md`. |
| Memory/Open Brain | Canonical docs under memory and Open Brain docs. |
| Super Tool Runtime | Canonical docs under `super-tool-runtime-core.md` and `tool-runtime/`. |
| Finance and Personal Development | Domain specs live under `domains/`; source material remains historical. |

## Redirect Principle

If a legacy source was promoted, the destination doc is the authority. The old
source can remain useful for historical nuance, but it must not be used by an AI
to create a parallel flow.

## Re-Promotion Rule

Before promoting a source again:

1. read the destination doc;
2. identify the exact missing decision;
3. patch the owner doc;
4. preserve source link;
5. run docs-health and architecture validation.
