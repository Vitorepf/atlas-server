# AP-118 — Proposal Review Action Contract

## Problem

Proposal Inbox items had a `review_patch` action, but the action result exposed
only the raw payload. Every surface would need to know how to find
`proposal_contract`, `review_signal`, `recommended_action`, and refs inside that
payload. That recreates surface-specific parsing and weakens the mother
architecture rule: surfaces render contracts; they do not infer them.

## Contract

`InboxActionRegistry::readOnlyResult` must expose these fields directly for
`review_patch`:

- `proposal_contract`
- `review_signal`
- `recommended_action`
- `source_refs`
- `trace_refs`
- `job_refs`
- `file_refs`
- `diff_refs`
- original `payload`

The original payload remains available for backwards compatibility, but App,
CLI, API, and future surfaces should consume the direct fields first.

## Enforcement

Architecture validation blocks unless action code, feature coverage, canonical
docs, and this AP doc all prove the `review_patch` proposal review contract.

## Status

Implemented in AP-118.
