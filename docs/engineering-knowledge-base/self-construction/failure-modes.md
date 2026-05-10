---
id: atlas-ai-self-construction-failure-modes
type: engineering_knowledge
title: Atlas Self-Construction Failure Modes
status: active
category: architecture
priority: 99
summary: Known ways Atlas self-construction can fail and required countermeasures.
tags:
  - atlas-ai
  - self-construction
  - failure-modes
capabilities:
  - self_construction_os
  - failure_governance
decisions:
  - Self-construction must treat failure modes as first-class design constraints.
  - The most dangerous failures are silent drift, false completeness and unsafe learning.
maintenance:
  - Update after incidents, failed autonomous runs or drift detections.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Failure Modes

Self-construction fails when Atlas becomes confident faster than it becomes
correct.

## Failure Table

| Failure | Signal | Countermeasure |
|---|---|---|
| Vibe self-coding | code without Meta-SDD | block; require spec and receipt |
| Parallel architecture | new flow bypasses Kernel/docs | architecture validate and AP review |
| Doc theatre | docs exist but code/gates absent | maturity ladder labels |
| False completeness | "done" without evidence | evidence closeout required |
| Scope creep | adjacent features added | receipt allowed files/actions |
| Memory contamination | weak facts promoted | Cognitive Immune gate |
| Research hallucination | claims without primary source | Evidence Lake and citation health |
| Drift | spec, code and tests diverge | drift detector |
| Unsafe learning | template/policy auto-mutated | proposal-first learning |
| Overengineering | large abstraction before need | small slice rule |
| Gate blindness | passing wrong tests | acceptance traceability |
| Long-session decay | repeated decisions, stale context | compaction and handoff metrics |

## Red Flags

Stop construction if:

- no one can name the target capability;
- the change improves no build graph dependency;
- test output does not prove acceptance criteria;
- docs and code disagree;
- implementation changes autonomy or security as a side effect;
- the agent says "complete" but cannot cite evidence.

## Required Incident Packet

When a self-construction failure occurs:

```yaml
incident:
  operation_id:
  failure_mode:
  root_cause:
  affected_docs:
  affected_files:
  failed_gates:
  rollback:
  prevention_proposal:
```

## Recovery Order

1. Stop writes.
2. Preserve evidence.
3. Identify drift/failure mode.
4. Revert only owned unsafe changes or propose rollback.
5. Update docs/spec if law was incomplete.
6. Add test/gate if failure was not detected.
7. Resume with smaller receipt.
