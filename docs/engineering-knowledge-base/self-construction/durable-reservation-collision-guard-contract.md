---
id: atlas-ai-self-construction-durable-reservation-collision-guard-contract
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Collision Guard Contract
status: active
category: architecture
priority: 100
summary: Read-only collision guard contract for future durable reservation claims, hot scopes, overlapping files, stale hashes and dependency blockers.
tags:
  - atlas-ai
  - self-construction
  - collision-guard
  - durable-reservation
capabilities:
  - self_construction_os
  - reservation_ledger
  - parallel_session_safety
decisions:
  - Durable reservation claims must be blocked when packet scope overlaps active reservations.
  - Hot Voice/Kernel scope must be rejected before any claim, dispatch or completion path.
  - Collision guard contract generation is read-only and cannot persist claims, storage, migrations or dispatch.
maintenance:
  - Update before changing overlap rules, hot-scope rules, stale hash policy or dependency blockers.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md
  - docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Collision Guard Contract

This contract defines the future guard that decides whether a packet can be
claimed safely. It is not an implementation and must not write storage.

## Collision Inputs

- candidate packet id;
- candidate allowed files;
- candidate forbidden files;
- candidate packet hash;
- candidate dependency ids;
- active reservation projections;
- hot forbidden scope list;
- current changed files;
- completion gate status.

## Blocking Decisions

- `hot_scope_forbidden`: candidate touches hot Voice/Kernel scope.
- `active_file_overlap`: candidate overlaps another active reservation.
- `packet_hash_stale`: packet hash changed since assignment.
- `dependency_incomplete`: required dependency is not complete.
- `completion_gate_blocked`: completion evidence cannot support claim.
- `owner_conflict`: active lease is owned by another session.

## Required Outputs

- decision: `allow_preview | block_claim | require_human_review`;
- blocking reasons;
- overlapping files;
- active reservation ids;
- stale hashes;
- dependency blockers;
- hot-scope matches;
- required next command.

## Required Tests

- hot Voice/Kernel scope is blocked;
- overlapping active file scope is blocked;
- stale packet hash is blocked;
- incomplete dependency is blocked;
- clean disjoint packet remains preview-allowable;
- collision guard command does not persist claims or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only collision
guard packet that future claim code can implement without guessing blocking
rules or review states.
