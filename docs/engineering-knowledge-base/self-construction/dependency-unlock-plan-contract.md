---
id: atlas-ai-self-construction-dependency-unlock-plan-contract
type: engineering_knowledge
title: Atlas Self-Construction Dependency Unlock Plan Contract
status: active
category: architecture
priority: 100
summary: Contract for showing which packet completions unlock later packets in the read-only queue.
tags:
  - atlas-ai
  - self-construction
  - dependency-unlock
  - packet-queue
capabilities:
  - self_construction_os
  - packet_queue
  - parallel_ai
decisions:
  - Parallel acceleration requires knowing which completed packet unlocks which next packet.
  - Unlock plans must remain read-only until durable completion and reservation exist.
  - Hot withheld work must not be unlocked by Self-Construction cold-lane packets.
maintenance:
  - Update before adding durable completion, automatic unlocks or queue state mutation.
related_paths:
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md
  - docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Dependency Unlock Plan Contract

Dependency Unlock Plan shows how completing one packet changes future work
availability, without mutating queue state.

## Purpose

It must show:

- blocked packets;
- dependencies for each blocked packet;
- which dependencies are already available;
- what would become assignable after completion;
- which work stays withheld;
- unlock plan hash.

## Non Goals

- Do not mark dependencies complete.
- Do not mutate queue state.
- Do not persist completion.
- Do not dispatch newly unlocked work.
- Do not unlock hot external work.

## Unlock Rule

A packet may become preview-assignable only when all dependencies have durable
completion evidence in a future phase. In the current phase, Atlas may only
preview the unlock relationship.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only unlock
plan with blocked packets, unlock candidates and withheld hot work.
