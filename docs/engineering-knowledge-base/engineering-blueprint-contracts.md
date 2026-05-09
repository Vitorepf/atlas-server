---
id: atlas-engineering-blueprint-contracts
type: engineering_knowledge
title: Atlas Engineering Blueprint Contracts
status: active
category: contracts
priority: 97
summary: Compact contract index for Engineering Blueprint schemas, invariants and compatibility rules.
tags:
  - atlas
  - engineering
  - contracts
  - schema
capabilities:
  - engineering_blueprint
  - task_contracts
  - scenario_inventory
  - qa_evidence
  - review_gates
  - postgres_gate
decisions:
  - Operational contracts live in Postgres and versioned payloads.
  - Markdown describes schema, invariants and evolution expectations.
  - Detailed schema summaries live in focused child docs.
maintenance:
  - Update when payloads, migrations, models, controllers or app types change.
  - Every schema change requires API/CLI tests and Code Intelligence update.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/README.md
  - docs/engineering-knowledge-base/engineering-blueprint/schema-contracts.md
  - docs/engineering-knowledge-base/archive/source-material/engineering-blueprint/contracts-full-2026-05-08.md
  - app/Services/Engineering/EngineeringTaskContractService.php
  - app/Services/Engineering/EngineeringBlueprintService.php
  - app/Models/AtlasEngineeringBlueprint.php
---

# Atlas Engineering Blueprint Contracts

This is the active contract index. The full original contract doc is preserved at
`archive/source-material/engineering-blueprint/contracts-full-2026-05-08.md`.

## Sources Of Truth

| Information | Primary source |
|---|---|
| Architecture and rules | Engineering Blueprint docs in this KB. |
| Frozen blueprints | Postgres blueprint tables. |
| QA/review/db evidence | Postgres evidence, test runs and artifacts. |
| Runs and attempts | Engineering Harness Runner tables. |
| Code location | Code Intelligence Index. |
| Reusable memory | Memory Core. |

## Contract Families

| Contract | Detail |
|---|---|
| Project Engineering Blueprint | `engineering-blueprint/schema-contracts.md` |
| Task Engineering Blueprint | `engineering-blueprint/schema-contracts.md` |
| Engineering Task Contract | `engineering-blueprint/schema-contracts.md` |
| Inventory and Scenarios | `engineering-blueprint/schema-contracts.md` |
| QA Evidence | `engineering-blueprint/schema-contracts.md` |
| Review Finding | `engineering-blueprint/schema-contracts.md` |
| Postgres Review | `engineering-blueprint/schema-contracts.md` |
| Context Pack | Memory/Open Brain docs plus schema summary. |

## Hard Invariants

- Frozen payloads are immutable; changes create new version/supersede.
- Hashes are deterministic over canonicalized payloads.
- Task contract and blueprint snapshot are mandatory execution context.
- Missing blocking gates or evidence must be visible to app/API/CLI.
- Compatibility changes require tests and docs update in the same wave.

## Validation

```bash
php artisan test tests/Feature/Engineering
php artisan atlas:ai:architecture-validate --json
```
