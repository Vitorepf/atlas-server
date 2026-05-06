# AP-116 — Self-Improvement Schedule Replay Inbox Gap Emission Contract

## Problem

AP-115 creates a reviewable finding when schedule replay detects missing Inbox
refs, but the finding must not remain a passive signal. When the operator runs
Self-Improvement with emission enabled, the repair proposal must enter the
standard Proposal Inbox path and stay linked from the Evidence Ledger.

## Contract

When `AtlasSelfImprovementRuntime::nightlyReview` receives a schedule replay
missing Inbox refs finding and `emit=true`, it must:

- emit through `ProposalInboxEmitter`
- preserve the finding schema and `review_signal`
- use recommended action `restore_or_reemit_missing_self_improvement_inbox_items`
- record `LEARNING_PROPOSED.emitted_to_inbox`
- record `LEARNING_PROPOSED.emitted_inbox_item_id`
- record `OPERATION_COMPLETED.emitted_inbox_item_ids`

The emitted proposal must include source refs with
`emitted_inbox_item_missing_ids` so review, repair, replay, and audit can trace
the broken Inbox references without recalculating schedule replay.

## Enforcement

Architecture validation blocks unless runtime code, feature coverage, canonical
docs, API/command validation parity, and this AP doc all prove the emission
contract.

## Status

Implemented in AP-116.
