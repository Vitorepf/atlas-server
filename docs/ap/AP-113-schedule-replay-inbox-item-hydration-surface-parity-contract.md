# AP-113 — Schedule Replay Inbox Item Hydration Surface Parity Contract

## Problem

AP-112 hydrates `emitted_inbox_items` in the schedule replay read model, but the
operator surfaces that drive review loops must prove they preserve those
hydrated objects. UUID-only output is not enough for Observability or Open Brain.

## Contract

Observability and MCP Self-Improvement schedule replay responses must expose:

- `self_improvement_schedule_replay.emitted_inbox_items[].title`
- `self_improvement_schedule_replay.emitted_inbox_items[].review_signal.recommended_action`
- `self_improvement_schedule_replay.recent_events[].emitted_inbox_items[].title`

## Surfaces

- `GET /ai/observability`
- MCP `atlas_self_improvement_schedule_report`

## Enforcement

`KernelArchitectureStaticScanner::scanScheduleReplayInboxItemHydrationSurfaceParity`
blocks architecture validation unless both surfaces have feature coverage and
the canonical documentation names this contract.

## Status

Implemented in AP-113.
