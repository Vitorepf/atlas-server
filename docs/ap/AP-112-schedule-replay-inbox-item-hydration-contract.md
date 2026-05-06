# AP-112 — Schedule Replay Inbox Item Hydration Contract

## Problem

AP-110/AP-111 expose `emitted_inbox_item_ids`, but an operator still has to open
another table or UI to understand what those proposals are. For Self-Improvement
and Curator loops, refs must be directly actionable from replay surfaces.

## Contract

`selfImprovementScheduleReportForWindow` must hydrate emitted Inbox refs when
`ai_inbox_items` exists. The report must expose:

- `emitted_inbox_items[]`
- `recent_events[].emitted_inbox_items[]`

Each hydrated item preserves the stable review fields:

- `id`
- `status`
- `type`
- `category`
- `severity`
- `title`
- `source_type`
- `source_id`
- `deep_link`
- `review_signal`
- `created_at`
- `updated_at`

If the Inbox table does not exist, replay still succeeds and returns an empty
hydration list.

## Surface Rule

Human CLI output must include `Emitted inbox items` so operators can identify
the proposal without opening the raw Ledger.

## Enforcement

Architecture validation blocks unless replay code, CLI output, unit coverage,
CLI coverage, API coverage, canonical docs, and this AP doc all prove the
hydration contract.

## Status

Implemented in AP-112.
