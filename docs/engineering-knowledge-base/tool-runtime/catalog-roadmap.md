---
id: atlas-tool-runtime-catalog-roadmap
type: engineering_knowledge
title: Atlas Tool Runtime Catalog Roadmap
status: active
category: tool_runtime
priority: 97
summary: Tool families Atlas should govern for heavy programming, security, frontend, API, architecture, testing and external agents.
tags:
  - atlas
  - tools
  - programming
  - roadmap
capabilities:
  - programming_power_tools_catalog
  - tool_registry
decisions:
  - Tool backlog is optional/local/open-source first when possible.
  - Tool families enter as governed capabilities, not parallel flows.
maintenance:
  - Promote a tool only with recipe, policy and evidence semantics.
related_paths:
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
---

# Atlas Tool Runtime Catalog Roadmap

## P0 Code Semantics

- Serena/LSP/MCP for symbols, references and semantic edits;
- Tree-sitter graph for local AST/code graph;
- ast-grep for structural search/refactor;
- universal-ctags for lightweight symbol index;
- IDE bridges for VS Code/JetBrains when useful.

Reads are cheap. Writes require sandbox/approval and evidence.

## P0 Quality And Static Analysis

- PHPStan/Psalm/Psalm taint;
- Pint/Biome/Prettier;
- ESLint/TypeScript;
- Rector;
- PHPMD/PHPCPD;
- Composer Require Checker/Unused;
- Knip/ts-prune.

## P0 Security And Supply Chain

- Gitleaks;
- Semgrep;
- CodeQL;
- OSV-Scanner;
- Trivy/Grype;
- Syft;
- Checkov/Terrascan;
- kube-linter/kube-score;
- Dockle;
- ScanCode/ORT/licensee.

## P1 API, Testing, Frontend And Architecture

- Schemathesis, Pact, Prism, WireMock, Bruno;
- Infection/Stryker, fast-check/Hypothesis, coverage tools;
- axe-core, Pa11y, Lighthouse CI, bundle analyzers, pixelmatch;
- Deptrac, dependency-cruiser, Madge;
- OpenRewrite, comby, jscodeshift, ts-morph.

## P2 External Agents

- Aider;
- Continue;
- OpenHands;
- Serena/MCP write operations.

Agents must run behind Atlas context, policy, worktree/sandbox, tests, gates,
Evidence Store and operator approval where needed.

## Promotion Rule

A cataloged tool becomes operational only after:

- registry definition;
- doctor detection state;
- safe recipe when executable;
- normalizer or explicit generic fallback;
- authority group role;
- gate behavior;
- tests with fake binary or fixture output.
