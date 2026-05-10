---
id: atlas-ai-self-construction-quality-bar-and-metrics
type: engineering_knowledge
title: Atlas Self-Construction Quality Bar And Metrics
status: active
category: architecture
priority: 99
summary: Measurable quality bar for absurd-level Atlas self-construction.
tags:
  - atlas-ai
  - self-construction
  - quality
  - metrics
capabilities:
  - self_construction_os
  - quality_metrics
decisions:
  - "Absurd level" must be measured as behavior, not declared as ambition.
  - Atlas quality is judged by continuity, correctness, evidence, autonomy safety and compounding improvement.
maintenance:
  - Update when adding dashboards, eval harnesses or maturity scorecards.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/cognitive-runtime/enterprise-excellence-checklist.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Quality Bar And Metrics

"Most powerful" must become measurable.

## Absurd-Level Quality Bar

Atlas self-construction should:

- choose high-leverage work;
- preserve canonical law;
- avoid repeated work;
- remember active decisions;
- retrieve the right context;
- ask only when ambiguity matters;
- implement small safe slices;
- validate before claiming success;
- record evidence;
- detect drift;
- learn through proposals;
- resume after compaction without losing invariants.

## Metrics

| Dimension | Metric |
|---|---|
| Context | relevant context precision, stale context rate, missing-doc rate |
| SDD | spec completeness, assumption quality, acceptance coverage |
| Execution | gate pass rate, repair count, rollback readiness |
| Evidence | requirements with proof, citation/evidence health |
| Drift | spec/code/test mismatch count |
| Continuity | handoff success, repeated decision rate, long-session degradation |
| Safety | blocked unsafe actions, forbidden-scope attempts |
| Priority | high-leverage task selection accuracy |
| Learning | proposals accepted, proposals rejected, unsafe proposal rate |

## Quality Gates

Self-construction gates:

```text
docs-health
architecture-validate
knowledge sync
code index
focused tests
static scans
receipt scope check
traceability check
drift check
diff check
```

## Definition Of Done

A self-construction block is done when:

- docs/AP/spec exist;
- code change, if any, matches spec;
- focused tests pass;
- architecture/doc gates pass;
- evidence is append-only;
- drift is checked;
- maturity delta is stated;
- residual risk is explicit.

## Score Bands

| Score | Meaning |
|---|---|
| 0-3 | ad hoc coding |
| 4-5 | documented but weakly validated |
| 6-7 | governed and testable |
| 8-9 | agent-executable with strong evidence |
| 9+ | self-improving, drift-aware and strategically prioritized |

No score above 9 is valid without repeated autonomous runs and low drift.
