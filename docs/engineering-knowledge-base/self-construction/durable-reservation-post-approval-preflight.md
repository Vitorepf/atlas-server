---
id: atlas-ai-self-construction-durable-reservation-post-approval-preflight
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Post-Approval Preflight
status: active
category: architecture
priority: 100
summary: Preflight contract for checking a signed durable reservation approval before any implementation, migration or storage write.
tags:
  - atlas-ai
  - self-construction
  - preflight
  - durable-reservation
capabilities:
  - self_construction_os
  - approval_gate
  - implementation_preflight
decisions:
  - A signed approval must pass preflight before any durable reservation implementation begins.
  - Preflight must verify hashes, signer slots, evidence, forbidden scopes and dispatch disablement.
  - Preflight generation is read-only and cannot create migrations, storage, claims or dispatch.
maintenance:
  - Update when post-approval checks, signer requirements or durable reservation evidence rules change.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Post-Approval Preflight

This contract defines the final read-only check that must pass after a future
approval decision is signed and before implementation begins.

## Required Checks

- approval decision is signed by all required roles;
- decision value is `approved_for_scoped_implementation`;
- approval request hash still matches;
- AP candidate hash still matches;
- durable ledger plan hash still matches;
- multi-session readiness gate hash still matches;
- docs-health and architecture-validate are passing;
- hot Voice/Kernel scopes are absent from allowed files;
- dispatch remains disabled.

## Blockers

Preflight blocks implementation when:

- any signer slot is missing;
- any hash drifted;
- any required evidence is absent;
- migration/storage scope is broader than approved;
- dispatch would be enabled;
- rollback strategy is missing.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only preflight
with checks, blockers, required evidence, implementation limits, rollback and
non-execution guarantees.
