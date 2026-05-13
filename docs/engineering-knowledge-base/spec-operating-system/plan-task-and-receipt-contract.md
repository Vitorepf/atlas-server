---
id: atlas-ai-sdd-plan-task-receipt-contract
type: engineering_knowledge
title: Atlas SDD Plan Task And Receipt Contract
status: active
category: contracts
priority: 99
summary: Plan compiler, task compiler and Decision Receipt contract for SDD execution.
tags:
  - atlas-ai
  - sdd
  - plan
  - decision-receipt
capabilities:
  - plan_compiler
  - task_compiler
  - sdd_decision_receipt
decisions:
  - Plans and tasks are executable objects, not just markdown prose.
  - Decision Receipt is the execution boundary.
maintenance:
  - Update when task contract, receipt schema or execution harness changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/kernel/contracts.md
---

# Atlas SDD Plan Task And Receipt Contract

## Plan Compiler

Plan output must include:

- target files;
- forbidden files;
- hot-file ownership;
- technical approach;
- backend/frontend/database/security/design impact;
- test plan;
- rollback plan.

## Task Compiler

Tasks must be small and ordered:

```yaml
tasks:
  - id: T1
    type: analysis
    title: Inspect current form submit handler
    allowed_files: ["resources/js/components/forms/ProfileForm.tsx"]
  - id: T2
    type: code_change
    depends_on: ["T1"]
    title: Add save button using design-system Button
```

## Task Lifecycle Audit

Task orchestration surfaces must preserve a local lifecycle trail before they
claim progress. Open Brain MCP task tools write `AtlasTaskEvent` records with
schema `atlas.task_orchestration.event.v1` for `started`, `milestone` and
`completed` events. If `atlas_tasks` or `atlas_task_events` is unavailable, the
tool fails closed with no task write.

Each event payload carries `event_sequence`, `previous_event_id`,
`previous_event_hash` and `event_hash` so task progress is locally hash-chained
and can be replayed as an audit trail. The hash chain is an orchestration
lineage receipt only; it is not a provider dispatch receipt.

Lifecycle events must state when they did not execute providers or runtimes.
Provider dispatch, external runtime execution, merge, approval and policy change
remain outside the task event contract and require a Decision Receipt.
Task and task-event API/CLI resources expose safety summaries as
`atlas.task_orchestration.task_safety.v1` and
`atlas.task_orchestration.event_safety.v1`. These are read-model contracts:
provider dispatch, runtime execution, policy mutation and automatic completion
stay closed unless a separate Decision Receipt authorizes them.

Planning, schedule, defer, calendar and completion paths that use
`TaskPlanningService::recordEvent()` also persist the same local event schema.
The service appends `atlas.task_orchestration.local_event_receipt.v1` to each
payload, assigns a per-task `event_sequence`, links to the previous event id and
hash, and stores a SHA-256 `event_hash`. This makes normal task planning
replayable by Open Brain without implying provider dispatch, runtime execution,
policy mutation or automatic completion authority.

## Decision Receipt Fields

SDD receipt must preserve:

- operation id;
- spec id;
- plan id;
- task ids;
- risk level;
- autonomy level;
- selected runtime/agents;
- allowed actions/tools/files;
- forbidden actions/tools/files;
- required gates;
- rollback strategy;
- evidence required.

## Execution Rule

Runtime may edit only what the receipt allows. Any need outside receipt creates
a new proposal or clarification, not silent scope expansion.
