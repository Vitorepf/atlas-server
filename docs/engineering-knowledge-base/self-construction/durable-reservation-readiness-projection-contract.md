---
id: atlas-ai-self-construction-durable-reservation-readiness-projection-contract
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Readiness Projection Contract
status: active
category: architecture
priority: 100
summary: Contract for feeding durable reservation projections into packet queue, collision matrix and multi-session readiness gates.
tags:
  - atlas-ai
  - self-construction
  - readiness-projection
  - durable-reservation
capabilities:
  - self_construction_os
  - reservation_ledger
  - multi_session_readiness
decisions:
  - Multi-session readiness must consume durable reservation projection state before allowing parallel AI work.
  - Packet queue and collision matrix must explain blocks from active reservations, leases, dependencies and hot scopes.
  - Local reservation projection may mark packets as claimed, but it must not dispatch work or enable autonomous execution.
maintenance:
  - Update before changing readiness inputs, queue states, projection summaries or multi-session gate blockers.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Readiness Projection Contract

This contract defines how durable reservation state feeds packet queue and
readiness decisions. Atlas now has a local file-backed projection that can mark
packets as `claimed` after `--claim-packet`, while dispatch and autonomous
execution remain disabled.

## Projection Inputs

- active reservations;
- expired reservations;
- completed packets;
- blocked packets;
- packet dependencies;
- allowed file scopes;
- hot forbidden scopes;
- current changed files;
- completion gate status.

## Derived Queue States

- `available`: no active reservation, dependencies complete and no hot scope.
- `claimed`: active local lease exists for the packet.
- `blocked_by_collision`: allowed files overlap an active claim.
- `blocked_by_dependency`: dependency packet is incomplete.
- `blocked_by_hot_scope`: packet touches forbidden hot files.
- `blocked_by_stale_hash`: packet hash changed after assignment.
- `completed`: durable completion exists.

## Readiness Outputs

- queue summary;
- claimable packet ids;
- blocked packet ids and reasons;
- active reservation owners;
- dependency unlock hints;
- multi-session decision;
- safe single-session fallback instruction.

## Required Tests

- active reservation removes packet from claimable queue;
- completed dependency unlocks dependent packet;
- hot scope blocks packet before queue assignment;
- stale packet hash blocks claim;
- multi-session gate can report `ready_for_multi_session_preview` when five
  cold-lane packets are available and the local durable ledger exists;
- claimed packet is removed from available packet count;
- single-session fallback remains available when parallel dispatch is blocked;
- projection command does not persist claims or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only projection
packet that future queue and readiness gates can implement without guessing
durable reservation semantics.
