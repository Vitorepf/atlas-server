---
id: atlas-ai-research-self-improvement-automation-runbook
type: engineering_knowledge
title: Atlas AI Research Self-Improvement Automation Runbook
status: active
category: maintenance
priority: 98
summary: Runbook for implementing research and self-improvement automation safely, starting read-only and proposal-only.
tags:
  - atlas-ai
  - automation
  - runbook
  - self-improvement
capabilities:
  - research_automation_runbook
  - fail_closed_automation
decisions:
  - Automation must start as read-only packet generation.
  - Background research requires source registry, rate limits, audit and review before activation.
maintenance:
  - Update when commands, jobs, schedulers or API surfaces are implemented.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
  - docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md
---

# Atlas AI Research Self-Improvement Automation Runbook

## Activation Order

1. Read-only schema/packet renderer.
2. Source quality scorer with no network side effects.
3. Manual research packet import.
4. Docs promotion preview.
5. AP/plan generator.
6. Self-Improvement proposal emission.
7. Scheduled read-only review.
8. Background source discovery with dedicated AP.
9. Approved apply for low-risk docs only, after repeated evidence.

## Required Guards Before Scheduler

- source registry;
- allowed source list;
- rate limits;
- canonical URL/hash;
- duplicate suppression;
- Evidence Ledger event;
- proposal inbox integration;
- docs-health validation;
- architecture validation for structural proposals;
- human review for medium/high risk.

## Forbidden First Versions

- crawler that writes docs directly;
- scheduler that changes code;
- provider release that changes Decide routing;
- memory write from unverified research;
- autonomous deletion of docs or source records;
- hidden background daemon without observability.

## Future Command Shape

```bash
php artisan atlas:ai:research-review --topic="<topic>" --plan-only --json
php artisan atlas:ai:research-review --source="<url>" --classify-only --json
php artisan atlas:ai:research-review --packet="<id>" --promotion-preview --json
php artisan atlas:ai:self-improve --flow=research_quality_review --plan-only --json
```

First implementation must return packets and review signals only.

## Validation Set

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:engineering:knowledge sync --prune --json
php artisan atlas:ai:architecture-validate --json
git diff --check
```

If automation changes runtime code, add focused tests and architecture scanner
coverage before promotion.

