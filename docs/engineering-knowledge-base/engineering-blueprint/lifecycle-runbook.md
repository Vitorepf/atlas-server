---
id: atlas-engineering-blueprint-lifecycle-runbook
type: engineering_knowledge
title: Atlas Engineering Blueprint Lifecycle Runbook
status: active
category: runbook
priority: 88
summary: End-to-end lifecycle for project blueprint, task generation, harness execution, QA, review, Postgres gate and memory delta.
tags:
  - atlas
  - engineering
  - lifecycle
capabilities:
  - engineering_blueprint
  - qa_evidence
  - review_gates
decisions:
  - Blueprint lifecycle runs from project intent to evidence and memory delta through governed gates.
maintenance:
  - Update when lifecycle steps change.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
---

# Atlas Engineering Blueprint Lifecycle Runbook

## Product Lifecycle

| Step | Purpose |
|---|---|
| Prepare project blueprint | Draft objective, context, repos, risks and constraints. |
| Create project blueprint | Build deterministic project-level plan from intent and project state. |
| Validate coverage | Block if inventory, scenarios, phase plan or gates are incomplete. |
| Freeze blueprint | Hash and freeze immutable version. |
| Generate tasks | Convert phases into strong task contracts. |
| Run task | Execute through Programming/Harness with context and gates. |
| QA manual | Attach human or runtime evidence. |
| Deep review | Add categorized findings and confidence. |
| Postgres review | Validate database risk and query/lock concerns. |
| Promote to Atlas-Bench | Use run evidence for benchmark/calibration. |
| Memory delta | Promote reusable learning through Memory Core policy. |

## AI Start Checklist

- Read task contract and frozen blueprint.
- Compile context from Open Brain and Engineering Context.
- Confirm gates and expected evidence.
- Execute through the selected runtime.
- Produce evidence packet and memory delta proposal.

## Failure Rule

Missing contract, stale blueprint, absent evidence or blocking gate means the
operation should pause or repair, not claim completion.
