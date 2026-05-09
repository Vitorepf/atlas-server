---
id: atlas-engineering-blueprint-schema-contracts
type: engineering_knowledge
title: Atlas Engineering Blueprint Schema Contracts
status: active
category: contracts
priority: 89
summary: Schema families and invariants for Engineering Blueprint payloads.
tags:
  - atlas
  - engineering
  - schema
capabilities:
  - engineering_blueprint
  - task_contracts
  - qa_evidence
decisions:
  - Blueprint schema evolution must be explicit, versioned, additive when possible and covered by tests.
maintenance:
  - Update when schema versions or payload fields change.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/archive/source-material/engineering-blueprint/contracts-full-2026-05-08.md
---

# Atlas Engineering Blueprint Schema Contracts

## Schema Families

| Schema | Role |
|---|---|
| `atlas.engineering.project_blueprint.v1` | Project-level objective, context, inventory, scenarios, data model, phase plan and QA/review policy. |
| `atlas.engineering.blueprint.v1` | Task-level executable blueprint. |
| `atlas.engineering.task_contract.v1` | Strong task contract with goal, context, scope and acceptance. |
| Inventory payloads | Components, routes, models, dependencies, states and risks. |
| Scenario payloads | Happy paths, edge cases, failure modes and visual/runtime checks. |
| QA evidence | Manual or automated proof tied to acceptance and gates. |
| Review finding | Category, severity, confidence, recommendation and evidence refs. |
| Postgres review | Migration/query/index/lock/performance evidence. |

## Invariants

- `schema_version` is explicit.
- `content_hash` is deterministic.
- Frozen records are immutable.
- Supersede points to the newer version.
- Stale task blueprints are visible when upstream contract/project blueprint changes.
- Blocking gates and missing evidence are directly exposed to surfaces.

## Compatibility

Schema evolution must be additive unless a migration and compatibility adapter
are shipped with tests. App types, API resources and CLI output must evolve in
the same implementation wave.
