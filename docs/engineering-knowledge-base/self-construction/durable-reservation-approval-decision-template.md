---
id: atlas-ai-self-construction-durable-reservation-approval-decision-template
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Approval Decision Template
status: active
category: architecture
priority: 100
summary: Decision template for approving or rejecting durable reservation implementation without confusing template generation with approval.
tags:
  - atlas-ai
  - self-construction
  - approval-decision
  - durable-reservation
capabilities:
  - self_construction_os
  - approval_gate
  - decision_receipt
decisions:
  - Approval decisions must be explicit, signed and hash-bound to the request, AP candidate and plan.
  - A decision template is not an approval and must keep execution, migration, storage and dispatch disabled.
  - Approval may authorize implementation planning only; dispatch requires a separate future AP.
maintenance:
  - Update when approval states, signer rules or durable reservation post-approval limits change.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Approval Decision Template

This template defines how a future operator records approval or rejection for
durable reservation implementation.

## Decision Values

- `approved_for_scoped_implementation`: implementation may proceed only inside
  the approved AP scope.
- `rejected`: implementation remains blocked.
- `needs_revision`: the AP candidate or approval request must be updated.
- `expired`: hashes or evidence changed after review.

## Required Bindings

Every decision must bind to:

- approval request hash;
- AP candidate hash;
- durable ledger plan hash;
- multi-session readiness gate hash;
- signer identities;
- approved scopes;
- forbidden scopes;
- rollback strategy.

## Explicit Non Approval

This template does not approve anything by itself. A valid decision requires
human/operator signer data and an accepted decision value.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only decision
template with states, signer slots, scope limits, rollback, expiry checks and
non-execution guarantees.
