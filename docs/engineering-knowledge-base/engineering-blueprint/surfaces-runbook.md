---
id: atlas-engineering-blueprint-surfaces-runbook
type: engineering_knowledge
title: Atlas Engineering Blueprint Surfaces Runbook
status: active
category: runbook
priority: 88
summary: App, CLI and API entry points for operating the Engineering Blueprint System.
tags:
  - atlas
  - engineering
  - surfaces
capabilities:
  - engineering_blueprint
decisions:
  - Blueprint operations should keep app, CLI and API parity unless an omission is explicitly documented.
maintenance:
  - Update when app screens, commands or routes change.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
---

# Atlas Engineering Blueprint Surfaces Runbook

## App

- `Home > Projetos` exposes project/task engineering context.
- `Engenharia` panels show contract, blueprint, gates and evidence.
- `Home > Atlas Engineering` exposes benchmarks, knowledge, tool runtime, replay and runs.

Current maturity gap: app still needs a fully unified run-detail flow outside
benchmark context.

## CLI

```bash
atlas dev --task-id=<task-id>
atlas engineering run --task-id=<task-id> --workspace=/Users/vitorepf/develop/Atlas/atlas-server --sandbox=worktree --auto-test
atlas engineering replay --run-id=<run-id>
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server
```

## API

```text
GET  /tasks/{task}/engineering
POST /tasks/{task}/engineering/blueprint/freeze
POST /tasks/{task}/engineering/evidence
POST /tasks/{task}/engineering/runs
GET  /engineering/runs/{run}
```

## Parity Rule

If a Blueprint operation exists in one surface, the owner must decide whether
CLI, API and app need equivalent access or an explicit reason for omission.
