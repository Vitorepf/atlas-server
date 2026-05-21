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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-background-domain

graph_title: Atlas AI Background Safety Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Background Safety Domain
canonical_name: Atlas AI Background Safety Domain
technical_name: atlas-ai-background-domain
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/background.md

repo_paths:
  - docs/engineering-knowledge-base/domains/background.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - domains

evidence:
  - docs/engineering-knowledge-base/domains/background.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - domains

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
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
`php artisan atlas:ai:long-running-work-report --json` is the read-only
promotion check for this boundary. It summarizes scheduled tasks, due work,
recent run status and autonomy receipt safety without claiming tasks or
dispatching jobs. The report also publishes
`atlas.long_running_work.baseline_contract.v1`, a hash-only baseline for the
structure-mother schedule families that should exist before autonomy promotion:
Memory/Open Brain retrieval snapshots, Capture/Inbox review, Task Orchestration
receipt review, Tool/Action runtime boundary review and proactive notification
review. If no scheduled task exists, the report is a low-severity warning rather
than OK, because zero schedules means monitoring exists but long-running work is
not operational yet.
`--emit-baseline-inbox` may emit a proposal item for operator review of that
baseline. The emission is a review bridge only: it does not create scheduled
tasks, dispatch jobs, mutate schedule state or raise autonomy.
`php artisan atlas:ai:long-running-work-declare-baseline --apply --json`
creates only missing disabled baseline schedule declarations in
`ai_scheduled_tasks`. These records have `enabled=false`, `next_run_at=null`,
no workspace path, no raw prompt from the operator, no dispatch authority and
`atlas.long_running_work.baseline_schedule_declaration.v1` metadata. They
declare the five schedule families so Structure Mother readiness can see the
baseline, but still require operator enablement before any job can run.

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

## Resumo

Spec canonica implemented/ready do dominio Background Safety para revisar tarefas em segundo plano, cadencia, permissoes e stop conditions sem iniciar jobs ou alterar schedules.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
