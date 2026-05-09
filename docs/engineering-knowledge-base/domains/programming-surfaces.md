---
id: atlas-ai-programming-surfaces
type: engineering_knowledge
title: Atlas AI Programming Surfaces
status: active
category: architecture
priority: 90
summary: Surface-to-flow contract for Programming domain.
tags:
  - atlas-ai
  - programming
  - surfaces
capabilities:
  - programming_domain
  - programming_orchestrator
decisions:
  - Programming surfaces are thin adapters into `programming.*` flows; none becomes a parallel product.
maintenance:
  - Update when Programming surfaces or surface contracts change.
related_paths:
  - docs/engineering-knowledge-base/domains/programming.md
---

# Atlas AI Programming Surfaces

| Surface | Flow contract |
|---|---|
| `atlas dev` | `programming.dev` |
| `atlas forge` | `programming.forge`, `atlas_cli_forge`, `engineering_harness`, evidence required |
| `atlas fix` | thin alias of `atlas dev --repair`, `programming.repair`, `dev_repair_executor` |
| `atlas continue` | resume contract preserving profile, intent, model and Open Brain |
| `atlas:ai:chat --dev` | `programming.*` by mode/task with chat contract |
| App/API/MCP | `domain_id=programming`, `flow_id=programming.*` |

`ProgrammingSurfaceContractFactory` owns forge, fix, continue and chat contracts.
Manual model overrides are audited through `ModelSelectionContractFactory` with
`authority=atlas_decide`; surfaces do not own model selection.
