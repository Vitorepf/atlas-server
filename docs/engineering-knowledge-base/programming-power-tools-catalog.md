---
id: atlas-programming-power-tools-catalog
type: engineering_knowledge
title: Atlas Programming Power Tools Catalog
status: active
category: tool_runtime
priority: 97
summary: Compact product index for programming power tools, authority groups, tiers, external agent boundaries and implementation backlog.
tags:
  - atlas
  - tools
  - programming
  - quality
  - security
capabilities:
  - programming_power_tools_catalog
  - tool_authority_matrix
  - t0_t3_execution_policy
  - anti_duplication_policy
  - external_agent_boundary
  - local_open_source_first
decisions:
  - Atlas is the control plane; external tools are sensors, validators or governed executors.
  - Paid/cloud tools are never mandatory core dependencies.
  - Tool overlap is allowed only with declared authority groups.
  - Detailed family catalog and backlog live in focused child docs.
maintenance:
  - Run `atlas tools authority --json` after changing tiers or authority.
  - Run docs sync and code index after changing this doc.
related_paths:
  - docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md
  - docs/engineering-knowledge-base/tool-runtime/programming-tool-backlog.md
  - docs/engineering-knowledge-base/archive/source-material/tool-runtime/programming-power-tools-catalog-full-2026-05-08.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/tool-runtime/README.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - app/Services/Tools/AtlasToolDefinitionCatalog.php
  - app/Services/Tools/AtlasToolAuthorityMatrixService.php
---

# Atlas Programming Power Tools Catalog

This is the active compact index for programming power tools. The full original
catalog is preserved at
`archive/source-material/tool-runtime/programming-power-tools-catalog-full-2026-05-08.md`.

## Current State

The Super Tool Runtime already provides registry, policy engine, executor,
generic and specific normalizers, Evidence Store, approvals, waivers, evidence
gates, release gates, command recipes, filters, authority matrix and Engineering
app panel.

The first P0 package includes Gitleaks, Semgrep, OSV-Scanner, Syft, Trivy,
PHPStan, TypeScript, ESLint, Laravel Pint, Biome, Hadolint and Checkov.

## Tiers

| Tier | Intent | Examples | Rule |
|---|---|---|---|
| T0 | Instant context/edit feedback | ripgrep, Code Intelligence, Tree-sitter, ast-grep | Interactive, local, no automatic write. |
| T1 | Fast local feedback | typecheck, lint, PHPStan, Gitleaks, Knip | Save/pre-commit/light quality. |
| T2 | Medium review | CodeQL, Semgrep, Schemathesis, visual smoke, axe | PR/task gate with timeout and evidence. |
| T3 | Heavy release/audit | Infection, Stryker, Lighthouse full, SBOM/release scans | Nightly/release/deep only. |

## Authority Rule

No gate runs tools directly. Gates evaluate persisted evidence filtered by
workspace, surface, context, tool, recipe and tier.

## Anti-Duplication Examples

| Group | Primary | Complement |
|---|---|---|
| `semantic_sast` | CodeQL | Semgrep |
| `ts_js_architecture` | dependency-cruiser | Madge |
| `php_static_analysis` | PHPStan | Psalm |
| `vulnerability_scan` | Trivy | Grype |
| `sbom` | Syft | n/a |
| `accessibility` | axe-core | Pa11y |

Full family map: `tool-runtime/programming-tool-families.md`.

## External Agents

Aider, Continue, OpenHands, Claude Code, Codex CLI, Cursor and future agents may
execute under Atlas policy, approval, evidence and gates. They do not own
context, memory, authority or quality.

## Implementation Backlog

See `tool-runtime/programming-tool-backlog.md`.

## Product Final Criteria

- The tool is registered.
- The recipe is safe and dry-run capable where relevant.
- Results are normalized.
- Evidence is persisted.
- Gates consume evidence by authority group.
- App/CLI exposes findings and repair hints.
- Self-Improvement can detect stale or missing tooling.
