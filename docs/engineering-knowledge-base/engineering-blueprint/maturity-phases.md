---
id: atlas-engineering-blueprint-maturity-phases
type: engineering_knowledge
title: Atlas Engineering Blueprint Maturity Phases
status: active
category: maturity
priority: 88
summary: Phase matrix and final Definition of Done for the Engineering Blueprint System.
tags:
  - atlas
  - engineering
  - maturity
capabilities:
  - engineering_blueprint
  - project_blueprint_pipeline
decisions:
  - Engineering Blueprint maturity is measured by executable project-to-memory flow, not by documentation alone.
maintenance:
  - Update when a maturity phase changes status.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
---

# Atlas Engineering Blueprint Maturity Phases

## Seven Items

| Item | Current state | Remaining work |
|---|---|---|
| Product -> Blueprint -> Phase -> Task -> QA | Base implemented | UX maturity and real-use calibration. |
| Strong task contract | Near complete | Global enforcement and UI coverage. |
| Inventory, scenarios and wireframes | Base implemented | Dedicated wireframe refs and visual baselines. |
| Contingency policy | Partial | Events, escalation triggers and automatic blocking. |
| Manual QA evidence | Base implemented | Direct runtime screenshot capture in QA form. |
| Deep review | Base implemented | Broader automated finding sources. |
| Postgres review | Base implemented | Real EXPLAIN/history against target connections. |

## Phases

| Phase | Goal |
|---|---|
| 0 | Canonical documentation. |
| 1 | Project Blueprint pipeline. |
| 2 | Phase plan and task generation. |
| 3 | Inventory, scenarios and wireframes. |
| 4 | Professional manual QA. |
| 5 | Professional deep review. |
| 6 | Postgres engineering review. |
| 7 | Final app product surface. |
| 8 | Atlas-Bench and Memory Delta calibration. |

## Final DoD

- Project blueprint can be prepared, created, validated and frozen.
- Tasks are generated with strong contracts.
- Harness runs produce auditable evidence.
- QA, review and Postgres gates are visible and enforceable.
- App, API and CLI expose the same operational truth.
- Knowledge sync and Code Intelligence index stay current.
- Memory Delta is proposed through governed Memory Core policy.
