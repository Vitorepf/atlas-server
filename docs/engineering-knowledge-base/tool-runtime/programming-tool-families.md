---
id: atlas-programming-tool-families
type: engineering_knowledge
title: Atlas Programming Tool Families
status: active
category: tool_runtime
priority: 86
summary: Focused family map for programming tools governed by the Atlas Super Tool Runtime.
tags:
  - atlas
  - tools
  - programming
capabilities:
  - programming_power_tools_catalog
  - tool_authority_matrix
decisions:
  - Tool families define authority and complementarity; they do not bypass the registry.
maintenance:
  - Update when a tool family gains or loses primary authority.
related_paths:
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
---

# Atlas Programming Tool Families

## Families

| Family | Representative tools |
|---|---|
| Code and context | `atlas_code_intelligence`, `ripgrep`, `tree_sitter`, `ast_grep`, `serena`, `universal_ctags` |
| PHP quality/types/refactor | `composer`, `laravel_pint`, `phpstan`, `psalm`, `phpmd`, `phpcpd`, `composer_require_checker`, `composer_unused`, `rector` |
| TypeScript/frontend/dead code | `typescript`, `eslint`, `biome`, `knip`, `ts_prune` |
| Security/supply chain/licenses | `gitleaks`, `semgrep`, `codeql`, `osv_scanner`, `trivy`, `grype`, `syft`, license scanners |
| Containers/IaC/Kubernetes | `hadolint`, `checkov`, `trivy`, Kubernetes scanners |
| APIs/contracts/mocking | OpenAPI validators, Schemathesis, contract test tools |
| Visual/accessibility/performance | visual smoke, Playwright, axe-core, Lighthouse, Pa11y |
| Architecture | dependency-cruiser, Madge, Deptrac |
| External agents | Aider, Continue, OpenHands, Claude Code, Codex CLI, Cursor |

## Authority Pattern

Each family must declare:

- primary tool;
- complementary/fallback tools;
- output normalizer;
- evidence shape;
- gate thresholds;
- duplicate suppression rule.

## Registry Rule

This doc is not the registry. Runtime truth lives in `atlas_tool_definitions` and
the Tool Runtime services.
