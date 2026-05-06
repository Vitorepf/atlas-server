# AP-119 — CLI Inbox Review Action Result Parity Contract

## Problem

AP-118 made `review_patch` return a structured proposal review contract, but
`atlas:cli:inbox respond --json` discarded the action result and returned only
the updated Inbox item. The CLI therefore lost `recommended_action`, direct refs,
and `proposal_contract` unless callers parsed the item payload again.

## Contract

`atlas:cli:inbox respond --json` must return:

- `result`: the serialized action result from `InboxActionRegistry`
- `item`: the updated `AiInboxItemResource`

For `review_patch`, `result.payload` must preserve:

- `proposal_contract`
- `review_signal`
- `recommended_action`
- refs such as `diff_refs`

This keeps CLI parity with API/App and prevents local automation from creating a
parallel parser for proposal payloads.

## Enforcement

Architecture validation blocks unless CLI command code, feature coverage,
canonical docs, and this AP doc all prove the action result parity contract.

## Status

Implemented in AP-119.
