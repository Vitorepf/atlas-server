---
id: atlas-programming-tool-backlog
type: engineering_knowledge
title: Atlas Programming Tool Backlog
status: active
category: tool_runtime
priority: 85
summary: Implementation backlog for making programming tools fully usable in Atlas automation.
tags:
  - atlas
  - tools
  - backlog
capabilities:
  - programming_power_tools_catalog
decisions:
  - Tool backlog is promoted through recipes, normalizers, gates and UX, never by ad hoc command execution.
maintenance:
  - Update after a tool recipe, normalizer or gate becomes implemented.
related_paths:
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
---

# Atlas Programming Tool Backlog

## P0 - Real Cheap Recipes

Priority is local, cheap, high-signal recipes that improve programming feedback:

- lint/typecheck recipes;
- secret/dependency scan recipes;
- SBOM/release recipes;
- Docker/IaC recipes;
- fast code search and context recipes.

## P1 - Specific Normalizers

Promote generic output into structured findings for high-volume tools:

- severity;
- file/line;
- fingerprint;
- rule id;
- authority group;
- repair hint;
- artifact refs.

## P2 - Gates By Authority

Gates should use the primary tool per authority group and suppress duplicate
findings from complementary tools when appropriate.

## P3 - Operational UX

App/CLI should expose:

- findings by authority group;
- stale tool evidence;
- missing tool evidence;
- repair hints;
- waiver workflow;
- links from evidence to code and docs.

## Maintenance Commands

```bash
atlas tools authority --json
atlas tools commands <tool> --json
atlas tools evidence --recipe=<recipe>
atlas tools doctor --workspace=/Users/vitorepf/develop/Atlas/atlas-server
```
