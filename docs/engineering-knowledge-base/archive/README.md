---
id: engineering-kb-archive
title: Engineering KB Archive
status: archived
category: documentation-governance
summary: Registry and operating rules for preserved legacy Atlas architecture material that must not compete with canonical architecture docs.
human_name: Engineering KB Archive
canonical_name: Engineering KB Archive
technical_name: engineering-kb-archive
cartography_type: registry
canonical_source: docs/engineering-knowledge-base/archive/README.md
last_reviewed: 2026-05-05
---

# Engineering KB Archive

This directory and registry preserve historical Atlas architecture material
without making it operational authority.

## Rule

Canonical docs in `docs/engineering-knowledge-base/` are the source of truth.
Archived, superseded, projection, quarantine and human-vault files may be read
as source material, but they do not override Kernel, Master Architecture,
Operating System, Domain Specs, Memory Core, Tool Runtime or CLI product docs.

## Status Families

| Status | Meaning | Required canonical reference |
|---|---|---|
| `superseded_source_material` | Useful historical content already synthesized elsewhere. | The current canonical doc family. |
| `archived` | Old plan, prompt, report or version kept for traceability. | The current doc or runbook that replaced it. |
| `archived_quarantine` | Temporary or delete-candidate material kept until a later explicit cleanup. | A safe replacement and cleanup report entry. |
| `archived_projection` | Provider/bootstrap projection, not source of truth. | `START_HERE.md`, `README.md` and KB canonical docs. |
| `human_vault_only` | Human/personal knowledge surface material, privacy-sensitive or non-operational. | Obsidian/AtlasVault, domain policy and privacy docs. |

## Operating Rules

- Do not delete preserved docs without updating
  `legacy-documentation-cleanup-report.md`.
- Do not move large legacy docs unless the old path keeps a clear redirect or
  the cleanup report records the move.
- Every legacy header must include `Cleanup status`, `Canonical replacement`
  and `Cleanup note`.
- If a legacy file contains a useful idea not represented canonically, promote
  a short synthesis into the correct canonical doc or into
  `atlas-ai-governed-backlog.md`; do not create a parallel doctrine.
- Provider bootstrap files are projections and must never become operational
  authority.
- AtlasVault/Obsidian remains Human Knowledge Surface / Personal Knowledge
  Workspace, not primary operational source.

## Current Registry

The authoritative inventory is
`docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md`.
The resolver-specific audit is
`docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md`.

Physical archive content may live in this directory or remain in original paths
with cleanup headers. Preserving original paths is preferred when moving would
break historical links or obscure useful source context.

## Canonical Families

| Source family | Canonical authority |
|---|---|
| Layer 0, glossary, constitution excerpts | `atlas-ai-layer-0-glossary.md`, `atlas-ai-canonical-architecture-index.md` |
| Kernel, receipts, ledger, failure domains | `atlas-ai-kernel-architecture.md`, `kernel/failure-domain-taxonomy.md` |
| Master architecture and operating topology | `atlas-ai-master-architecture.md`, `atlas-ai-operating-system.md`, `atlas-ai-pipeline.md`, `atlas-ai-core-vs-domain.md` |
| Domain specs | `domains/README.md` and the specific domain doc |
| Memory, Open Brain and continuity | `atlas-ai-memory-context-core-open-brain.md`, `memory-core-contracts.md`, `open-brain-context-injection.md`, `atlas-ai-continuity-session-state.md` |
| Tool runtime, programming tools and harness | `engineering-blueprint*.md`, `super-tool-runtime-core.md`, `programming-power-tools-catalog.md`, `atlas-ai-runtime-packets.md` |
| Telemetry, performance and evidence | `atlas-ai-telemetry-evidence-performance.md`, `atlas-ai-kernel-architecture.md` |
| Mobile, local agents and surfaces | `atlas-ai-mobile-surface-gateway.md`, `atlas-local-agent-surface.md`, `atlas-ai-operating-system.md` |
| CLI product and multimodal input | `docs/atlas-cli-final-product.md`, `docs/atlas-cli-5x-claude-code-plan.md`, `atlas-ai-cli-multimodal.md` |
| Legacy backlog and raw ideas | `atlas-ai-governed-backlog.md`, never provider context directly |

## Quarantine Register

These files are preserved and explicitly not deleted in this cleanup wave.
Future deletion requires link audit, `git log --follow`, human approval and a
cleanup report update.

No preserved file should carry a delete-candidate status as an immediate action.
Use `archived_quarantine` until a separate delete review proves the file is no
longer referenced and no longer useful for implementation history.

| Path | Status | Replacement | Delete gate |
|---|---|---|---|
| `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md` | `archived_quarantine` | `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md`, `docs/paste-image-setup.md`, `atlas-ai-cli-multimodal.md` | Only after links are clean and implementation history is no longer needed. |
| `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Bootstrap_Setup.md` | `archived_quarantine` | `docs/atlas-cli-final-product.md`, `docs/atlas-cli-release-checklist.md` | Only after scheduler/bootstrap commands are verified elsewhere. |
