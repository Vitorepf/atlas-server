---
id: atlas-ai-self-construction-single-session-instruction-packet-contract
type: engineering_knowledge
title: Atlas Self-Construction Single Session Instruction Packet Contract
status: active
category: architecture
priority: 100
summary: Contract for canonical instruction/start packets a single AI session consumes when continuing Self-Construction work.
tags:
  - atlas-ai
  - self-construction
  - single-session
  - ai-instruction
capabilities:
  - self_construction_os
  - ai_session_bootstrap
  - governance_gate
decisions:
  - When automated dispatch is blocked, Atlas must still guide one safe session.
  - The instruction packet must be self-contained and executable by an AI without chat history.
  - `--codex-start-packet` may durably claim one packet and return a start contract.
  - `--codex-launch-plan` may generate multiple start commands, but each session must still claim through its own start packet.
  - The packet must not dispatch, auto-merge, enable execution authority or complete work.
maintenance:
  - Update before allowing automated dispatch, completion writes or execution authority.
related_paths:
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Single Session Instruction Packet Contract

Single Session Instruction Packet is the canonical packet an AI session can
consume when broader automated dispatch is blocked.

Codex Start Packet is the stronger operational entry point for parallel Codex
work:

```bash
php artisan atlas:ai:self-construction --codex-start-packet --actor=codex-a --session=session-a --json
```

It durably claims the next available packet, then returns the scope, gates,
evidence expectations and final response contract for that session.

## Purpose

It must include:

- selected packet id;
- one-line operator instruction;
- required first commands;
- allowed files;
- forbidden hot scopes;
- required gates;
- evidence expected;
- stop conditions;
- bootstrap and readiness hashes.
- durable claim state when using Codex Start Packet.

## Non Goals

- Do not dispatch work.
- Do not auto-start another process.
- Do not auto-merge.
- Do not grant execution authority beyond the user's active implementation
  request.
- Do not mark completion.
- Do not hide current blockers.

## Codex Start Contract

The start contract must include:

- claimed packet id;
- actor and session id;
- one-line user prompt;
- operator prompt for a Codex session with no chat history;
- mission;
- reservation id and lease expiry;
- claim hash;
- allowed files and hot forbidden scopes;
- explicit bootstrap command;
- explicit scope validator command;
- required first commands;
- required gates;
- evidence required in the final answer;
- implementation rules;
- final response contract;
- completion command;
- release command.

## Completion Criteria

This contract is complete when Atlas can emit either a deterministic read-only
instruction packet or a durable Codex Start Packet that lets one AI continue
safely without chat history, while dispatch, auto-merge and completion remain
separate governed steps.
