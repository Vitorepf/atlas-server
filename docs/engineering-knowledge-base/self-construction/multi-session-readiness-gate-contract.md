---
id: atlas-ai-self-construction-multi-session-readiness-gate-contract
type: engineering_knowledge
title: Atlas Self-Construction Multi-Session Readiness Gate Contract
status: active
category: architecture
priority: 100
summary: Contract for deciding whether multiple AI sessions may safely proceed from queue, collision and unlock previews.
tags:
  - atlas-ai
  - self-construction
  - multi-session
  - readiness-gate
capabilities:
  - self_construction_os
  - parallel_ai
  - governance_gate
decisions:
  - Multi-session work requires an explicit readiness gate before dispatch.
  - The gate may recommend preview-only continuation but must not start sessions.
  - Durable parallel execution remains blocked until claims, reservations and evidence persistence exist.
maintenance:
  - Update before adding durable multi-session dispatch, auto-claim or execution.
related_paths:
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md
  - docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Multi-Session Readiness Gate Contract

Multi-Session Readiness Gate answers whether the current packet system is ready
for multiple AI sessions.

## Purpose

It must consolidate:

- packet queue;
- parallel session plan;
- collision matrix;
- dependency unlock plan;
- reservation preview state;
- blocking reasons;
- safe next instruction.

## Non Goals

- Do not start sessions.
- Do not dispatch packets.
- Do not persist claims.
- Do not write reservation ledger rows.
- Do not promote completion.

## Decision Values

- `preview_only_single_session`: one session can continue in read-only/scoped mode.
- `parallel_preview_ready_but_not_durable`: at least two disjoint packet-scoped
  sessions can proceed in preview mode, but durable reservation/dispatch is not
  enabled yet.
- `blocked_for_multi_session`: multiple sessions are unsafe.
- `ready_for_multi_session_preview`: multiple preview slots are safe, still without execution.
- `ready_for_durable_dispatch`: future value requiring durable claims and reservation ledger.

`ready_for_multi_session_preview` is the expected state after the local
reservation ledger exists and five cold-lane packets are available. It means
five Codex sessions can be manually started with packet-scoped bootstrap
commands and durable claims, while automated dispatch remains off.

## Hot Work Policy

Hot external Voice/Kernel work must remain withheld and visible. It is a
non-blocking warning for cold-lane parallel preview when the five cold-lane
packets are disjoint, and a hard blocker only if a selected packet attempts to
own or edit the hot scope.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only gate that
tells the operator whether multi-session continuation is safe and why.
