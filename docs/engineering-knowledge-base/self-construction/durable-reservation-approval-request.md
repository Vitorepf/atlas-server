---
id: atlas-ai-self-construction-durable-reservation-approval-request
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Approval Request
status: active
category: architecture
priority: 100
summary: Approval request contract for promoting durable reservation work from AP candidate to explicitly authorized implementation.
tags:
  - atlas-ai
  - self-construction
  - approval
  - durable-reservation
capabilities:
  - self_construction_os
  - approval_gate
  - durable_claims
decisions:
  - Durable reservation implementation requires an explicit approval request before migrations or storage writes.
  - Approval must name signer roles, accepted risk, rollback, evidence and forbidden scopes.
  - Approval request generation remains read-only and cannot be treated as approval.
maintenance:
  - Update when signer roles, approval criteria or durable reservation promotion rules change.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Approval Request

This contract defines the approval payload required before durable reservation
implementation can touch migrations, storage, repository code or claim state.

## Approval Is Not Execution

Generating this request does not approve work. It only creates a deterministic
packet for a human/operator to review.

## Required Signers

- product governor;
- architecture governor;
- safety/governance reviewer;
- implementation operator.

## Approval Must Decide

- whether migrations/storage are allowed;
- whether the AP candidate is accepted as scoped;
- whether dispatch remains disabled after ledger activation;
- whether rollback evidence is sufficient;
- whether hot Voice/Kernel scopes remain forbidden.

## Required Evidence

- AP candidate hash;
- durable ledger plan hash;
- multi-session readiness gate hash;
- docs-health output;
- architecture-validate output;
- rollback strategy;
- explicit forbidden scopes.

## Blocking Conditions

Approval must be blocked when:

- candidate hash changed after review;
- plan hash changed after review;
- docs or architecture validation fail;
- hot scopes appear in allowed files;
- dispatch would be enabled in the same AP;
- rollback is missing.

## Completion Criteria

This contract is complete when Atlas emits a read-only approval request with
signers, decisions, blockers, evidence, rollback and non-execution guarantees.
