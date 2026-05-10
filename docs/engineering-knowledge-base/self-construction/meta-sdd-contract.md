---
id: atlas-ai-self-construction-meta-sdd-contract
type: engineering_knowledge
title: Atlas Self-Construction Meta-SDD Contract
status: active
category: architecture
priority: 100
summary: SDD rules for changing Atlas itself.
tags:
  - atlas-ai
  - self-construction
  - meta-sdd
capabilities:
  - meta_sdd
  - spec_operating_system
decisions:
  - Atlas core changes require Meta-SDD, not ordinary feature specs.
  - Meta-SDD must include layer impact, maturity delta and system risk.
maintenance:
  - Update when SDD runtime or Atlas core construction workflow changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/self-construction/build-graph.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Meta-SDD Contract

Meta-SDD is the SDD of Atlas itself. It is stricter than normal SDD because the
system being changed is the system that decides future changes.

## Required Meta-Spec Fields

```yaml
meta_spec:
  id:
  title:
  target_layer:
  target_capability:
  current_maturity:
  target_maturity:
  problem:
  goal:
  non_goals:
  dependencies:
  affected_authority_docs:
  affected_runtime_components:
  risk_level:
  autonomy_allowed:
  rollback_strategy:
  evidence_required:
```

## Layer Classification

Every Meta-SDD must classify the target:

```text
L0 documentation/governance
L1 Kernel/Decision/Evidence
L2 Memory/Cognitive Runtime
L3 Research/Self-Improvement
L4 SDD/Programming Harness
L5 Product Surface/UI/API/Mobile/Voice
L6 Tool Runtime/MCP/External Integrations
L7 Autonomy/Self-Programming
```

## Required Questions

Before implementation, answer:

- What exact Atlas capability improves?
- Which existing law already governs this?
- Which docs must change first?
- Which code areas are allowed?
- Which current behavior must remain unchanged?
- Which gates prove success?
- What evidence is enough?
- What failure would make the change unsafe?
- What rollback is possible?

## Meta-SDD Flow

```text
gap
-> layer/risk classification
-> research if knowledge is unstable
-> docs update
-> meta-spec
-> critic/security/architecture review
-> plan/tasks
-> receipt
-> small implementation
-> gates
-> evidence
-> drift check
-> maturity update proposal
```

## Prohibitions

- No Atlas core code change from plain user intent.
- No "quick fix" for self-construction without at least minimal Meta-SDD.
- No hidden maturity promotion.
- No spec that lacks rollback for risky changes.
- No self-programming expansion when documentation and context are stale.

## Minimal Meta-SDD Exception

Tiny documentation fixes may use a lightweight spec if:

- no runtime behavior changes;
- no authority order changes;
- no policy/autonomy/security changes;
- docs-health and diff check pass.

Even then, evidence must exist in the final report.
