---
id: atlas-engineering-blueprint-runbook
type: engineering_knowledge
title: Atlas Engineering Blueprint Runbook
status: active
category: runbook
priority: 96
summary: Compact operational index for Engineering Blueprint app, CLI, API and lifecycle runbooks.
tags:
  - atlas
  - engineering
  - runbook
  - cli
  - app
capabilities:
  - engineering_blueprint
  - task_contracts
  - qa_evidence
  - review_gates
  - context_pack_recall
decisions:
  - App, CLI and API must expose equivalent Engineering Blueprint operations.
  - Commands are wrappers over tested services.
  - Full historical runbook is archived; active operational detail lives in child docs.
maintenance:
  - Update when commands, routes, screens or services change.
  - Keep this file as an index under the line limit.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/README.md
  - docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md
  - docs/engineering-knowledge-base/archive/source-material/engineering-blueprint/runbook-full-2026-05-08.md
  - bin/atlas
  - app/Console/Commands/AtlasEngineeringRunCommand.php
  - app/Http/Controllers/EngineeringRunController.php
---

# Atlas Engineering Blueprint Runbook

This is the active runbook index. The full original runbook is preserved at
`archive/source-material/engineering-blueprint/runbook-full-2026-05-08.md`.

## Read Order

| Need | Read |
|---|---|
| Local index | `engineering-blueprint/README.md` |
| App, CLI and API operations | `engineering-blueprint/surfaces-runbook.md` |
| End-to-end blueprint lifecycle | `engineering-blueprint/lifecycle-runbook.md` |
| Historical full command detail | archived full runbook |

## Current Operating Principle

Engineering Blueprint exists to turn product/engineering intent into governed
task contracts, context, harness runs, QA, review, database checks, evidence and
memory deltas.

## Minimal Flow

```text
Project intent
-> Project Blueprint
-> Task Blueprint / Contract
-> Context Pack
-> Harness Run
-> QA / Review / Postgres Gates
-> Evidence Packet
-> Memory Delta
```

## Required Validation After Blueprint Work

```bash
php artisan test tests/Feature/Engineering
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server
```

## Failure Rule

If any required evidence, gate or context is missing, the system should pause,
record the gap and expose a review action instead of pretending the run is
complete.
