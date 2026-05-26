---
id: AP-707-self-directed-evolution-reuse-boundary-contract
type: architecture_proposal
title: AP-707 Self-Directed Evolution Reuse Boundary Contract
status: accepted
owner: atlas-ai
created_at: 2026-05-26
summary: Corrects Self-Directed Evolution documentation so future AI sessions reuse Self-Construction Subsystem Builder, Self-Improvement, AAEL, Spec OS and Evidence instead of creating duplicate self-evolution runtimes.
related_paths:
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
  - docs/engineering-knowledge-base/atlas-self-construction-catalog.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os-compaction-plan.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderService.php
requires_evidence: true
risk_level: high
---
# AP-707 Self-Directed Evolution Reuse Boundary Contract

## Decision

Self-Directed Evolution is a composition/read-model layer, not a new OS and not
a standalone self-programming runtime.

## Required Reuse

Any implementation must reuse these owners before adding new services:

| Capability | Existing owner |
|---|---|
| subsystem gap/proposal/approval | `AtlasSelfConstructionSubsystemBuilderService` |
| improvement backlog/delta/regression | Self-Improvement Runtime |
| evolution portfolio/opportunities | AAEL |
| spec/AP/doc proposal | Spec OS + Documentation Governance |
| counterfactual roadmap | TEOS-I3/I4 + ASRE/AARS |
| execution evidence | AVER/AVEOR/Evidence |

## Forbidden

- Creating a new gap detector that ignores Subsystem Builder, Self-Improvement or AAEL.
- Creating a proposal registry parallel to Self-Construction approval receipts.
- Treating Self-Directed Evolution as an auto-approval authority.
- Creating another Self-Construction OS, Self-Programming OS or AGOS-style OS.

## Acceptance

- Docs state existing runtime owners explicitly.
- Future implementation names are adapter/read-model names, not authority names.
- Operator Curation Receipt remains mandatory for promotion.
- docs-health passes.
