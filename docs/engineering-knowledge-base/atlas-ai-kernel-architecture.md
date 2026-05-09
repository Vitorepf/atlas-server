---
id: atlas-ai-kernel-architecture
type: engineering_knowledge
title: Atlas AI Kernel Architecture
status: active
category: architecture
priority: 100
summary: Compact authority index for the executable Atlas AI Kernel: Operation Envelope, Decision Receipt, Evidence Ledger, SDK contracts, failure domains, SLOs and architectural tests.
tags:
  - atlas-ai
  - kernel
  - architecture
  - typed-contracts
  - event-sourcing
capabilities:
  - kernel_specification
  - operation_envelope_contract
  - decision_receipt_v2_contract
  - evidence_ledger_event_sourcing
  - capability_registry_enforcement
  - domain_manifest_sdk
  - surface_adapter_contract
  - provider_driver_contract
  - architectural_test_doctrine
  - kernel_slo_governance
decisions:
  - Kernel is the executable architecture layer; product docs cannot bypass it.
  - Decision Receipt, Evidence Ledger, Policy/Profile and Provider/Surface/Domain contracts are mandatory for real execution.
  - The full original specification is archived as source material; focused child docs own active implementation detail.
maintenance:
  - Keep this file compact; add details to kernel/* child docs.
  - Run architecture validation after changing Kernel contracts.
  - Update child docs and canonical index when contracts move or mature.
related_paths:
  - docs/engineering-knowledge-base/kernel/contracts.md
  - docs/engineering-knowledge-base/kernel/static-scans.md
  - docs/engineering-knowledge-base/kernel/roadmap-ap-index.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
  - docs/engineering-knowledge-base/archive/source-material/kernel/atlas-ai-kernel-architecture-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
---

# Atlas AI Kernel Architecture

This is the compact executable authority for Atlas AI Kernel. The long mother
spec is preserved at
`archive/source-material/kernel/atlas-ai-kernel-architecture-full-2026-05-08.md`.

## Kernel Law

Every meaningful Atlas operation must pass through:

```text
Input -> Operation Envelope -> Intent/Routing -> Decide -> Decision Receipt
-> Domain/Profile/Flow -> Context -> Policy -> Runtime -> Gates -> Repair
-> Evidence Ledger -> Learning/Proposals -> Output
```

No Surface, Provider, Tool, Domain, Runtime, Curator or generated agent may
skip this chain for production behavior.

## Child Authorities

| Topic | Read |
|---|---|
| Envelope, Receipt, Ledger, SDKs, Policy, SLOs | `kernel/contracts.md` |
| Architecture tests and bypass prevention | `kernel/static-scans.md` |
| AP roadmap and implementation phases | `kernel/roadmap-ap-index.md` |
| Failure taxonomy and handler expectations | `kernel/failure-domain-taxonomy.md` |

## Hard Invariants

1. Surface does not decide.
2. Provider does not decide.
3. Tool does not decide.
4. Domain does not bypass Policy/Profile.
5. Runtime does not execute without Decision Receipt.
6. Repair returns through Policy, Receipt and Decide.
7. Everything important becomes Evidence.
8. Curator proposes critical changes; it does not silently self-apply them.
9. Repeated capability moves to Core.
10. Manual model/provider choice is an audited override.

## Contract Families

| Family | Purpose |
|---|---|
| Operation Envelope | canonical unit of work, trace, tenant/operator and attachments |
| Decision Receipt v2 | signed decision, provider/model, budget, gates, repair limits |
| Evidence Ledger | append-only operational truth and replay source |
| Capability Registry | prevents surface-only capabilities and duplicate ownership |
| Domain Manifest/SDK | lets domains plug in without new pipelines |
| Surface Adapter | collects input and renders output without deciding |
| Provider Driver | routes provider calls without owning Atlas identity |
| Failure Domain | closed taxonomy with handlers |
| SLO Targets | latency, quality, cost and reliability contract |

## Validation

```bash
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health --json
php artisan atlas:ai:docs-split-plan --json
```
