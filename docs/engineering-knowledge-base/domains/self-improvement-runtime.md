---
id: atlas-ai-self-improvement-runtime
type: engineering_knowledge
title: Atlas AI Self-Improvement Runtime
status: active
category: architecture
priority: 88
summary: Runtime, schedule, filters and replay contract for Self-Improvement domain.
tags:
  - atlas-ai
  - self-improvement
  - runtime
capabilities:
  - self_improvement_domain
  - docs_drift_review
decisions:
  - Self-Improvement runtime consumes shared read models and emits reviewable proposals, not direct critical mutations.
maintenance:
  - Update when schedule, filters, replay surfaces or AP review signals change.
related_paths:
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
---

# Atlas AI Self-Improvement Runtime

## Runtime Sources

- `sloReportForWindow()`;
- `repairReportForWindow()`;
- `kernelPipelineReportForWindow()`;
- domain catalog scorecards;
- architecture validation;
- KB, Code Intelligence, tool evidence, memory signals, provider traces and benchmark corpus.

## Operational Surfaces

- `atlas:ai:self-improve --list-flows --json`;
- `atlas:ai:self-improve --schedule-plan --json`;
- `atlas:ai:self-improve --schedule-health --json`;
- `atlas:ai:self-improve --flow=... --plan-only --json`;
- `GET /ai/self-improvement/schedule`;
- `GET /ai/self-improvement/schedule/health`;
- `GET /ai/self-improvement/schedule/report`;
- Open Brain/MCP read-only schedule and report tools.

## Schedule Contract

The recurring plan is normalized by `AtlasSelfImprovementScheduleService`.
Unknown flows, invalid time or invalid timezone become visible warnings and do
not silently execute. `plan_hash` is stable over effective configuration and
health issues, not clock movement.

Default recurring flows now include `provider_release_review` between agent
behavior and voice realtime review. This keeps provider/lab launches under
Curator observation without crawler, direct provider channel, policy write or
routing mutation. The flow remains proposal-only and every release still needs
source gate, Provider Release Envelope, AP/Rivals/AP-99 evidence and human
review before any Decide signal can be promoted.

## Review Signals

AP42-AP56 ensure recurring architecture audit, per-flow cadence, per-command
`next_run_at`, schedule replay, review signals for schedule, kernel pipeline,
repair loop and SLO drift, plus surface parity across CLI, API, Observability
and MCP.

## Safety

Warnings and drift become findings/proposals. Runtime, adapter, provider,
schedule or policy changes still require the normal implementation/review path.
