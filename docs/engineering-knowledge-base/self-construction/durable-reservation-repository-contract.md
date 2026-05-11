---
id: atlas-ai-self-construction-durable-reservation-repository-contract
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Repository Contract
status: active
category: architecture
priority: 100
summary: Repository contract for durable local reservation claims, event appends, projections, locks, leases and future completion recording.
tags:
  - atlas-ai
  - self-construction
  - repository-contract
  - durable-reservation
capabilities:
  - self_construction_os
  - reservation_ledger
  - durable_claims
decisions:
  - Durable reservation writes must pass through a narrow repository contract, never direct ad hoc table mutation.
  - Repository methods must append events first and update projections from accepted events.
  - The current implementation uses a local file-backed ledger; Postgres migration remains a future promotion path.
  - Reservation claims do not enable dispatch, auto-merge or code execution authority.
maintenance:
  - Update before changing durable reservation method names, error states, lock policy, lease policy or completion semantics.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Repository Contract

This contract defines the application boundary for durable reservation claims.
Atlas currently implements the first local ledger version as an append-only
event log plus projection under `storage/app/atlas/self-construction`.

This local implementation may persist packet claims, releases and completions,
but it must not dispatch work, approve code, enable autonomous execution,
auto-merge, or mutate hot
Voice/Kernel scopes.

## Required Methods

- `claim(packet, actor, scope, lease)`: atomically claim an available packet.
- `renew(reservation, actor, lease)`: extend an active lease owned by actor.
- `release(reservation, actor, reason)`: release an active claim.
- `expire(now)`: mark expired leases from the event stream.
- `complete(reservation, actor, evidence)`: record owner-reported packet
  completion only after gates have been run by the session.
- `current(packet)`: read projection for one packet.
- `activeCollisions(scope)`: return active overlapping file claims.
- `rebuildProjection(reservation)`: rebuild current state from events.

## Required Errors

- `packet_already_claimed`;
- `packet_already_completed`;
- `allowed_files_overlap_active_reservation`;
- `hot_scope_forbidden`;
- `packet_hash_stale`;
- `lease_expired`;
- `completion_gate_missing`;
- `actor_not_owner`;
- `event_chain_mismatch`.

## Transaction Rules

- Acquire packet and allowed-file-scope lock before claim.
- Append event before projection update.
- Reject projection update when event hash chain is broken.
- Treat release and completion as terminal for the current lease.
- Never enable dispatch from repository methods.

## Required Tests

- claim writes claim event then projection;
- duplicate active claim is rejected;
- overlapping allowed files are rejected;
- non-owner cannot release or complete;
- expired lease cannot complete;
- stale packet hash is rejected;
- projection rebuild matches current projection;
- repository blocks duplicate packet claims;
- repository blocks completed packets from being claimed again;
- repository blocks overlapping active allowed-file claims;
- repository can release an owner-matching active claim;
- repository can complete an owner-matching active claim;
- repository status exposes active reservations without dispatch.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only repository
packet with methods, errors, transaction rules and tests for future
implementation.
