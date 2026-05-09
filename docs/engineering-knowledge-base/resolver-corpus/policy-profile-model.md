---
id: atlas-ai-resolver-corpus-policy-profile-model
type: engineering_knowledge
title: Atlas AI Resolver Corpus Policy Profile Model
status: active
category: architecture
priority: 86
summary: Compact resolver-derived model for Domain Profile, Flow Profile, Policy/Profile and Atlas Decide.
tags:
  - atlas-ai
  - policy-profile
  - atlas-decide
capabilities:
  - policy_profile_architecture
  - decision_receipt_governance
decisions:
  - Domain, flow, policy and model selection are separate layers.
maintenance:
  - Keep aligned with Kernel, Operating System and Model Selection Strategy.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
---

# Atlas AI Resolver Corpus Policy Profile Model

## Layering

```text
Atlas AI Core
-> Domain Profile
-> Flow Profile
-> Policy/Profile
-> Atlas Decide
-> Domain Orchestrator
-> Runtime / Executor
```

## Definitions

| Term | Meaning |
|---|---|
| Domain | Cognitive/operational vertical such as Programming, Finance or Learning. |
| Flow | Specific operational process inside a domain, such as `programming.dev`. |
| Profile | Layered policy bundle for domain, flow, surface, risk and session. |
| Model selection | Decision made by Atlas Decide under policy, not by the surface. |
| Decision Receipt | Signed/auditable contract authorizing runtime execution. |

## Examples

- `atlas dev` enters `programming.dev`.
- `atlas forge` enters `programming.forge`.
- `atlas fix` enters a Programming repair flow.
- Finance review uses Finance domain policy and never auto-executes trades.

## Invariant

Manual model override is allowed, but it is an audited override. It does not make
the provider or model the owner of the flow.
