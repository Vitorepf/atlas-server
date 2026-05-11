---
id: atlas-ai-self-construction-collision-matrix-contract
type: engineering_knowledge
title: Atlas Self-Construction Collision Matrix Contract
status: active
category: architecture
priority: 100
summary: Contract for detecting packet scope overlap before parallel AI sessions claim or execute work.
tags:
  - atlas-ai
  - self-construction
  - collision-matrix
  - parallel-ai
capabilities:
  - self_construction_os
  - packet_queue
  - scope_validation
decisions:
  - Parallel work is unsafe until packet write scopes are proven disjoint.
  - Collision checks must include allowed files, forbidden scopes and withheld hot work.
  - The current phase may report collisions but must not mutate packet state.
maintenance:
  - Update before adding durable claims, automated dispatch or write-scope locking.
related_paths:
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Collision Matrix Contract

Collision Matrix is the read-only proof that parallel packets do or do not
overlap.

## Purpose

It must show:

- pairwise packet comparisons;
- allowed-file overlap;
- dependency relation;
- hot external scope exposure;
- collision decision;
- safe parallel groups;
- matrix hash.

## Non Goals

- Do not claim packets.
- Do not reserve packets.
- Do not rewrite packet scopes.
- Do not dispatch work.
- Do not override hot forbidden scopes.

## Pair Schema

```json
{
  "left_packet_id": "AIP-SPLIT-...",
  "right_packet_id": "AIP-SPLIT-...",
  "overlap": [],
  "dependency_related": false,
  "hot_scope_present": false,
  "collision": false,
  "decision": "parallel_safe|blocked"
}
```

## Collision Rules

A pair is blocked when:

- allowed files overlap;
- either side is withheld hot work;
- dependency relation requires ordering;
- either packet allows Voice/Kernel hot scope.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only matrix that
proves which packets may be parallelized without claims, dispatch or execution.
