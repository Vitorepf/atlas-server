---
id: atlas-ai-cyber-recipes-external-mcp
type: engineering_knowledge
title: Atlas AI Cyber External MCP Tooling
status: scaffold
category: knowledge-base
priority: 80
summary: Governance for integrating offensive external MCP tooling through Atlas wrappers instead of raw provider configuration.
tags:
  - atlas-ai
  - cyber-security
  - mcp
capabilities:
  - cyber_recipes_proposal
decisions:
  - External offensive MCP servers are never connected directly to Atlas Decide.
  - A wrapper skill must enforce Decision Receipt, scope proof, refusal matrix, sandbox and evidence.
maintenance:
  - Add a section for each external MCP candidate after security review.
related_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
  - docs/engineering-knowledge-base/archive/source-material/cyber-security/recipes-catalog-full-2026-05-08.md
---

# Atlas AI Cyber External MCP Tooling

## Rule

External MCP servers with offensive capability are integrated as wrapped recipes,
not as raw `mcpServers` entries exposed to Atlas Decide or providers.

## Candidate: HexStrike MCP

| Field | Value |
|---|---|
| Tool slug | `hexstrike-mcp` |
| Recipe | `pentest-orchestrate` |
| Integration | MCP server through wrapper skill |
| Required sandbox | Dedicated VM |
| Execution tier | T2 |
| Approval | Extra approval always required |
| Skill wrapper | `cyber-hexstrike-runner` |

Constraints:

- apply Decision Receipt before every call;
- enforce `scope.in`;
- apply refusal matrix;
- write Evidence Ledger events;
- rate-limit MCP calls;
- kill-switch if the tool leaves declared scope;
- no reuse of VM between engagements.

## General External MCP Pattern

1. Create wrapper skill `cyber-<tool>-runner`.
2. Register external recipe with `integration: mcp_server`.
3. Require VM sandbox.
4. Record ADR in cyber extension docs.
5. Enforce receipt, scope proof, refusal matrix and evidence.
6. Limit simultaneous MCP connections.

## Anti-Pattern

Never paste an offensive MCP server directly into a provider config and let it
act as a normal tool. That bypasses Atlas governance.
