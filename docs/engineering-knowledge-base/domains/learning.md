---
id: atlas-ai-learning-domain
type: engineering_knowledge
title: Atlas AI Learning Domain
status: active
category: architecture
priority: 94
summary: Spec canonica implemented/ready do dominio Learning para aprendizado humano, pratica deliberada, revisao e spaced review sem alterar o Learning Plane do Core.
capabilities:
  - learning_domain
  - deliberate_practice
  - spaced_review
  - mastery_evidence
decisions:
  - Learning e dominio implemented/ready para aprendizado humano.
  - Learning Domain nao e o Learning Plane do Core; ele nao promove memoria nem altera comportamento critico sem review.
  - Planos de estudo nao mutam calendario, tarefas ou rotina automaticamente.
maintenance:
  - Atualize quando flows, gates, runtime, spaced review ou mastery evidence mudarem.
  - Rodar docs-health, sync, architecture-validate e testes de DomainProfileCompliance depois de alterar o contrato.
related_paths:
  - app/Services/Ai/Domain/AtlasLearningOrchestrator.php
  - app/Services/Ai/Domain/LearningPlanService.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_152000_promote_learning_domain_onboarding_contract.php
owner: atlas-ai
layer: domain
line_limit: 220
tags:
  - atlas-ai
  - learning
  - deliberate-practice
  - spaced-review
related:
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
---

# Atlas AI Learning Domain

Learning is the human learning domain. It helps the operator learn a topic,
skill, workflow or mental model through structured plans, practice loops,
review, retrieval and spaced repetition.

## Status

Current status: implemented/ready.

Learning emits deterministic learning packets with dry-run Decision Receipts and
Evidence Ledger events. Provider synthesis remains a later runtime stage; the
implemented contract defines safe packets, gates, outputs and memory policy.

## Non-Confusion Rule

Learning Domain is not the Core Learning Plane.

Core Learning transforms evidence into memory, metrics, proposals and process
updates. Learning Domain plans human learning. It can propose learning artifacts,
but it cannot mutate core memory, policy, calendar, tasks or behavior without
review.

## Scope

Included:

1. learning plans;
2. deliberate practice;
3. review after practice;
4. spaced review;
5. mastery rubric;
6. mistake patterns;
7. reviewed evidence of progress.

Excluded:

1. automatic calendar or task mutation;
2. claiming mastery without evidence;
3. promoting learning results to memory without review;
4. modifying Core Learning Plane behavior;
5. replacing Personal Development routines.

## Flows

1. `learning.plan`
2. `learning.practice`
3. `learning.review`
4. `learning.spaced_review`

## Required Gates

Every flow must preserve:

1. `learning_objective`
2. `practice_loop`
3. `mastery_rubric`

Review additionally requires:

1. `outcome_evidence`
2. `gap_map`
3. `next_iteration`

## Runtime Boundary

Runtime family: `learning`.

Execution mode: `plan_only`.

Learning can prepare packets, practice loops and review proposals. It cannot
write to calendar, tasks, Core Learning, or long-term memory without human
review.

## Evidence Contract

Important outputs should become:

1. a dry-run Decision Receipt;
2. an Evidence Ledger `EVIDENCE_PACKED` event;
3. a learning packet with objective, topic, target level, practice loop and
   mastery rubric;
4. memory proposal only after reviewed mastery evidence.

## Ready Boundary

Learning is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. `LearningPlanService` packet runtime;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. CLI/API/app/MCP surface declaration;
6. context, memory, gate and no-mutation policies;
7. tests proving human-learning boundary and Core Learning Plane non-mutation.
