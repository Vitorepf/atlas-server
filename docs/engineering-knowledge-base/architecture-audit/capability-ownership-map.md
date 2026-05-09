---
id: atlas-ai-architecture-audit-capability-ownership-map
type: engineering_knowledge
title: Atlas AI Architecture Audit Capability Ownership Map
status: active
category: architecture
priority: 87
summary: Ownership map for capabilities identified by the architecture audit, preventing duplication across surfaces and domains.
tags:
  - atlas-ai
  - architecture
  - ownership
capabilities:
  - flow_consolidation
  - anti_duplication_governance
decisions:
  - Every repeated capability must have one owner and be consumed by surfaces/domains.
maintenance:
  - Update when a capability owner changes in the canonical architecture index.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
---

# Atlas AI Architecture Audit Capability Ownership Map

| Capability | Canonical owner | Notes |
|---|---|---|
| Domain/intent resolution | Atlas AI Core | Classifies domain, risk and task type. |
| Provider/model/policy choice | Atlas Decide | Emits receipt; does not execute. |
| Policy profiles | Policy/Profile layer | Merges global, domain, flow, surface, risk and session overrides. |
| Domain/flow profiles | Profile resolver | Separates vertical domain from executable flow. |
| Base context | Context Builder | Task, conversation, workspace and policy context. |
| Memory/Open Brain | Memory Context Core | Provider-safe knowledge, memory quality and code refs. |
| Engineering context | Programming domain | Deep repo/task context for programming flows. |
| Programming | Programming domain | Unifies `dev`, `forge`, `fix`, `continue`, app and workers. |
| Heavy harness | Engineering Harness | Runtime intensity, not separate product. |
| Tools | Super Tool Runtime | Registry, planner, policy, executor, normalizer and evidence. |
| Programming gates | Programming Quality Matrix | Composes basic, tool, blueprint and release gates. |
| Repair | Domain repair loop | Uses failure taxonomy and repair capsule. |
| Evidence packet | Evidence layer | Domain-specific final packet and ledger events. |
| Telemetry | Telemetry/read models | Quality, cost, latency, repair, provider performance. |
| Evolution | Self-Improvement/Curator | Detects gaps, drift and duplicate behavior. |

## Placement Rule

If more than one surface needs it, it is not surface-owned. If more than one
domain needs it, it is Core or Runtime. If it executes external commands, it is
Runtime or Tool Runtime.
