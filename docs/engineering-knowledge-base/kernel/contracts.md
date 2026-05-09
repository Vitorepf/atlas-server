---
id: atlas-ai-kernel-contracts
type: engineering_knowledge
title: Atlas AI Kernel Contracts
status: active
category: architecture
priority: 100
summary: Focused contract map for Kernel executable objects: Envelope, Receipt, Ledger, SDKs, Policy, SLO, cost, identity and tenancy.
tags:
  - atlas-ai
  - kernel
  - contracts
capabilities:
  - operation_envelope_contract
  - decision_receipt_v2_contract
  - evidence_ledger_event_sourcing
  - surface_adapter_contract
  - provider_driver_contract
decisions:
  - Typed contracts beat prose in executable behavior.
  - Preview and dry-run use the same code path as execution with dry_run=true.
  - Operational tables are projections; Evidence Ledger is the replay source.
maintenance:
  - Add schema changes as additive versions.
  - Link concrete implementations from related_paths when promoted.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
---

# Kernel Contracts

## Core Objects

| Contract | Must contain |
|---|---|
| Operation Envelope | envelope id, operator/tenant, provenance, input refs, state, decision, execution, output, audit hash |
| Decision Receipt v2 | receipt id, schema, selected domain/flow/provider/model, budget, gates, repair policy, dry_run, signed_by, hashes |
| Ledger Event | event id, envelope id, type, payload hash, occurred_at, actor, schema version |
| Capability Manifest | capability id, owner, surfaces, gates, tests, authority group |
| Domain Manifest | domain id, flows, orchestrator, profiles, gates, memory projection |
| Surface Adapter | normalize input, declare capabilities, call Kernel, render output |
| Provider Driver | prepare call, inject identity, execute, normalize response, report telemetry |

## State Machines

Operation states: created, routed, decided, executing, gated, repairing,
completed, failed, blocked.

Decision receipt states: issued, attached, consumed, expired, superseded,
replayed.

Tool run states: planned, approved, invoked, returned, normalized, evidenced,
waived, failed.

## Policy/Profile

Policy/Profile compiles permissions, privacy, autonomy, cost, provider allowlist,
tool tier, repair limits, quality gates and redaction rules. No lower layer can
weaken hard Kernel invariants.

## Identity and Tenancy

Atlas identity persists across providers. OperatorId/TenantId are present from
day one so single-operator and multi-tenant deployments use the same shape.

## SLO and Cost

SLO targets are contracts, not dashboards. Cost is tracked per receipt and per
outcome so Atlas Decide can optimize quality per unit of compute.

