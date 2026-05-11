---
id: atlas-ai-self-construction-durable-reservation-collision-guard-blueprint-contract
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Collision Guard Blueprint Contract
status: active
category: architecture
priority: 100
summary: Read-only collision guard implementation blueprint for future durable reservation overlap checks, blockers, decisions and tests.
tags:
  - atlas-ai
  - self-construction
  - collision-guard-blueprint
  - durable-reservation
capabilities:
  - self_construction_os
  - reservation_ledger
  - collision_guard
decisions:
  - Durable reservation claims must pass a collision guard before repository writes.
  - Collision decisions must be explicit, explainable and hash-bound to packet scope.
  - Collision guard blueprint generation is read-only and cannot create runtime files, write storage, persist claims or dispatch work.
maintenance:
  - Update before changing collision guard inputs, blockers, decision states, overlap rules or tests.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Collision Guard Blueprint Contract

This contract defines the future collision guard implementation shape. It is
not runtime code and must not create PHP files or persist claims.

## Required Inputs

- candidate packet id;
- candidate packet hash;
- candidate allowed files;
- current active reservations;
- current changed files;
- hot forbidden scopes;
- packet dependency status;
- packet completion gate status.

## Required Blockers

- `hot_scope_forbidden`;
- `active_file_overlap`;
- `packet_hash_stale`;
- `dependency_incomplete`;
- `completion_gate_blocked`;
- `owner_conflict`.

## Decision States

- `allow_preview`;
- `allow_claim`;
- `block_claim`;
- `require_human_review`.

## Required Outputs

- decision state;
- blocker code;
- human-readable reason;
- conflicting reservation ids;
- conflicting file paths;
- packet hash used for decision;
- guard hash.

## Required Tests

- hot Voice/Kernel scope is blocked;
- overlapping active file scope is blocked;
- stale packet hash is blocked;
- incomplete dependency is blocked;
- clean disjoint packet can be claimable;
- guard explains conflicting reservation ids and file paths;
- blueprint command does not create PHP files or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only collision
guard blueprint that future implementation can convert into a guard service and
tests without guessing blockers, outputs or decision states.
