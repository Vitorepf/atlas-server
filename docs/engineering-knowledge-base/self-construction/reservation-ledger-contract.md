---
id: atlas-ai-self-construction-reservation-ledger-contract
type: engineering_knowledge
title: Atlas Self-Construction Reservation Ledger Contract
status: active
category: architecture
priority: 100
summary: Contract for durable local packet reservations that prevent multiple AI sessions from claiming the same work.
tags:
  - atlas-ai
  - self-construction
  - reservation-ledger
  - multi-agent
capabilities:
  - self_construction_os
  - packet_assignment
  - reservation_ledger
decisions:
  - Durable packet reservation requires an explicit ledger contract before any write implementation.
  - The current implementation uses a local append-only file ledger and projection.
  - A packet reservation must expire, be auditable, preserve disjoint write sets and block completed packets from being reclaimed.
maintenance:
  - Update before changing local ledger storage, lease renewal, claim release, completion or future Postgres promotion.
related_paths:
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Reservation Ledger Contract

Reservation Ledger is the durable local record that prevents two AI sessions
from owning the same packet at the same time. It currently stores append-only
events and a projection under `storage/app/atlas/self-construction`.

## Purpose

It must track:

- packet id;
- owner/session id;
- claim state;
- lease expiration;
- allowed files;
- forbidden files;
- packet hash;
- split hash;
- release reason;
- completion reason;
- completion timestamp;
- evidence hash.

## Non Goals

- Do not implement persistence in the current read-only phase.
- Do not grant execution authority.
- Do not replace Decision Receipt.
- Do not allow packet overlap.
- Do not reserve hot external scopes.

## Ledger Row Schema

```json
{
  "reservation_id": "RES-YYYYMMDD-0001",
  "packet_id": "AIP-SPLIT-...",
  "owner_id": "session-or-agent-id",
  "state": "preview | claimed | released | expired | completed | blocked",
  "lease_expires_at": "datetime",
  "packet_hash": "sha256",
  "split_hash": "sha256",
  "allowed_files": [],
  "forbidden_files": [],
  "completion_gate_hash": "sha256|null",
  "created_at": "datetime",
  "updated_at": "datetime"
}
```

## State Rules

- `preview` means no durable claim exists.
- `claimed` means exactly one owner holds the packet.
- `released` means work was intentionally returned.
- `expired` means owner timed out.
- `completed` means the owner reported packet work complete and provided
  optional evidence hash; it does not approve code, merge changes or bypass
  quality gates.
- `blocked` means scope, evidence or hot files prevent work.

## Collision Rules

A reservation must be blocked when:

- packet is already claimed and lease is active;
- packet was already completed;
- allowed files overlap another active reservation;
- packet hash changed after assignment;
- split hash changed after assignment;
- hot external scope appears in allowed files;
- completion gate is blocked.

## Local Durable Commands

Current implementation supports:

```text
--reservation-status
--claim-packet
--claim-next-packet
--complete-packet
--release-packet
```

`--complete-packet` persists packet state only. It does not grant approval,
dispatch work, enable execution or mark the whole Self-Construction OS complete.

## Completion Criteria

This contract is complete when Atlas can claim, release, complete and inspect
local packet reservations while preserving scope isolation and keeping dispatch,
approval and auto-merge as separate governed steps.
