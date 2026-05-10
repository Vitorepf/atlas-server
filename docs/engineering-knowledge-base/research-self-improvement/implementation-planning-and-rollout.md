---
id: atlas-ai-research-implementation-planning-rollout
type: engineering_knowledge
title: Atlas AI Research Implementation Planning And Rollout
status: active
category: delivery-governance
priority: 98
summary: Rules for converting source-backed documentation into small, reversible implementation blocks.
tags:
  - atlas-ai
  - planning
  - rollout
  - validation
capabilities:
  - implementation_planning
  - rollout_governance
  - evidence_based_delivery
decisions:
  - Implementation must be decomposed into small reversible blocks.
  - Every block must have validation before promotion.
maintenance:
  - Update when Atlas adds planning evaluator, task contract generator or rollout autopilot.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
---

# Atlas AI Research Implementation Planning And Rollout

Implementation begins after source-backed documentation or a focused AP exists.

## Block Shape

Every block must declare:

- objective;
- owner files;
- hot files to avoid;
- allowed files;
- forbidden changes;
- test command;
- architecture/doc command when applicable;
- rollback plan;
- success metric.

## Rollout Order

1. Cold tests and guardrails.
2. Read-only scanners and reports.
3. DTO/schema contracts.
4. Runtime adapters behind fail-closed gates.
5. Surface exposure.
6. Promotion metrics.
7. Automation.

This order avoids making autonomy powerful before it is observable and
reversible.

## Stop Conditions

Stop and report when:

- implementation touches hotter ownership than planned;
- source basis is weaker than expected;
- validation would require forbidden temporary files;
- runtime change would bypass Kernel/Policy/Receipt/Ledger;
- docs and code disagree;
- failure is in a hot file owned by another active worker.

## Done Definition

A block is done only when:

- code/doc delta is scoped;
- tests pass or failure is explained with cause;
- `git diff --check` passes;
- docs-health passes if docs changed;
- architecture validation passes when structural contracts changed.

