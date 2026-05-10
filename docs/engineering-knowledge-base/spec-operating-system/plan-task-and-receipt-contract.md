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

