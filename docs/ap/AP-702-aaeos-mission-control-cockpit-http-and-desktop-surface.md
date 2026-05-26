# AP-702 AAEOS Mission Control Cockpit HTTP + Desktop Surface

Status: proposed
Owner: atlas-ai
Area: aaeos-mission-control
Risk: medium

## Problem

`AtlasMissionControlCockpitService` exists and aggregates AAEOS state
for an intent (phases + universal gates + departments + blockers).
There is no HTTP entry point and no Desktop React surface, so the
operator can only invoke it via `php artisan atlas:aaeos cockpit`
which is fine for headless use but does not deliver the
"Atlas-as-OS" visual cockpit.

## Goal

Deliver two slim, real artifacts:

1. `AtlasMissionControlCockpitController` (backend) exposing
   - `GET /atlas-code/aaeos/cockpit?intent=<id>` returns the canonical
     `atlas.aaeos.mission_control_cockpit.v1` snapshot. When no events
     are available for the intent (or no intent given), returns a
     baseline empty cockpit showing the 17-phase scaffold so the
     Desktop can render structure even before any work runs.
2. `MissionControlSurface` (Desktop React) under
   `apps/desktop/src/surfaces/mission-control/` with:
   - `useMissionControl.ts` hook that polls the endpoint every 5s,
   - `MissionControlSurface.tsx` rendering 6 panels (phase tracker,
     gate report, blockers, departments, signature pending, raw JSON
     fallback for audit).
   - `mission-control.css` editorial styling consistent with the
     existing slate-dark cream surfaces.

## Non Goals

- Not building the Evidence Ledger reader (handled separately).
- Not gating provider execution from the cockpit (read-only UI).
- Not adding routing/menu wiring into App.tsx (the surface module is
  importable; final navigation is operator's choice).

## Overlap Decision

| Candidate | Decision |
|---|---|
| `AtlasMissionControlCockpitService` | Reuse: controller calls `snapshot()` with empty inputs as baseline. |
| `apps/desktop/src/surfaces/control-plane` | Reuse pattern: new surface follows same module shape. |
| `apps/desktop/src/surfaces/atlas-ai/client.ts` | Reuse fetchJson + token + bridge mode patterns. |

## Required Docs

- `docs/engineering-knowledge-base/atlas-agentic-engineering-os.md`
- `docs/ap/AP-702-aaeos-mission-control-cockpit-http-and-desktop-surface.md` (this AP)

## Acceptance Criteria

- Route `GET /atlas-code/aaeos/cockpit` registered.
- Controller returns canonical `atlas.aaeos.mission_control_cockpit.v1`
  payload (baseline when no input given).
- Desktop surface module exists at
  `apps/desktop/src/surfaces/mission-control/`.
- Backend feature test covers baseline + a populated snapshot path.
- Frontend smoke test (manual import of surface from test entrypoint)
  validates no compile errors.

## Rollback

- Unregister route; delete controller + surface directory.

## Risks

- **Low**: poll every 5s is cheap; ETag could be added in follow-up.
- **Low**: surface menu wiring is intentionally left to operator to
  avoid forcing a navigation layout choice.

## What This AP Is NOT

- Not the Evidence Ledger reader.
- Not a write surface.
- Not Atlas Dev surface parity (AP-703).
