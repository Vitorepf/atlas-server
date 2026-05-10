---
id: atlas-ai-self-construction-capability-maturity-ladder
type: engineering_knowledge
title: Atlas Self-Construction Capability Maturity Ladder
status: active
category: architecture
priority: 99
summary: Maturity levels for Atlas capabilities from documented idea to strategic self-programming.
tags:
  - atlas-ai
  - self-construction
  - maturity
capabilities:
  - capability_maturity_ladder
  - self_construction_os
decisions:
  - Atlas capabilities must advance by evidence-backed maturity levels.
  - A capability is not complete because it is documented or scaffolded.
maintenance:
  - Update before changing readiness labels, roadmap scoring or implementation status language.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Capability Maturity Ladder

Atlas must distinguish idea, law, scaffold, runtime and autonomous competence.

## Levels

| Level | Name | Meaning |
|---|---|---|
| L0 | Named | Capability is identified but not governed. |
| L1 | Documented | Canonical docs define purpose, boundaries and owner. |
| L2 | Specified | SDD/AP/spec defines behavior, contracts and tests. |
| L3 | Scaffolded | Files/classes/routes may exist, but behavior is incomplete. |
| L4 | Executable Manual | Human can run the flow with commands/tests. |
| L5 | Agent Executable | Agent can execute scoped tasks through receipts and gates. |
| L6 | Autonomous Restricted | Atlas can run the loop for low-risk work with evidence and rollback. |
| L7 | Self-Improving Governed | Atlas can propose and validate improvements to its own construction system. |
| L8 | Strategic Self-Construction | Atlas can choose high-leverage next work, justify it, execute safely and improve future execution. |

## Promotion Requirements

| Promotion | Required Proof |
|---|---|
| L0 -> L1 | Canonical doc and owner. |
| L1 -> L2 | AP/spec, acceptance criteria, risk and non-goals. |
| L2 -> L3 | Scaffold with tests or explicit scaffold marker. |
| L3 -> L4 | Passing manual command or test proving behavior. |
| L4 -> L5 | Agent can execute with Decision Receipt and gates. |
| L5 -> L6 | Repeated successful runs, rollback and drift checks. |
| L6 -> L7 | Learning proposals improve future runs without unsafe mutation. |
| L7 -> L8 | Priority engine, build graph and metrics prove strategic selection quality. |

## Anti-Confusion Rule

Never describe a capability as "ready" without its maturity level.

Good:

```text
Spec Operating System is L1/L2 documented and specified; runtime is not yet L5.
```

Bad:

```text
Spec Operating System is complete.
```

## Current Target Framing

Self-Construction OS begins as:

```text
Documentation maturity: L1/L2
Runtime maturity: L0/L1
Autonomous maturity: L0
```

The next step is runtime implementation that promotes selected low-risk slices
from L2 to L4, then L5.
