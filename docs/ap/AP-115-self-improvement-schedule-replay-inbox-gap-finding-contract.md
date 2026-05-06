# AP-115 — Self-Improvement Schedule Replay Inbox Gap Finding Contract

## Problem

AP-114 exposes missing Inbox hydration refs, but passive visibility is not enough.
If a Self-Improvement run emitted a proposal and the Inbox item no longer
resolves, the Curator must generate a reviewable repair proposal.

## Contract

`AtlasSelfImprovementRuntime::selfImprovementScheduleReplayFindings` must consume
`emitted_inbox_item_missing_ids` from `selfImprovementScheduleReportForWindow`.

When missing refs exist, it emits a finding with:

- `schema_version`: `atlas.self_improvement.schedule_replay_inbox_gap.v1`
- `dedupe_key` prefix: `self-improvement:schedule-replay-inbox-gap:`
- `review_signal.status`: `warning`
- `review_signal.severity`: `medium`
- `review_signal.recommended_action`: `restore_or_reemit_missing_self_improvement_inbox_items`
- `source_refs[].emitted_inbox_item_missing_ids`

## Enforcement

Architecture validation blocks unless runtime code, feature coverage, canonical
docs, and this AP doc all prove the finding contract.

## Status

Implemented in AP-115.
