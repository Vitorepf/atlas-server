---
id: atlas-ai-os-governance-and-dod
type: engineering_knowledge
title: Atlas AI OS - Governance And DoD
status: active
category: architecture
priority: 99
summary: Horizontal layers, anti-duplication rules, ownership model and Definition of Done for Atlas AI flows.
tags:
  - atlas-ai
  - governance
  - dod
capabilities:
  - anti_duplication_governance
  - unified_capability_pipeline
decisions:
  - Horizontal capabilities belong to Core when useful across surfaces/domains.
  - Mature flows have owner, policy, gates, evidence, repair and docs.
maintenance:
  - Update when ownership or DoD rules change.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
---

# Atlas AI OS - Governance And DoD

## Horizontal Layers

- Intent;
- Context;
- Policy;
- Decide;
- Tools;
- Memory;
- Validation;
- Repair;
- Evidence;
- Evolution.

If a capability is useful to more than one surface/domain, move it to Core or a
shared runtime before expanding behavior.

## Anti-Duplication Rules

1. Multi-surface features belong to Core.
2. Provider/model/permission/gate decisions pass through Policy/Decide.
3. State-changing tasks need evidence.
4. Code work enters Programming.
5. Sensitive memory passes privacy/provider-safety.
6. Local tools enter Tool Runtime when registry/normalizer applies.
7. Forge gates relevant to Dev must be shared or explicitly justified.
8. Aliases must not implement their own logic when canonical flow exists.

## Ownership

| Layer | Owner |
|---|---|
| Domain/intent | Atlas AI Core |
| Provider/model/policy | Atlas Decide + policy profiles |
| Prompt/context | Context assembly + Open Brain |
| Programming | Atlas AI Programming |
| Engineering harness | Engineering Harness |
| Memory | Memory Core / Open Brain |
| Tools | Super Tool Runtime |
| Evidence | Domain evidence packet |
| Documentation | Engineering Knowledge Base |

## Flow Definition Of Done

A mature Atlas AI flow has:

- clear domain and owner;
- canonical pipeline;
- governed context and memory;
- policy profile;
- executor;
- gates;
- repair/escalation;
- evidence packet;
- learning when applicable;
- canonical docs;
- tests preventing duplicated flow regression.
