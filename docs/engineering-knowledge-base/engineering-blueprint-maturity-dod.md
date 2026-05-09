---
id: atlas-engineering-blueprint-maturity-dod
type: engineering_knowledge
title: Atlas Engineering Blueprint Maturity And DoD
status: active
category: maturity
priority: 96
summary: Compact maturity and Definition of Done index for the Atlas Engineering Blueprint System.
tags:
  - atlas
  - engineering
  - maturity
  - dod
capabilities:
  - engineering_blueprint
  - project_blueprint_pipeline
  - task_contracts
  - qa_evidence
  - review_gates
  - postgres_gate
decisions:
  - Current implementation is a strong operational base, not the final mature product.
  - Maturity detail lives in a focused child doc to keep this index readable.
maintenance:
  - Update when a phase is completed.
  - Do not mark complete without tests, docs, CLI/API/app coverage when applicable and usage evidence.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/README.md
  - docs/engineering-knowledge-base/engineering-blueprint/maturity-phases.md
  - docs/engineering-knowledge-base/archive/source-material/engineering-blueprint/maturity-dod-full-2026-05-08.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
---

# Atlas Engineering Blueprint Maturity And DoD

This is the active maturity index. The full original maturity doc is preserved at
`archive/source-material/engineering-blueprint/maturity-dod-full-2026-05-08.md`.

## Current State

Atlas has an operational Engineering Harness foundation:

- task contracts;
- deterministic task blueprints;
- frozen snapshots;
- evidence;
- runs, attempts, controls, tests, scoring and replay;
- review findings;
- visual smoke and quality scan;
- Atlas-Bench;
- Knowledge Base and Code Intelligence;
- Super Tool Runtime phase 0.

## Remaining Product Maturity

| Area | Remaining work |
|---|---|
| UX | Unified Blueprint -> Run -> Review -> Evidence -> Promote flow. |
| Missing evidence | Global app filters for missing evidence/blocking gates. |
| Wireframes | Dedicated wireframe refs and visual baselines. |
| Calibration | Atlas-Bench and Memory Delta calibrated with real run volume. |
| Postgres | Real EXPLAIN/history against target connections. |

## Phase Detail

See `engineering-blueprint/maturity-phases.md`.

## Final DoD Rule

The system is complete only when a new AI session can take a project objective,
generate/freeze the blueprint, produce tasks, run harnesses, collect evidence,
pass review gates and promote memory without relying on hidden chat context.
