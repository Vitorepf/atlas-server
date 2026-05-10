---
id: atlas-ai-self-construction-implementation-priority-engine
type: engineering_knowledge
title: Atlas Self-Construction Implementation Priority Engine
status: active
category: architecture
priority: 100
summary: Priority law for choosing the highest-leverage Atlas construction work.
tags:
  - atlas-ai
  - self-construction
  - prioritization
capabilities:
  - implementation_priority_engine
  - self_construction_os
decisions:
  - Atlas should prioritize compounding foundations over isolated feature excitement.
  - Priority must be scored by leverage, dependency unlock, risk and evidence.
maintenance:
  - Update before changing roadmap priorities or autonomous work selection.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/build-graph.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Implementation Priority Engine

Atlas must not choose work because it is interesting. It chooses work because it
maximizes durable capability.

## Priority Formula

```text
priority_score =
  strategic_leverage
+ dependency_unlocks
+ quality_improvement
+ autonomy_enablement
+ user_value
+ evidence_confidence
- risk
- implementation_size
- uncertainty
- maintenance_burden
```

## P0 Foundations

P0 construction areas:

1. governed memory;
2. context retrieval quality;
3. long session quality and compaction;
4. SDD runtime;
5. evidence, gates and drift detection;
6. research self-improvement runtime;
7. self-construction loop;
8. voice/mobile surfaces only after core contracts stay intact.

## Selection Questions

Before choosing work, Atlas asks:

- Does this unlock multiple downstream capabilities?
- Does it reduce future implementation error?
- Does it improve memory, context, SDD, evidence or gates?
- Does it make autonomous execution safer?
- Is there enough source truth and code context?
- Can it be implemented in a small reversible slice?
- Are gates available?

## Deprioritize

Deprioritize work that is:

- visually impressive but low leverage;
- provider-wrapper driven;
- not tied to a build graph dependency;
- missing source-backed research;
- missing tests/gates;
- likely to expand scope;
- likely to create parallel architecture.

## Priority Packet

Each self-construction task should carry:

```yaml
priority:
  score:
  rationale:
  p_level: P0 | P1 | P2 | P3
  unlocks:
  blocked_by:
  risk:
  smallest_safe_slice:
```

## Current Strategic Bias

Until Atlas reaches reliable autonomous runtime, the priority engine favors:

```text
memory + retrieval + SDD runtime + evidence + drift + research verification
```

over broad product expansion.
