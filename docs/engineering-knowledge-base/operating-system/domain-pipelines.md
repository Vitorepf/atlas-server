---
id: atlas-ai-os-domain-pipelines
type: engineering_knowledge
title: Atlas AI OS - Domain Pipelines
status: active
category: architecture
priority: 99
summary: Canonical pipeline shapes for Programming, Personal Development, Finance and Self-Improvement domains.
tags:
  - atlas-ai
  - domains
  - pipeline
capabilities:
  - programming_pipeline
  - personal_development_pipeline
  - finance_pipeline
  - self_evolution_pipeline
decisions:
  - Every operational domain follows the canonical pipeline.
  - Domain-specific harnesses customize content, not the law of policy/evidence/gates.
maintenance:
  - Update when domain flow shapes change.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/domains/personal-development.md
  - docs/engineering-knowledge-base/domains/finance.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
---

# Atlas AI OS - Domain Pipelines

## Canonical Pipeline

```txt
input -> domain -> intent -> profile -> flow -> context -> policy -> decide
-> executor -> execution -> gates -> repair/escalation -> evidence -> learning -> output
```

No mature domain may skip policy, evidence or gates for operational work.

## Programming

Scope: dev, forge, fix, review, refactor, QA, security, tests, database,
release, benchmarks, memory of engineering and code intelligence.

Executors range from simple provider path to dev repair executor and Engineering
Harness. `atlas dev`, `atlas forge`, `atlas fix` and `atlas continue` are
intensities/aliases of this same domain.

## Personal Development

Scope: reflection, daily/weekly review, habits, focus, learning, energy,
objectives and recovery.

Limits: private by default, non-clinical language, no diagnosis, no medical
treatment, no automatic mutation of calendar/tasks/external systems.

## Finance

Scope: market research, risk review, portfolio analysis, thesis review, macro,
earnings, news impact, compliance, backtest plan and finance forge.

Limits: review-only, low autonomy by default, no market orders, no broker
execution, no rebalance/transfer payloads.

## Self-Improvement / Curator

Scope: detect duplication, loose capabilities, docs drift, architecture drift,
process gaps and proposed improvements.

Curator may propose and prepare changes. High-risk changes require gates and
human review before critical behavior changes.
