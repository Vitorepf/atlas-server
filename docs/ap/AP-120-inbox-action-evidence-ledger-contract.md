# AP-120 — Inbox Action Evidence Ledger Contract

## Problem

Inbox actions were audited through the audit log, but they were not represented
in the Evidence Ledger. That meant human review actions such as `review_patch`
could be visible operationally while remaining invisible to replay,
Self-Improvement, and learning projections.

## Contract

When an Inbox action completes, `InboxActionRegistry` must record
`INBOX_ACTION_RECORDED` when `atlas_ledger_events` is available.
The canonical enum is `LedgerEventType::InboxActionRecorded`.

The ledger payload must use schema `atlas.inbox_action.v1` and preserve:

- action id
- idempotency key
- Inbox item identity, type, category, severity, status, source, and dedupe key
- actor type/id
- serialized action result
- `proposal_contract`
- `review_signal`
- `recommended_action`

For `review_patch`, the event must preserve direct refs from the action result,
including `diff_refs`, so proposal review is replayable without parsing raw
Inbox payloads.

## Enforcement

Architecture validation blocks unless the ledger event type, action registry,
feature coverage, canonical docs, and this AP doc all prove the contract.

## Status

Implemented in AP-120.
