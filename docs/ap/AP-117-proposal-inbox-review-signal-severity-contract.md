# AP-117 — Proposal Inbox Review Signal Severity Contract

## Problem

Proposal Inbox items preserved `review_signal` inside the payload, but the
public Inbox fields still used fixed `severity=info` and `priority_score=65`.
That made high-severity or blocking proposals look operationally harmless until
an operator opened the raw payload.

## Contract

`ProposalInboxEmitter` must map `review_signal.severity` into Inbox fields:

- `critical` and `high` become Inbox `severity=critical`
- `medium` and `low` become Inbox `severity=warning`
- `debug` becomes Inbox `severity=debug`
- unknown or missing severity becomes Inbox `severity=info`

The emitter must also derive `priority_score` from the same signal so critical
and high-severity proposals sort above routine informational proposals.

The original `review_signal.severity` must remain preserved inside
`payload.proposal_contract.review_signal` and the Context Bundle raw payload.

## Enforcement

Architecture validation blocks unless emitter code, unit coverage, canonical
docs, and this AP doc all prove the severity and `priority_score` contract.

## Status

Implemented in AP-117.
