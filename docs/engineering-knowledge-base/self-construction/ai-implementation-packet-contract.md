---
id: atlas-ai-self-construction-ai-implementation-packet-contract
type: engineering_knowledge
title: Atlas Self-Construction AI Implementation Packet Contract
status: active
category: architecture
priority: 100
summary: Contract for the packet that lets another AI continue Atlas implementation from one short instruction.
tags:
  - atlas-ai
  - self-construction
  - ai-implementation-packet
  - multi-agent
capabilities:
  - self_construction_os
  - ai_implementation_packet
  - governed_implementation
decisions:
  - A packet is a bounded work contract, not a suggestion.
  - A packet must be executable by an AI that only receives "continue implementation".
  - A packet never grants authority beyond its allowed files, gates and evidence requirements.
maintenance:
  - Update before changing implementation-packet runtime, packet schema or multi-agent handoff behavior.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 240
---

# Atlas Self-Construction AI Implementation Packet Contract

AI Implementation Packet is the artifact Atlas emits so another AI can safely
continue Atlas construction from one short user instruction.

Target user instruction:

```text
continue a implementação
```

The AI must read the packet, implement only that packet, run the required gates
and return evidence.

## Purpose

The packet must answer:

- what to build;
- why this is the next correct block;
- which files may be changed;
- which files must not be touched;
- which gates prove the work;
- what evidence is required;
- when to stop and ask for review.

## Non Goals

- Do not authorize autonomous merge.
- Do not sign receipts by itself.
- Do not override AP law.
- Do not touch hot Voice, Kernel, provider, daemon or runtime scopes unless
  explicitly listed in the packet and receipt.
- Do not allow an AI to choose a different task because it seems more useful.

## Packet Schema

```json
{
  "schema_version": "1.0",
  "packet_id": "AIP-YYYYMMDD-0001",
  "operation_id": "OP-YYYYMMDD-0001",
  "status": "available | claimed | blocked | completed | stale",
  "lane": "self_construction | docs | tests | runtime_read_only | runtime_scoped",
  "objective": "string",
  "rationale": "string",
  "priority": 0,
  "risk_level": "low | medium | high | critical",
  "execution_allowed": false,
  "requires_human_signature": true,
  "context_docs": [],
  "allowed_files": [],
  "forbidden_files": [],
  "forbidden_actions": [],
  "acceptance_criteria": [],
  "required_gates": [],
  "required_evidence": [],
  "rollback_policy": "string",
  "dependencies": [],
  "stop_conditions": [],
  "scope_validator": {
    "required": true,
    "command": "php artisan atlas:ai:self-construction --scope-validator --json"
  },
  "packet_hash": "sha256"
}
```

## Hard Invariants

- `execution_allowed=false` until a governed receipt permits execution.
- `allowed_files` is the maximum write set.
- `forbidden_files` always wins over `allowed_files`.
- Every acceptance criterion must map to at least one evidence item.
- A packet must be small enough for one AI session to finish without owning
  unrelated architecture.
- One AI claims one packet at a time.
- A packet cannot assign work to files already marked hot by external work.

## Required Context

Every packet must include these docs when relevant:

- Self-Construction OS;
- Constitution;
- Structural Contract Gate;
- the specific subsystem contract;
- AP governing the work;
- ownership boundary or hot-file report;
- runtime roadmap when code is involved.

## AI Consumption Protocol

When an AI receives only "continue a implementação", it must:

```text
1. Run git status/diff inspection.
2. Read Self-Construction OS and this packet.
3. Confirm packet status is available or claimed by this session.
4. Confirm allowed and forbidden files.
5. Implement only the objective.
6. Run required gates.
7. Run Scope Validator.
8. Produce evidence and residual risk.
9. Stop if any forbidden file changes.
```

## Safe Packet Example

```json
{
  "packet_id": "AIP-20260510-0001",
  "lane": "self_construction",
  "objective": "Document Scope Validator contract and link it from AP-691.",
  "risk_level": "low",
  "execution_allowed": false,
  "allowed_files": [
    "docs/engineering-knowledge-base/self-construction/scope-validator-contract.md",
    "docs/ap/AP-691-atlas-self-construction-os-contract.md"
  ],
  "forbidden_files": [
    "runtimes/python/voice_realtime/**",
    "app/Services/Ai/Voice/**"
  ],
  "required_gates": [
    "docs-health",
    "architecture-validate",
    "git diff --check"
  ]
}
```

## Rejected Packet Example

Reject or block any packet that:

- has no `allowed_files`;
- permits broad directories such as `app/**` without sub-scope;
- omits gates;
- asks for implementation before its structural contract exists;
- overlaps hot files owned by another session;
- changes security, payment, provider, daemon, memory or runtime policy without
  explicit critical AP and receipt.

## Completion Criteria

A packet is complete only when:

- all acceptance criteria are satisfied;
- required gates passed or failures are explicitly external;
- Scope Validator reports no blocking violation;
- evidence is attached;
- residual risk is documented;
- no unrelated file was changed by the packet owner.
