---
id: atlas-ai-self-construction-work-splitter-contract
type: engineering_knowledge
title: Atlas Self-Construction Work Splitter Contract
status: active
category: architecture
priority: 100
summary: Contract for splitting Atlas construction into safe disjoint packets for parallel AI sessions.
tags:
  - atlas-ai
  - self-construction
  - work-splitter
  - multi-agent
capabilities:
  - self_construction_os
  - work_splitter
  - parallel_implementation
decisions:
  - Parallel AI work is allowed only through disjoint write sets.
  - Hot external files block assignment, not validation.
  - Work Splitter must prefer fewer safe packets over many risky packets.
maintenance:
  - Update before changing packet assignment, reservation or parallel work policies.
related_paths:
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 240
---

# Atlas Self-Construction Work Splitter Contract

Work Splitter converts Atlas construction backlog into packets that multiple AI
sessions can implement without colliding.

## Purpose

It must let the user run several sessions with a short command while Atlas keeps
ownership, order and scope safe.

```text
user -> continue a implementação
AI -> asks Atlas for packet
Atlas -> emits one safe disjoint packet
AI -> implements only that packet
```

## Non Goals

- Do not maximize concurrency at the cost of safety.
- Do not split work across the same file unless explicitly read-only.
- Do not assign hot Voice/Kernel/provider/daemon files to Self-Construction
  packets.
- Do not create autonomous merge authority.

## Splitter Input

```json
{
  "backlog": [],
  "current_git_status": [],
  "hot_scopes": [],
  "dependency_graph": [],
  "available_lanes": [],
  "max_packets": 5,
  "risk_policy": "conservative"
}
```

## Packet Output

```json
{
  "split_id": "SPLIT-YYYYMMDD-0001",
  "packets": [
    {
      "packet_id": "AIP-YYYYMMDD-0001",
      "lane": "self_construction",
      "objective": "string",
      "allowed_files": [],
      "forbidden_files": [],
      "depends_on": [],
      "collision_risk": "none | low | medium | high",
      "claim_policy": "single_owner",
      "status": "available"
    }
  ],
  "withheld_work": [],
  "blocking_reasons": []
}
```

## Lane Model

| Lane | Purpose | Default risk |
|---|---|---|
| `docs` | Documentation contracts, indexes, AP updates | low |
| `packet_contracts` | Packet, splitter, validator, runbook and completion contracts | low |
| `command_surface` | CLI option surface for read-only Self-Construction commands | low |
| `readiness_service` | Readiness service payloads and packet orchestration logic | low/medium |
| `tests` | Focused test additions for existing read-only surfaces | low |
| `runtime_read_only` | Commands/services that only inspect and report | medium |
| `runtime_scoped` | Narrow execution behind receipt and gates | high |
| `hot_external` | Files owned by another active front | blocked |

## Five-Session Cold-Lane Split

The operational split for parallel Codex continuation must expose five
dependency-free cold-lane packets before any durable dispatch exists:

1. `AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001`: root Self-Construction docs,
   constitution, structural gate and AP-691.
2. `AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002`: packet, splitter,
   validator, runbook, evidence and completion contracts.
3. `AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003`: CLI command surface only.
4. `AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004`: readiness service logic only.
5. `AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005`: focused feature tests only.

These five packets are preview-assignable in parallel because their write sets
are disjoint. Hot Voice/Kernel work stays withheld and visible, but it does not
block cold-lane preview planning.

## Disjoint Write-Set Rules

- Two packets may not write the same file.
- Two packets may not write parent/child ownership scopes that imply the same
  generated index unless the index update is assigned to one packet.
- Docs and tests may run in parallel only when their related_paths and test
  fixtures do not overlap.
- Runtime packets must own service, command and tests together unless the split
  explicitly assigns one side as read-only documentation.
- Hot external files must be listed in every packet as forbidden.

## Assignment Policy

The splitter chooses packets in this order:

```text
1. documentation contracts blocking future runtime;
2. schema and example completion;
3. read-only command and service surfaces;
4. focused tests for read-only surfaces;
5. scoped execution only after signatures and receipts.
```

## Claim Policy

A packet may be:

- `available`: no owner yet;
- `claimed`: one AI owns it for the current session;
- `blocked`: dependency, hot file or missing contract;
- `completed`: evidence submitted and validator clean;
- `stale`: status changed since packet hash was emitted.

If a packet is stale, the AI must stop and request a fresh packet.

## Collision Detection

Collision risk is `high` when:

- any write file is already modified by another lane;
- any write file is inside a hot scope;
- migrations, routes, providers, daemons or runtime entrypoints are involved;
- packet lacks a scope validator command.

Collision risk `high` blocks assignment.

## Failure Modes

- Too many packets: emit fewer, safer packets.
- Shared file needed: assign that file to exactly one packet.
- Hot scope detected: withhold the affected packet.
- Missing structural contract: emit documentation packet first.
- Unknown generated file: mark unknown and require human review.

## Completion Criteria

Work Splitter is ready when it can emit up to five non-overlapping packets, each
with objective, allowed files, forbidden files, dependencies, gates and evidence
requirements, while withholding unsafe or hot work.
