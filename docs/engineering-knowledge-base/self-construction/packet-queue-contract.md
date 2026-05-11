---
id: atlas-ai-self-construction-packet-queue-contract
type: engineering_knowledge
title: Atlas Self-Construction Packet Queue Contract
status: active
category: architecture
priority: 100
summary: Contract for a queue of available, claimed, completed, blocked and withheld Self-Construction packets.
tags:
  - atlas-ai
  - self-construction
  - packet-queue
  - parallel-ai
capabilities:
  - self_construction_os
  - work_splitter
  - packet_assignment
decisions:
  - Parallel AI sessions need a queue view backed by durable reservation projection.
  - Queue ranks available work and reflects claimed/completed packets, but must not dispatch, execute or complete packets itself.
  - Hot external work must appear as withheld, not assignable.
maintenance:
  - Update before adding automated packet dispatch, completion writes or Postgres projection storage.
related_paths:
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Packet Queue Contract

Packet Queue is the work board a new AI session can inspect before choosing or
receiving a packet. It now consumes the local durable reservation projection so
claimed packets are removed from the available pool. Completed packets are also
removed from the claimable pool and can unlock dependent work when dependencies
exist.

## Purpose

It must show:

- assignable packets;
- claimed packets and lease owner/session;
- completed packets and completion owner/time;
- dependency-blocked packets;
- withheld hot work;
- selected recommended packet;
- disjoint allowed files;
- forbidden scopes;
- commands required before work;
- queue hash.

## Non Goals

- Do not dispatch work automatically.
- Do not execute work.
- Do not mark packet completion.
- Do not hide blocked or withheld work.

## Queue Entry Schema

```json
{
  "packet_id": "AIP-SPLIT-...",
  "lane": "docs|runtime_read_only|tests",
  "queue_state": "available|claimed|completed|blocked_by_dependency|withheld",
  "rank": 1,
  "active_reservation_id": "RES-...",
  "active_reservation_actor": "codex-a|claude-a|gemini-a|local-a",
  "active_reservation_session": "session-a",
  "lease_expires_at": "iso8601",
  "completed_reservation_id": "RES-...",
  "completed_at": "iso8601",
  "completion_actor": "codex-a|claude-a|gemini-a|local-a",
  "provider_profile": "codex|claude|gemini|local_agent|generic",
  "claim_policy": "single_owner",
  "allowed_files": [],
  "forbidden_files": [],
  "depends_on": [],
  "recommended": true
}
```

## Ranking Rules

Packets rank higher when:

- dependencies are empty;
- collision risk is low;
- allowed files are disjoint;
- packet can be validated with existing gates;
- packet does not touch hot Voice/Kernel scope.

## Required Guarantees

The queue preview must emit:

```text
execution_allowed=false
claim_persisted=false
ledger_write_allowed=false
queue_write_allowed=false
```

`claim_persisted=false` means the queue command itself did not claim. It may
still report packets claimed by `--claim-packet` or `--claim-next-packet`.
Completed packets may be written only by `--complete-packet`, which records
packet state but does not approve code, dispatch work, merge changes or bypass
quality gates.

## Completion Criteria

This contract is complete when Atlas can emit a deterministic queue with
available, claimed, blocked and withheld work while keeping dispatch and
completion disabled.
