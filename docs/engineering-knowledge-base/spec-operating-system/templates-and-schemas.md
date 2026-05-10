---
id: atlas-ai-sdd-templates-and-schemas
type: engineering_knowledge
title: Atlas SDD Templates And Schemas
status: active
category: contracts
priority: 98
summary: Minimal schemas for operational spec, assumption ledger, task and SDD policy.
tags:
  - atlas-ai
  - sdd
  - schemas
  - templates
capabilities:
  - sdd_schemas
  - spec_templates
decisions:
  - Templates define required structure but do not create parallel authority outside canonical docs.
maintenance:
  - Update before creating migrations, DTOs, commands or UI for SDD.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
---

# Atlas SDD Templates And Schemas

## Operational Spec

```yaml
spec:
  id:
  title:
  status: draft|approved|implemented|superseded
  type: feature|bugfix|refactor|ui_change|api_change|data_change
  risk: low|medium|high|critical
intent:
  raw_user_request:
  interpreted_goal:
  non_goals:
context:
  product_area:
  current_screen:
  code_area:
  related_specs:
business:
  actors:
  objects:
  rules:
requirements: []
acceptance_criteria: []
technical_constraints:
  backend:
  frontend:
  database:
  security:
  design_system:
assumptions: []
test_strategy:
  unit:
  integration:
  e2e:
  visual:
  accessibility:
```

## SDD Policy

```yaml
sdd_policy:
  default_mode: auto_spec_then_execute
  clarification:
    ask_only_when:
      - missing_target_context
      - ambiguous_business_object
      - security_or_permission_unclear
      - irreversible_or_high_risk_action
  design_system:
    forbid_hardcoded_colors: true
    prefer_tokens: true
    require_accessibility_for_ui_changes: true
  evidence:
    require_diff: true
    require_test_output: true
    require_traceability: true
```

## Projection Rule

`.atlas/` templates, AGENTS.md and local project files may mirror these schemas
for agent ergonomics. Canonical authority remains repo docs, APs, Knowledge DB,
Decision Receipts and Evidence Ledger.

