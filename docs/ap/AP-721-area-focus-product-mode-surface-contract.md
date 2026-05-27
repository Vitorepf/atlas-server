---
id: AP-721-area-focus-product-mode-surface-contract
type: architecture_proposal
title: AP-721 Area Focus Product Mode Surface Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Adds a read-only HTTP surface so Mission Control / Night Shift Product Mode can display an Area Focus Loop area (such as agentic_engineering_os) as a Desktop-ready read model — area summary, health, findings, inbox items, work orders, budgets, evidence packs, kill-switch state and next actions — without executing any mutation. It is a projection over the existing read-only Area Focus Loop, not a new OS, runtime or executor.
related_paths:
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - app/Services/Ai/NightShift/AreaFocusLoopReadModelService.php
  - app/Services/Ai/SoftwareCompany/AreaFocusProductModeSurfaceService.php
  - app/Http/Controllers/Ai/SoftwareCompanyStewardship/AreaFocusController.php
  - routes/api.php
  - tests/Feature/Ai/SoftwareCompany/AreaFocusControllerTest.php
  - tests/Unit/Ai/SoftwareCompany/AreaFocusProductModeSurfaceServiceTest.php
requires_evidence: true
risk_level: high
---
# AP-721 Area Focus Product Mode Surface Contract

> Atlas Software Company Stewardship Stack is a stack/capability family inside
> the Atlas Autonomous Software Company Runtime, not a new OS.

## Context

AP-712 shipped the read-only Area Focus Loop (`AreaFocusLoopReadModelService`,
namespace `App\Services\Ai\NightShift`) as a CLI projection. Product Mode /
Mission Control need the same governed view over HTTP so a Desktop cockpit can
render it. This AP adds that read surface, projecting over that canonical read
model.

## Decision

Add a **read-only HTTP read model** for one Area Focus area:

```text
GET /ai/software-company-stewardship/area-focus/{area}
```

- It is a projection over the existing Area Focus Loop. It creates no new OS, no
  parallel runtime and no executor.
- The GET runs **no mutating cycle**: no execution, no branch, no provider call,
  no merge/deploy/secrets, no destructive change.
- It is auth-gated by the canonical `atlas.token` middleware and ETag-cached on a
  deterministic surface hash.

## Desktop-ready schema

Schema `atlas.night_shift.area_focus_product_mode_surface.v1` exposes:

| Key | Meaning |
|---|---|
| `area_summary` | area_id, name, status, autonomy tier, owner docs, repo scope, stack identity |
| `health` | overall (healthy/watch/blocked) + scan status, blocker/high-risk/queued counts, WIP pressure, signals |
| `findings` | summary (by risk, by route) + finding list |
| `inbox_items` | Morning Inbox operator decision queue with decision options |
| `work_orders` | routed work as planned orders (route, owner service, branch isolation, never executed) |
| `budgets` | dev/forge budgets, WIP limit/used, queued, nothing consumed |
| `evidence_packs` | evidence requirement + packs (empty in read-only slice) |
| `kill_switch_state` | required + engaged flags (not engaged; operator-controlled) |
| `next_actions` | operator-facing next steps |

## Boundaries (HTTP-only this slice)

Because a parallel Area Focus implementation exists under
`app/Services/Ai/NightShift/` (separate effort), this slice ships **API + AP +
tests only** and does NOT add an `atlas-desktop` frontend surface, to avoid a
cross-effort UI collision. The JSON is Desktop-ready; a Desktop `AreaFocusSurface`
can be added once the canonical Area Focus service namespace is reconciled.

## Acceptance

- `GET .../area-focus/agentic_engineering_os` returns the canonical schema with
  all nine Desktop-ready keys, including `kill_switch_state`.
- Unknown area returns a stable error (HTTP 404, `code=unknown_area`).
- Missing/invalid `atlas.token` returns 401.
- The endpoint performs no mutation (read-only; `execution_executed=false`
  throughout; deterministic surface hash; ETag/304 supported).
- Feature and unit tests pass; docs-health and architecture-validate stay green.

## Forbidden in this slice

- Running a mutating Area Focus cycle from the GET.
- Executing Dev/Forge, creating branches, invoking a provider, merging or
  deploying.
- Creating a new OS, runtime or a parallel Area Focus executor.
- Touching the parallel `app/Services/Ai/NightShift/` effort.
