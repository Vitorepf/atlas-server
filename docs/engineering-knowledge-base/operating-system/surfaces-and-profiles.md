---
id: atlas-ai-os-surfaces-and-profiles
type: engineering_knowledge
title: Atlas AI OS - Surfaces And Profiles
status: active
category: architecture
priority: 100
summary: Surface aliases, canonical Domain/Flow Profiles and Atlas Decide authority for the Atlas AI operating system.
tags:
  - atlas-ai
  - surfaces
  - profiles
capabilities:
  - domain_flow_registry
  - surface_adapter_registry
  - model_selection_contract
decisions:
  - Commands and screens are surfaces only.
  - Domain Profile and Flow Profile prevent commands from becoming separate products.
  - Atlas Decide owns provider/model selection unless the operator makes an audited override.
maintenance:
  - Update when surface aliases, model selection modes or domain catalog fields change.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - app/Services/Ai/Kernel/Surface/SurfaceAdapterRegistry.php
  - app/Services/Ai/Kernel/Domain/AtlasAiDomainCatalogService.php
---

# Atlas AI OS - Surfaces And Profiles

## Surface Rule

Surfaces collect input and render output. They do not own domain logic, provider
selection, memory, gates or repair.

| Surface | Canonical entry |
|---|---|
| `atlas dev` | `atlas_cli_dev` -> `programming.dev` |
| `atlas forge` | `atlas_cli_forge` -> `programming.forge` |
| `atlas fix` | alias to `atlas_cli_dev` -> `programming.repair` |
| `atlas continue` | alias to `atlas_cli_dev` with resume contract |
| `atlas ask/chat` | `atlas_cli_chat` |
| API/app/mobile/voice/MCP | Surface Adapter -> Kernel pipeline |

Aliases preserve UX origin for audit while declaring canonical surface.

## Profiles

```txt
Domain Profile = vertical operational world
Flow Profile   = work mode inside that world
```

Examples:

- `programming.dev`, `programming.forge`, `programming.qa`, `programming.security`;
- `finance.market_research`, `finance.risk_review`;
- `personal_development.daily_review`, `personal_development.focus_plan`;
- `self_improvement.docs_drift_review`, `self_improvement.proposal_generation`.

Catalog authority is `atlas:ai:domains`, `GET /ai/domains` and the MCP/Open
Brain domain catalog tool.

## Atlas Decide

Atlas Decide is not a product flow. It compiles the operational decision:

- domain/flow/profile;
- provider/model and fallback;
- budget/risk/autonomy;
- gates and evidence contract;
- manual override audit.

Provider/model selection modes are closed vocabulary:

- `auto_best_allowed`;
- `auto_best_available`;
- `manual_override`.

Surfaces may pass operator preference; they do not decide model authority.
