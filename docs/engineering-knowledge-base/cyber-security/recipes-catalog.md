---
id: atlas-ai-cyber-recipes-catalog
type: engineering_knowledge
title: Atlas AI Cyber Recipes Catalog
status: scaffold
category: knowledge-base
priority: 81
summary: Compact index for proposed cyber-security recipes that may extend the canonical Super Tool Runtime without creating a parallel security runtime.
tags:
  - atlas-ai
  - cyber-security
  - recipes
  - super-tool-runtime
  - tools
capabilities:
  - cyber_recipes_proposal
decisions:
  - Cyber recipes extend the canonical Super Tool Runtime via registry entries; they never create a parallel executor.
  - Offensive recipes are dry-run by default, sandboxed, evidence-producing and scoped by policy.
  - Existing defensive tools must be reused instead of recreated.
maintenance:
  - Add new recipe families to focused child docs, not to this index.
  - Promote a recipe only through migration/registry entry, wrapper, sandbox profile, tests and evidence gate.
related_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-existing-tools.md
  - docs/engineering-knowledge-base/cyber-security/recipes-offensive-families.md
  - docs/engineering-knowledge-base/cyber-security/recipes-external-mcp.md
  - docs/engineering-knowledge-base/cyber-security/recipes-promotion-runbook.md
  - docs/engineering-knowledge-base/archive/source-material/cyber-security/recipes-catalog-full-2026-05-08.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
owner: atlas-ai
layer: extension
line_limit: 240
---

# Atlas AI Cyber Recipes Catalog

This is the active index for cyber-security recipes proposed for the Atlas Super
Tool Runtime. The full original YAML catalog is preserved at
`archive/source-material/cyber-security/recipes-catalog-full-2026-05-08.md`.

## Authority

| Subject | Authority |
|---|---|
| Tool execution | `super-tool-runtime-core.md` |
| Tool authority and anti-duplication | `programming-power-tools-catalog.md` |
| Cyber refusal and safety | `cyber-security/refusal-matrix.md` |
| Proposed cyber recipe families | this doc and its children |
| Historical full argv examples | archived full source material |

## Read Order

| Need | Read |
|---|---|
| Existing tools to reuse | `recipes-existing-tools.md` |
| Proposed offensive families | `recipes-offensive-families.md` |
| External MCP tooling | `recipes-external-mcp.md` |
| How to promote a recipe | `recipes-promotion-runbook.md` |
| Exact historical YAML | `archive/source-material/cyber-security/recipes-catalog-full-2026-05-08.md` |

## Required Recipe Contract

Every promoted cyber recipe must declare:

- `tool_slug`
- `recipe_name`
- `category`
- `argv` schema
- `dry_run_default`
- `creates_evidence`
- `blocking_capable`
- `execution_tier`
- `sandbox`
- `privacy_level`
- `task_type`
- `authority_group`
- approvals when active exploitation, C2, distributed scan or external MCP is involved

## Hard Safety Rules

- Defensive tools already in the registry are reused, not re-registered.
- Offensive recipes default to `dry_run_default: true`.
- Active exploit, C2, distributed scan and external offensive MCP require extra approval.
- External MCP servers are wrapped by Atlas skills; they are never connected raw to Atlas Decide.
- Every run emits evidence through the canonical Evidence Ledger path.
- Scope proof and refusal matrix run before execution.

## Families

| Family | Child doc |
|---|---|
| Existing defensive tools | `recipes-existing-tools.md` |
| Recon, Web/API, Mobile, Cloud, C2, Network/AD, AI/ML and supply chain | `recipes-offensive-families.md` |
| HexStrike and future MCP offensive tooling | `recipes-external-mcp.md` |
| Promotion process | `recipes-promotion-runbook.md` |

## Anti-Patterns

- Recreating `gitleaks`, `semgrep`, `trivy`, `osv-scanner`, `syft` or `checkov`.
- Adding a cyber tool outside the Super Tool Runtime registry.
- Running offensive recipes without sandbox, scope proof or audit evidence.
- Treating an external MCP server as a trusted executor.
- Letting a cyber skill bypass Atlas Decide, Policy/Profile or Decision Receipt.
