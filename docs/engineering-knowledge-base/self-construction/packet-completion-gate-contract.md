---
id: atlas-ai-self-construction-packet-completion-gate-contract
type: engineering_knowledge
title: Atlas Self-Construction Packet Completion Gate Contract
status: active
category: architecture
priority: 100
summary: Contract for deciding whether a packet may be marked complete, remain blocked, or require human review.
tags:
  - atlas-ai
  - self-construction
  - completion-gate
  - evidence
capabilities:
  - self_construction_os
  - packet_completion_review
  - implementation_evidence
decisions:
  - Completion decisions must be derived from evidence report, not agent confidence.
  - Read-only completion gate may recommend, but never mark durable completion.
  - External blockers must prevent automated completion claims.
maintenance:
  - Update before implementing packet done states, completion persistence or evidence ledger writes.
related_paths:
  - docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Packet Completion Gate Contract

Packet Completion Gate converts packet evidence into a controlled completion
decision.

## Purpose

It must answer:

- can this packet be completed;
- what blocks completion;
- which evidence is missing;
- whether human review is required;
- what the next action must be.

## Non Goals

- Do not persist packet completion.
- Do not write evidence ledger events.
- Do not override packet evidence report.
- Do not authorize execution.
- Do not merge or clean up external hot changes.

## Gate Schema

```json
{
  "schema_version": "atlas.self_construction_packet_completion_gate.v1",
  "gate_id": "COMPLETION-GATE-YYYYMMDD-0001",
  "selected_packet_id": "AIP-SPLIT-...",
  "status": "blocked | human_review_required | completion_candidate",
  "execution_allowed": false,
  "completion_allowed": false,
  "durable_completion_written": false,
  "decision": "block | request_human_review | candidate_only",
  "blocking_reasons": [],
  "required_next_action": "string"
}
```

## Decision Rules

- If evidence report is blocked, status must be `blocked`.
- If evidence report is clean but no human review exists, status must be
  `human_review_required`.
- If evidence report is clean and human review exists, read-only status may be
  `completion_candidate`.
- `completion_allowed` remains false until durable completion persistence is
  implemented by a future AP.

## Required Evidence

The gate must inspect:

- selected packet id;
- evidence report hash;
- scope validator status;
- blocking reasons;
- external blockers;
- required gate statuses;
- required evidence statuses.

## Completion Criteria

This contract is complete when the gate can block false completion, request
human review for clean evidence, and keep durable completion disabled until a
future persistence layer exists.
