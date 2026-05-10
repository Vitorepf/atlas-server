---
id: atlas-ai-research-self-improvement-metrics-and-evals
type: engineering_knowledge
title: Atlas AI Research Self-Improvement Metrics And Evals
status: active
category: evaluation
priority: 99
summary: Metrics and evals for proving research quality, evolution velocity, long-session impact and self-improvement safety.
tags:
  - atlas-ai
  - metrics
  - evals
  - research-quality
capabilities:
  - research_quality_eval
  - evolution_velocity_metrics
  - self_improvement_eval
decisions:
  - Atlas cannot claim better evolution without metrics.
  - Quality metrics must include source quality, recall, safety, cost and rework.
maintenance:
  - Update when Observability, Evidence Ledger or Self-Improvement exposes these metrics as read models.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
---

# Atlas AI Research Self-Improvement Metrics And Evals

## Research Quality Metrics

| Metric | Target |
|---|---|
| Primary-source ratio | >= 80% for critical claims |
| Citation coverage | 100% for factual claims |
| Hallucinated-source rate | 0 |
| Stale-source rate | Explicit and trending down |
| Contradiction detection | Conflicts recorded before promotion |
| Uncertainty preservation | Unknowns remain visible |
| Scope coverage | Required subquestions addressed or marked unknown |
| Abstention correctness | No unsupported answer when evidence is insufficient |

## Evolution Velocity Metrics

| Metric | Meaning |
|---|---|
| research_to_doc_latency | Time from strong finding to canonical doc/AP |
| doc_to_plan_latency | Time from doc law to scoped block plan |
| plan_to_validated_block_latency | Time from plan to passing validation |
| weak_research_rework_rate | Rework caused by poor source quality |
| promotion_throughput | Validated improvements promoted per week |

## Self-Improvement Safety Metrics

- proposal acceptance rate;
- false-positive proposal rate;
- auto-apply attempt blocks;
- rollback rate;
- post-promotion regression count;
- policy/runtime bypass attempts blocked;
- memory contamination findings.

## Efficiency And Robustness Metrics

- cost per report;
- latency per report;
- sources read per minute;
- tokens per verified claim;
- cache hit rate;
- duplicate source rate;
- crawler/API failure rate;
- dead URL rate;
- changed page rate;
- prompt injection detection rate;
- agent divergence rate.

## External Eval References

Atlas should model internal evals after:

- browsing/retrieval persistence benchmarks such as BrowseComp-like tasks;
- deep research report benchmarks such as DeepResearch-Bench-like tasks;
- atomic factuality scoring inspired by FActScore-like evaluation;
- research-and-revise loops inspired by RARR-like correction;
- citation accuracy and URL liveness evals;
- internal Atlas research tasks tied to docs/AP/code outcomes.

## Long-Session Impact Metrics

Research/self-improvement must improve Cognitive Runtime:

- fewer repeated decisions;
- lower context drift;
- higher recall of active decisions;
- lower compaction loss;
- faster recovery after handoff;
- stable quality across 72h target sessions.

## Minimum Eval Suite

1. Source hallucination eval: every cited source must resolve or map to repo.
2. Claim support eval: each critical claim must map to source evidence.
3. Conflict eval: contradictory sources must be surfaced.
4. Promotion eval: docs/AP target must match authority map.
5. Implementation eval: hot files and forbidden changes must be honored.
6. Self-improvement eval: proposal must remain proposal-only until gate.
7. Regression eval: promoted changes must not reduce docs-health or architecture validation.

## Reporting Shape

```json
{
  "schema_version": "atlas.research_eval_report.v1",
  "status": "pass|warn|fail",
  "research_quality": {},
  "evolution_velocity": {},
  "self_improvement_safety": {},
  "long_session_impact": {},
  "blocking_findings": []
}
```
