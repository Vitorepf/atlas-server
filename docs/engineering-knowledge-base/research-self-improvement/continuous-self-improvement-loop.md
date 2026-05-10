---
id: atlas-ai-continuous-self-improvement-loop
type: engineering_knowledge
title: Atlas AI Continuous Self-Improvement Loop
status: active
category: learning
priority: 99
summary: Governed loop for turning evidence, research, validation and failures into self-improvement proposals.
tags:
  - atlas-ai
  - self-improvement
  - curator
  - learning
capabilities:
  - governed_self_improvement
  - proposal_generation
  - quality_learning
decisions:
  - Self-improvement remains proposal-first until promotion gates prove safety.
  - Improvement is measured by evidence, not by volume of changes.
maintenance:
  - Update when Curator gains stronger autonomy or proposal inbox changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
---

# Atlas AI Continuous Self-Improvement Loop

Self-Improvement converts evidence into safer future behavior.

## Loop

```text
Collect evidence
-> Detect pattern
-> Classify risk
-> Compare with docs/APs
-> Generate proposal
-> Human or gate review
-> Implement small block
-> Validate
-> Promote or rollback
```

## Inputs

- Evidence Ledger;
- failed tests;
- architecture validation findings;
- docs-health findings;
- provider performance;
- research packets;
- user corrections;
- memory quality metrics;
- retrieval benchmark results;
- long-session degradation metrics.

## Proposal Requirements

Every proposal must include:

- source evidence;
- affected docs/code;
- risk;
- expected gain;
- validation;
- rollback;
- autonomy level;
- reason it is not auto-applied.

## Promotion Levels

| Level | Meaning |
|---|---|
| `lead` | Interesting, not trusted yet. |
| `proposal` | Evidence-backed, needs review. |
| `approved_plan` | Ready for scoped implementation. |
| `validated_block` | Implemented and tested. |
| `promoted_law` | Reflected in canonical docs and runtime gates. |

## Autonomy Principle

Autonomy should increase only after repeated successful evidence cycles.
Self-Improvement earns power; it does not assume it.

