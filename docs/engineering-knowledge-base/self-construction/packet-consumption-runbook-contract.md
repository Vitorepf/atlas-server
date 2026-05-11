---
id: atlas-ai-self-construction-packet-consumption-runbook-contract
type: engineering_knowledge
title: Atlas Self-Construction Packet Consumption Runbook Contract
status: active
category: architecture
priority: 100
summary: Contract for the ordered steps an AI must follow after receiving an assigned implementation packet.
tags:
  - atlas-ai
  - self-construction
  - runbook
  - evidence
capabilities:
  - self_construction_os
  - packet_assignment
  - governed_implementation
  - implementation_evidence
decisions:
  - A selected packet must produce a deterministic runbook before implementation.
  - Completion requires evidence, not a narrative claim.
  - The runbook is read-only until a signed receipt permits scoped execution.
maintenance:
  - Update before changing how an AI consumes assigned packets or returns evidence.
related_paths:
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Packet Consumption Runbook Contract

Packet Consumption Runbook is the ordered instruction set an AI follows after
Atlas selects a packet for a session.

## Purpose

It must turn assignment preview into a safe execution ritual:

```text
inspect -> read packet -> confirm scope -> implement only allowed files
-> run gates -> validate scope -> return evidence -> stop
```

## Non Goals

- Do not persist claims.
- Do not authorize writes by itself.
- Do not replace Decision Receipt.
- Do not allow broad "continue everything" behavior.
- Do not accept completion without machine-readable evidence.

## Runbook Schema

```json
{
  "schema_version": "atlas.self_construction_packet_consumption_runbook.v1",
  "runbook_id": "RUNBOOK-YYYYMMDD-0001",
  "assignment_id": "ASSIGN-YYYYMMDD-0001",
  "selected_packet_id": "AIP-SPLIT-...",
  "execution_allowed": false,
  "claim_persisted": false,
  "steps": [],
  "required_gates": [],
  "required_evidence": [],
  "stop_conditions": [],
  "final_response_contract": []
}
```

## Mandatory Steps

Every runbook must include:

- inspect current worktree;
- read canonical docs and selected packet;
- confirm allowed and forbidden files;
- confirm execution policy;
- implement only if the user/governance has asked for implementation;
- run focused tests;
- run docs-health when docs changed;
- run architecture validate when architecture/governance docs changed;
- run Scope Validator;
- report evidence and residual risk.

## Evidence Contract

The final response must include:

- selected packet id;
- files changed by this AI;
- gates run;
- pass/fail status;
- scope validator status;
- known external blockers;
- what remains blocked.

## Stop Conditions

The AI must stop when:

- selected packet is missing;
- packet hash changed;
- forbidden file changed;
- unknown file changed;
- hot external file appears in assigned scope;
- required gate fails;
- user request conflicts with packet scope.

## Completion Criteria

The runbook is complete when another AI can follow it without conversation
history and finish one packet with evidence, while execution authority remains
separate from packet selection.
