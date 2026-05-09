---
id: atlas-ai-programming-repair-contract
type: engineering_knowledge
title: Atlas AI Programming Repair Contract
status: active
category: architecture
priority: 90
summary: Repair Loop contract for Programming flows, Dev, Forge, Fix and worker repair.
tags:
  - atlas-ai
  - programming
  - repair
capabilities:
  - programming_domain
  - dev_repair_executor
decisions:
  - Programming repair converges on Kernel Repair contracts and Evidence Ledger events.
maintenance:
  - Update when Kernel Repair or Programming repair metadata changes.
related_paths:
  - docs/engineering-knowledge-base/domains/programming.md
  - app/Services/Ai/Kernel/Repair
---

# Atlas AI Programming Repair Contract

## Target Use

- classify operational failure as `FailureClassification`;
- preserve `envelope_id` and `receipt_id`;
- fill `evidence_refs` with ledger events, harness runs, diffs, logs,
  screenshots or test output;
- call `AtlasRepairOrchestrator::plan()` before attempts;
- block heavy repair without evidence;
- require human review for policy, privacy, security, compliance, unknown and
  terminal states.

## Current Integration

- `AiWorker` plans through Kernel Repair before queuing repair jobs.
- `AtlasProgrammingOrchestrator::sessionPlan()` publishes
  `repair_execution_contract`.
- Programming plans also publish `agent_behavior_contract`.
- Forge/Harness propagates the same repair and behavior contracts.
- `atlas:ai:repair` and `POST /ai/repair` remain planning/scaffold surfaces.
- Ledger projections expose repair summaries by envelope.
