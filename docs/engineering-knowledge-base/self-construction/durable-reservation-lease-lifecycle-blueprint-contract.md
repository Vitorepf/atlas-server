---
id: atlas-ai-self-construction-durable-reservation-lease-lifecycle-blueprint-contract
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Lease Lifecycle Blueprint Contract
status: active
category: architecture
priority: 100
summary: Read-only lease lifecycle implementation blueprint for durable reservation claim, renew, release, expire, reclaim and complete semantics.
tags:
  - atlas-ai
  - self-construction
  - lease-lifecycle-blueprint
  - durable-reservation
capabilities:
  - self_construction_os
  - reservation_ledger
  - lease_lifecycle
decisions:
  - Durable reservation lease transitions must be explicit before runtime implementation.
  - Completion must require active ownership, non-expired lease and passing packet completion evidence.
  - Lease lifecycle blueprint generation is read-only and cannot create runtime files, write storage, persist claims or dispatch work.
maintenance:
  - Update before changing lease states, transitions, timing rules, reclaim semantics or completion rules.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-blueprint-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Lease Lifecycle Blueprint Contract

This contract defines the future lease lifecycle implementation shape. It is
not runtime code and must not create PHP files or persist reservation state.

## Required States

- `preview`;
- `claimed`;
- `renewed`;
- `released`;
- `expired`;
- `completed`;
- `blocked`.

## Required Transitions

- `preview_to_claimed`;
- `claimed_to_renewed`;
- `claimed_to_released`;
- `claimed_to_expired`;
- `renewed_to_released`;
- `renewed_to_expired`;
- `claimed_to_completed`;
- `renewed_to_completed`;
- `released_or_expired_to_claimed_by_new_owner`;
- `any_to_blocked_when_guard_rejects_action`.

## Timing Rules

- default lease duration must come from config or policy;
- renew requires same owner/session and active lease;
- release requires same owner/session and active lease;
- expiry may be system-driven and must append an event;
- completion requires same owner, active lease and passing completion gate;
- expired or released leases cannot complete.

## Required Tests

- owner can renew active lease;
- non-owner cannot renew, release or complete;
- expired lease cannot complete;
- released lease cannot complete;
- expired packet can be reclaimed after expiry event;
- release packet can be reclaimed after release event;
- completion requires packet completion gate evidence;
- blueprint command does not create PHP files or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only lease
lifecycle blueprint that future implementation can convert into state handling
and tests without guessing transitions, timing rules or completion semantics.
