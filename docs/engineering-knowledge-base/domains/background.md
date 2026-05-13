---
id: atlas-ai-background-domain
type: engineering_knowledge
title: Atlas AI Background Safety Domain
status: active
category: architecture
priority: 94
summary: Spec canonica implemented/ready do dominio Background Safety para revisar tarefas em segundo plano, cadencia, permissoes e stop conditions sem iniciar jobs ou alterar schedules.
capabilities:
  - background_domain
  - background_safety
  - schedule_review
  - permission_review
  - readiness_review
decisions:
  - Background Safety e dominio implemented/ready para governar trabalho recorrente, heartbeat, cron, daemon e automacoes propostas.
  - Background Safety nao inicia jobs, nao muda schedule, nao escala permissoes e nao roda loops sem limite.
  - Qualquer execucao real de background exige runtime/tool dono, policy explicita, aprovacao e Decision Receipt separado.
maintenance:
  - Atualize quando flows, gates, scheduler, automations, heartbeat ou permissoes de background mudarem.
  - Rodar docs-health, sync, architecture-validate e testes de DomainProfileCompliance depois de alterar o contrato.
related_paths:
  - app/Services/Ai/Domain/BackgroundSafetyOrchestrator.php
  - app/Services/Ai/Domain/BackgroundSafetyService.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_160000_promote_background_domain_onboarding_contract.php
owner: atlas-ai
layer: domain
line_limit: 220
tags:
  - atlas-ai
  - background
  - automations
  - scheduler
  - safety
related:
  - docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
---

# Atlas AI Background Safety Domain

Background Safety is the review-only domain for work that may run outside the
immediate interactive request: cron, heartbeat, daemon, watcher, recurring
automation and proactive proposal loops.

## Status

Current status: implemented/ready.

Background emits deterministic safety packets with dry-run Decision Receipts
and Evidence Ledger events. It does not start the background job.

## Non-Confusion Rule

Background Safety is not the scheduler and not an executor.

It can decide whether a proposed recurring job has bounded scope, explicit
schedule, least-privilege permissions, evidence requirements and stop
conditions. It cannot create, start, pause, reschedule or escalate that job.

## Scope

Included:

1. safe background review;
2. readiness review;
3. schedule/cadence review;
4. permission review;
5. stop-condition review;
6. operator approval requirements.

Excluded:

1. starting background jobs;
2. mutating cron/heartbeat schedules;
3. permission escalation;
4. unbounded loops;
5. bypassing operator review.

## Flows

1. `background.safe`
2. `background.readiness_review`
3. `background.schedule_review`
4. `background.permission_review`

## Required Gates

Every flow must preserve:

1. `background_scope`
2. `review_only`
3. `stop_conditions`
4. `human_review_required`

Background execution additionally requires:

1. `separate_decision_receipt`
2. `operator_approval`
3. `bounded_schedule`
4. `stop_conditions`

## Runtime Boundary

Runtime family: `background`.

Execution mode: `review_only`.

Background Safety prepares safety decisions, permission summaries, readiness
scores, cadence reviews and approval requirements. If a real scheduled task is
needed, Atlas Decide must route to the owning runtime with explicit approval
and a separate Decision Receipt.
`RunScheduledTaskJob` persists `atlas.long_running_work.autonomy_contract.v1`
inside each `atlas.scheduled_task_run_receipt.v1`: autonomy remains low,
execution is single-run, stop conditions are explicit, recursive schedule
execution and autonomous follow-up are blocked, and operator review is required
for escalation.

## Evidence Contract

Important outputs should become:

1. a dry-run Decision Receipt;
2. an Evidence Ledger `EVIDENCE_PACKED` event;
3. a background packet with job, scope, trigger, schedule, permissions and stop
   conditions;
4. a reviewed finding before memory, automation or policy promotion.

## Ready Boundary

Background Safety is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. `BackgroundSafetyService` packet runtime;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. scheduler/CLI/API/app/MCP surface declaration;
6. context, memory, gate and review-only policies;
7. tests proving no job start, schedule mutation, permission escalation or
   unbounded background loop.
