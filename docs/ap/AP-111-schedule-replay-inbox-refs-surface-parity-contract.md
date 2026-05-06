# AP-111 — Schedule Replay Inbox Refs Surface Parity Contract

## Problem

AP-110 made `selfImprovementScheduleReportForWindow` join schedule observations
with terminal `OPERATION_COMPLETED` events and expose emitted Inbox proposal refs.
That is not enough if a surface serializes only the old schedule fields or if the
human CLI hides the refs operators need to open reviewable proposals.

## Contract

Every Self-Improvement schedule replay surface must preserve the completion and
Inbox reference projection:

- `completed_count`
- `emitted_count`
- `emitted_inbox_item_ids`
- `recent_events[].completed`
- `recent_events[].emitted_count`
- `recent_events[].emitted_inbox_item_ids`

The human CLI output must show:

- `Completed runs`
- `Emitted proposals`
- `Emitted inbox refs`

## Surfaces

- `atlas:ai:self-improvement-schedule-report --json`
- `atlas:ai:self-improvement-schedule-report`
- `GET /ai/self-improvement/schedule/report`
- `GET /ai/observability`
- MCP `atlas_self_improvement_schedule_report`

## Enforcement

`KernelArchitectureStaticScanner::scanScheduleReplayInboxRefsSurfaceParity`
blocks architecture validation unless the CLI, CLI test, API test,
Observability test, MCP test, canonical architecture doc, and this AP doc all
prove the parity contract.

## Status

Implemented in AP-111.
