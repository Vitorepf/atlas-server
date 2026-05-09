---
id: atlas-ai-self-improvement-flows
type: engineering_knowledge
title: Atlas AI Self-Improvement Flows
status: active
category: architecture
priority: 88
summary: Focused flow catalog for the Self-Improvement domain.
tags:
  - atlas-ai
  - self-improvement
  - domains
capabilities:
  - self_improvement_domain
  - proposal_generation
decisions:
  - Self-Improvement flows create findings and proposals; they do not auto-apply critical behavior changes.
maintenance:
  - Update when flows are added, renamed or retired.
related_paths:
  - docs/engineering-knowledge-base/domains/self-improvement.md
---

# Atlas AI Self-Improvement Flows

| Flow | Purpose |
|---|---|
| `self_improvement.nightly_review` | Recurring review of failures, drift, regressions and small proposals. |
| `self_improvement.weekly_architecture_audit` | Weekly architecture, docs, duplication and coverage audit. |
| `self_improvement.capability_gap_scan` | Gaps between declared capabilities, surfaces, tools and observed behavior. |
| `self_improvement.benchmark_review` | Baselines, benchmark corpus and quality suite review. |
| `self_improvement.memory_quality_review` | Recall, redundancy, stale memory, provider-safety and learning review. |
| `self_improvement.tool_runtime_review` | Tool failures, normalizers, recipes, gates and recovery. |
| `self_improvement.repair_loop_review` | Repair loop human review, blocked/exhausted repairs and repeated strategies. |
| `self_improvement.kernel_pipeline_review` | `atlas.run` accepted/rejected contracts and adapter drift. |
| `self_improvement.domain_learning_review` | Feedback/traces into domain/profile/policy/gate proposals. |
| `self_improvement.docs_drift_review` | Drift between KB, code, catalog, migrations and historical docs. |
| `self_improvement.provider_performance_review` | Cost, latency, quality, fallback, SLO and provider compliance. |
| `self_improvement.agent_behavior_review` | Agent behavior, missing verification, drift and repeated findings. |
| `self_improvement.proposal_generation` | Consolidates findings into provider-safe reviewable proposals. |
