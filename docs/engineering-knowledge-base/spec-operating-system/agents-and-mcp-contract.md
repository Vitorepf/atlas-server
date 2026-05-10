---
id: atlas-ai-spec-operating-system-agents-and-mcp-contract
type: engineering_knowledge
title: Atlas Spec Operating System Agents And MCP Contract
status: active
category: architecture
priority: 99
summary: Canonical internal agent roles and MCP exposure rules for Atlas SDD.
tags:
  - atlas-ai
  - sdd
  - agents
  - mcp
capabilities:
  - spec_operating_system
  - agent_orchestration
  - open_brain
decisions:
  - SDD uses specialized agents, but Kernel and receipts remain the authority.
  - MCP may expose context, prompts and tools only through governed Atlas surfaces.
  - Raw MCP write access is forbidden without dedicated policy and human gate.
maintenance:
  - Update before exposing SDD over Open Brain/MCP or adding SDD agents.
  - Keep aligned with Memory/Open Brain MCP docs and Tool Runtime contracts.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md
owner: atlas-ai
layer: 0.7-and-programming
line_limit: 220
---

# Atlas Spec Operating System Agents And MCP Contract

Atlas SDD is not a single prompt. It is an orchestrated set of specialists whose
outputs become governed artifacts.

## Internal Agents

| Agent | Role |
|---|---|
| Context Scout | Finds relevant files, docs, specs, decisions, routes, tests and history. |
| Product Analyst | Interprets user intent as product/business outcome. |
| Business Rule Miner | Extracts confirmed rules, constraints and hypotheses. |
| Spec Compiler | Creates operational spec, requirements and acceptance criteria. |
| Spec Critic | Attacks ambiguity, missing rules, design conflicts and risk. |
| Architecture Agent | Chooses approach compatible with existing stack and patterns. |
| Plan Compiler | Converts spec into technical implementation plan. |
| Task Compiler | Produces small, ordered, traceable tasks. |
| Execution Agent | Implements only what the Decision Receipt allows. |
| QA Agent | Generates/runs tests and validates acceptance criteria. |
| Security Agent | Checks auth, permissions, sensitive data and abuse cases. |
| Drift Detector | Compares spec, code, tests, docs and receipt boundaries. |
| Evidence Agent | Appends proof for diffs, tests, gates and traceability. |
| Learning Curator | Proposes template, policy or context improvements. |

## Orchestration Pattern

Use orchestrator-worker for complex changes:

```text
Research/Context agents run first
-> Spec Compiler produces artifact
-> Critic/Security/Architecture review in parallel where safe
-> Plan/Task compilers generate executable work
-> Kernel signs receipt
-> Execution/QA operate inside receipt
-> Evidence/Drift/Learning close the loop
```

Agents may disagree. Disagreement must be represented as an assumption,
blocking question, risk note or alternative plan. It must not be silently merged
into a confident answer.

## MCP Resources

Atlas SDD may expose provider-safe resources:

```text
- project code context
- existing specs
- canonical documentation
- design system rules
- database schema summaries
- API contracts
- logs and telemetry summaries
- issues and PR summaries
- evidence events
- Decision Receipt summaries
```

Resources are read models. They do not authorize execution.

## MCP Prompts

Atlas SDD may expose governed prompt workflows:

```text
- generate_spec
- critique_spec
- create_plan
- create_tasks
- review_security
- validate_drift
- summarize_evidence
- propose_learning
```

Prompts produce candidate artifacts. Kernel, receipts and gates decide whether
the artifact can affect runtime.

## MCP Tools

Allowed future tools must be narrow and auditable:

```text
- read_file_context
- search_code_context
- run_allowed_test
- create_sdd_report
- inspect_traceability
- inspect_drift
- open_proposal
```

Write-capable tools such as patch application, PR creation, database query with
sensitive data, Figma mutation or external repo write require a dedicated
Decision Receipt and policy.

## MCP Safety Rules

- MCP is a surface, not authority.
- MCP context must be provider-safe and audited.
- MCP write tools remain blocked until a dedicated AP, receipt model and human
  gate exist.
- Secrets never enter model context.
- Tool outputs must be validated before becoming spec evidence.
- External MCP servers must be wrapped by Atlas Tool Runtime; never connected
  raw to Atlas Decide.
