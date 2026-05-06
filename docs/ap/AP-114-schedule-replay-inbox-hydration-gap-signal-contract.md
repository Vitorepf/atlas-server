# AP-114 — Schedule Replay Inbox Hydration Gap Signal Contract

## Problem

Hydrating emitted Inbox refs is useful only if missing hydration is visible. A
schedule replay that returns UUIDs but silently omits missing `ai_inbox_items`
creates an audit blind spot.

## Contract

`selfImprovementScheduleReportForWindow` must expose hydration health in the
summary and per recent event:

- `emitted_inbox_item_hydration_available`
- `emitted_inbox_item_missing_ids`

The CLI must render:

- `Inbox hydration`
- `Missing inbox refs`

## Behavior

- If `ai_inbox_items` exists and all emitted refs resolve, missing refs is empty.
- If a ref does not resolve, it appears in `emitted_inbox_item_missing_ids`.
- If `ai_inbox_items` is unavailable, hydration availability is false and emitted
  refs remain visible as IDs.

## Enforcement

Architecture validation blocks unless replay code, CLI output, unit coverage,
CLI coverage, API coverage, canonical docs, and this AP doc prove the gap signal.

## Status

Implemented in AP-114.
