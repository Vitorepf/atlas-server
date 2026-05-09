---
id: atlas-ai-architecture-audit-canonical-findings
type: engineering_knowledge
title: Atlas AI Architecture Audit Canonical Findings
status: active
category: architecture
priority: 87
summary: Consolidated architecture audit findings about existing truths, duplicate flows and required consolidation rules.
tags:
  - atlas-ai
  - architecture
  - findings
capabilities:
  - architecture_audit
  - anti_duplication_governance
decisions:
  - Atlas value comes from orchestration, memory, gates, evidence and learning above providers.
  - Surface-specific business logic is the root cause of duplicated behavior.
maintenance:
  - Update when a finding is closed by architecture validation or implementation.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
---

# Atlas AI Architecture Audit Canonical Findings

## Canonical Truths

| Truth | Implication |
|---|---|
| Atlas is the surface; providers are replaceable engines. | Provider adapters cannot own flow. |
| Memory belongs to Atlas, not providers. | Important flows must request context through Atlas. |
| Context Pack is an artifact, not improvised prompt text. | Context must be small, traceable, provider-safe and hashable. |
| Engineering needs contract before code. | Medium/hard programming tasks need task contracts and gates. |
| Tools must be governed and evidenced. | Tool Runtime owns registry, policy, executor, normalizer and evidence. |
| 5x over direct Claude Code comes from harness, not model worship. | Context, gates, repair, replay and final packets are the multiplier. |
| Profile is not a model preset. | Domain/flow profile resolves before provider/model. |
| Decide emits receipt; it does not execute. | Domain orchestrator and runtime execute under the receipt. |

## Disorder Observed

| Disorder | Correction |
|---|---|
| Surface became flow. | Surface adapters collect input and call `atlas.run`. |
| Multiple context concepts compete. | Base context, Open Brain and Engineering Context need formal boundaries. |
| Gates exist at several levels. | Domain quality matrix chooses gates by task type and risk. |
| Repair has multiple semantics. | Use one failure taxonomy and repair capsule per domain. |
| Decide can drift into execution. | Decision Receipt is output; runtime executes. |
| Rich docs lacked consolidation map. | Canonical index and Documentation OS govern authority. |

## Current Closure Mechanisms

- Surface Adapter contracts.
- Decision Receipt propagation.
- Architecture validation static scans.
- Documentation health and split plan.
- Domain profile registry.
- Capability registry and surface coverage tests.
- Evidence Ledger and read models.

## Remaining Audit Discipline

When a new duplicated flow appears, do not patch the duplicate in place. Identify
the shared owner and promote the capability to Core, Domain, Runtime or Tool
Runtime.
