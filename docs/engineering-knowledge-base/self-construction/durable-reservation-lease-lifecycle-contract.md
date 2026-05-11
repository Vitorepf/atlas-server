---
id: atlas-ai-self-construction-durable-reservation-lease-lifecycle-contract
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Lease Lifecycle Contract
status: active
category: architecture
priority: 100
summary: Read-only lease lifecycle contract for future durable reservation claim, renewal, release, expiry and completion timing semantics.
tags:
  - atlas-ai
  - self-construction
  - lease-lifecycle
  - durable-reservation
capabilities:
  - self_construction_os
  - reservation_ledger
  - parallel_session_safety
decisions:
  - Durable reservations must have explicit lease timing, renewal, release, expiry and completion semantics.
  - Expired leases must block completion and allow safe reclaim only through event-backed state transitions.
  - Lease lifecycle contract generation is read-only and cannot persist claims, storage, migrations or dispatch.
maintenance:
  - Update before changing lease duration, renewal policy, expiry semantics, completion semantics or reclaim rules.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Lease Lifecycle Contract

This contract defines future reservation timing semantics. It is not an
implementation and must not write storage.

## Lifecycle States

- `preview`: no durable claim exists.
- `claimed`: one owner holds an active lease.
- `renewed`: active lease was extended by its owner.
- `released`: owner intentionally released the packet.
- `expired`: lease exceeded expiry and cannot complete.
- `completed`: packet completion was recorded after gates.
- `blocked`: claim or completion was rejected by policy.

## Required Transitions

- `preview -> claimed`
- `claimed -> renewed`
- `claimed -> released`
- `claimed -> expired`
- `renewed -> released`
- `renewed -> expired`
- `claimed -> completed`
- `renewed -> completed`
- `any -> blocked` when guard policy rejects the action.

## Timing Rules

- Default lease duration must be explicit in config or policy.
- Renew requires same owner/session and active lease.
- Release requires same owner/session and active lease.
- Expire may be system-driven and must append an event.
- Completion requires active lease, same owner and passing completion gate.
- Expired or released leases cannot complete.

## Required Tests

- owner can renew active lease;
- non-owner cannot renew or release;
- expired lease cannot complete;
- released lease cannot complete;
- expired packet can be reclaimed after expiry event;
- completion requires packet completion gate pass;
- lifecycle command does not persist claims or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only lease
lifecycle packet with states, transitions, timing rules and tests for future
implementation.
