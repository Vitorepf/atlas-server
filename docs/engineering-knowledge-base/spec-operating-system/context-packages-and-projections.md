---
id: atlas-ai-spec-operating-system-context-packages-and-projections
type: engineering_knowledge
title: Atlas Spec Operating System Context Packages And Projections
status: active
category: architecture
priority: 99
summary: Versioned context packages and local projection rules for Atlas SDD.
tags:
  - atlas-ai
  - sdd
  - context-engineering
  - projections
capabilities:
  - spec_operating_system
  - context_discovery
  - documentation_operating_system
decisions:
  - Agents must use versioned context packages instead of reinventing project rules.
  - Local `.atlas` trees may project canonical docs into project form, but cannot outrank them.
  - Context packages must include good examples, bad examples, commands, gates and evaluation criteria.
maintenance:
  - Update when adding project adapters, generated `.atlas` trees or SDD context package versions.
  - Keep aligned with Documentation Operating System and canonical architecture index.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md
  - docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md
  - docs/engineering-knowledge-base/START_HERE.md
owner: atlas-ai
layer: 0.7-and-programming
line_limit: 220
---

# Atlas Spec Operating System Context Packages And Projections

Atlas SDD needs stable project understanding across agents, tools and sessions.
That understanding must be packaged, versioned and evaluated.

## Context Packages

Example packages:

```text
laravel-api-v1
react-typescript-v1
atlas-design-system-v2
security-policy-v1
testing-standard-v3
product-rules-v1
decision-receipt-v1
evidence-ledger-v1
```

Each package must define:

- purpose and scope;
- authority source;
- instructions;
- good examples;
- bad examples;
- commands and gates;
- common mistakes;
- evaluation criteria;
- compatible domains/harnesses;
- version and supersession rules.

## Package Selection

Context Builder selects packages from:

- Operation Envelope;
- project stack;
- active domain;
- changed file types;
- risk level;
- current spec/receipt;
- canonical docs and Knowledge DB.

For a React + Laravel UI save action, likely packages:

```text
react-typescript-v1
atlas-design-system-v2
laravel-api-v1
testing-standard-v3
security-policy-v1
decision-receipt-v1
```

## Local Projection Tree

A local `.atlas` tree can make Atlas rules easier for external agents:

```text
.atlas/
  constitution.md
  product/
    overview.md
    personas.md
    business-rules.md
    glossary.md
    workflows.md
  architecture/
    backend.md
    frontend.md
    database.md
    security.md
    testing.md
    api-standards.md
    design-system.md
  sdd/
    policy.yaml
    templates/
      spec-template.yaml
      plan-template.yaml
      task-template.yaml
      decision-receipt-template.yaml
    agents/
      spec-agent.md
      product-critic.md
      architecture-agent.md
      task-agent.md
      qa-agent.md
      security-agent.md
      drift-detector.md
  specs/
    SPEC-YYYY-NNNN-example/
      spec.yaml
      spec.md
      plan.yaml
      tasks.yaml
      traceability.yaml
      evidence.md
      assumptions.yaml
  evidence/
    OP-YYYY-NNNN/
      decision-receipt.yaml
      diff.patch
      test-output.log
      quality-gates.json
      final-report.md
  memory/
    decisions.md
    learned-patterns.md
    rejected-patterns.md
    recurring-failures.md
```

## Projection Law

- Canonical docs, APs, Kernel and receipts outrank `.atlas` projection files.
- Projection files must declare generation source and timestamp.
- Projection drift must be detected by docs-health or dedicated projection audit.
- External agents may read projections, but Atlas must verify against canonical
  docs before risky execution.
- Projection files must not silently add permissions, tools or autonomy.

## AGENTS.md Bridge

Project root `AGENTS.md` should summarize rules for any code agent:

```text
- stack and project purpose;
- mandatory docs to read;
- forbidden areas;
- required gates;
- design system rules;
- backend/frontend conventions;
- receipt and evidence expectations.
```

`AGENTS.md` is a bridge for agents. It is not a replacement for the canonical
Atlas docs.
