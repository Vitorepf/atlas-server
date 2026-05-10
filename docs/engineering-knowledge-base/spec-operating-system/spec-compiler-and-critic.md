---
id: atlas-ai-sdd-spec-compiler-critic
type: engineering_knowledge
title: Atlas SDD Spec Compiler And Critic
status: active
category: contracts
priority: 99
summary: Rules for compiling user intent into operational spec and critiquing ambiguity, risk and design conflicts.
tags:
  - atlas-ai
  - sdd
  - spec-compiler
  - spec-critic
capabilities:
  - spec_compiler
  - spec_critic
  - assumption_ledger
decisions:
  - Spec Compiler converts intent into requirements and acceptance criteria.
  - Spec Critic must attack ambiguity, overreach, missing tests and policy/design conflicts before plan.
maintenance:
  - Update when spec compiler or ambiguity gates become executable.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md
---

# Atlas SDD Spec Compiler And Critic

## Spec Compiler Output

Minimum fields:

- raw user request;
- interpreted goal;
- non-goals;
- product area;
- business actor/object/action;
- requirements;
- acceptance criteria;
- design-system constraints;
- security/privacy constraints;
- assumptions;
- blocking questions;
- test strategy.

## Assumption Ledger

Assumptions are never hidden.

```yaml
assumptions:
  - id: A1
    text: "The button saves the currently active profile form."
    confidence: 0.91
    evidence:
      - "active_file: ProfileForm.tsx"
      - "route: /profile/edit"
    blocking: false
```

## Spec Critic Checks

- missing target file/screen;
- missing business object;
- design-system conflict;
- hardcoded style when token exists;
- missing loading/disabled/error/success state;
- missing auth/permission rule;
- API/backend ambiguity;
- overengineering;
- scope creep;
- missing acceptance criteria;
- missing test strategy.

## Output States

| State | Meaning |
|---|---|
| `ready_for_plan` | Enough context and no blocking ambiguity. |
| `needs_clarification` | Short user question required. |
| `blocked_by_policy` | Request violates safety/design/architecture rule. |
| `spike_only` | Exploration allowed, implementation blocked. |

