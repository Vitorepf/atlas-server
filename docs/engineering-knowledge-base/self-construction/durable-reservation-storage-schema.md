---
id: atlas-ai-self-construction-durable-reservation-storage-schema
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Storage Schema
status: active
category: architecture
priority: 100
summary: Storage contract for durable packet reservation events, projections, future migrations, indexes and invariants.
tags:
  - atlas-ai
  - self-construction
  - storage-schema
  - durable-reservation
capabilities:
  - self_construction_os
  - reservation_ledger
  - durable_claims
decisions:
  - Durable reservation storage must be event-backed, append-only and projection-readable.
  - Current storage is local file-backed under storage/app; future migrations must preserve the same invariants in Postgres.
  - Exactly one active reservation per packet and overlapping active file-scope blocks are mandatory.
  - Storage claims never grant dispatch, execution, auto-merge or hot-scope authority.
maintenance:
  - Update before changing durable reservation table names, states, indexes, invariants or migration gates.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Storage Schema

This contract defines the storage shape for durable reservation claims. The
current runtime uses:

- `events.jsonl`: append-only hash-chained reservation events;
- `projection.json`: current reservation projection;
- `ledger.lock`: local file lock for atomic claim/release operations.

The Postgres table shape below remains the promotion target for a later
enterprise backend migration.

## Tables

`atlas_self_construction_reservation_events`

- append-only event source for reservation lifecycle;
- stores reservation id, packet id, event type, actor, session, packet hash,
  allowed files hash, previous event hash, event hash and payload;
- supports audit, replay, tamper detection and rollback reasoning.

`atlas_self_construction_reservations`

- current projection rebuilt from events;
- stores packet id, owner/session, state, packet hash, allowed files hash, lease
  expiry, release/completion timestamps and blocker reason;
- supports fast collision checks and multi-session readiness.

## Required Invariants

- Events are append-only.
- Each event references the previous event hash when one exists.
- Exactly one active claimed reservation may exist per packet.
- Active reservations with overlapping allowed files block new claims.
- Expired leases cannot mark completion.
- Hot Voice/Kernel scopes are never claimable.
- Dispatch remains disabled by this schema.

## Required Tests

- migration contains hash, actor, state, lease and payload fields;
- duplicate active packet claim is blocked;
- overlapping active file scope is blocked;
- expired reservation cannot complete;
- released reservation can be reclaimed;
- projection can be rebuilt from events;
- schema command does not create migrations or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only schema
packet that future migrations can implement without guessing table shape,
indexes, states, invariants or tests.
