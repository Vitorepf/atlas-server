---
id: atlas-ai-kernel-roadmap-ap-index
type: engineering_knowledge
title: Atlas AI Kernel Roadmap AP Index
status: active
category: roadmap
priority: 98
summary: Focused AP and phase index for implementing the Atlas AI Kernel without expanding the mother spec.
tags:
  - atlas-ai
  - kernel
  - roadmap
  - ap
capabilities:
  - kernel_specification
  - architecture_validation
  - roadmap_handoff
decisions:
  - Kernel implementation should advance as APs, not new monolithic sections.
  - Each AP must declare contracts, services, migrations, tests, events and docs updated.
maintenance:
  - Move completed phase detail into AP docs or focused child specs.
  - Keep this as an implementation map, not a historical transcript.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/ap
  - docs/engineering-knowledge-base/evolution/implementation-handoff.md
---

# Kernel Roadmap AP Index

## Phase Map

| Phase | Purpose |
|---|---|
| 0 | Promote docs and establish canonical governance |
| 1 | Operation Envelope and Kernel Pipeline scaffold |
| 2 | Deterministic Decision Receipt v2 and replay |
| 3 | Evidence Ledger append-only and projections |
| 4 | Capability Registry and surface parity |
| 5 | Domain Manifest and SDK |
| 6 | Surface Adapter Contract |
| 7 | Provider Driver Contract |
| 8 | Failure Domains and handlers |
| 9 | SLO telemetry and cost |
| 10 | Domain expansion |
| 11 | Multi-tenancy hardening |
| 12 | Self-Evolution Curator |

## Active AP Families

| Family | Examples |
|---|---|
| Retrieval and Open Brain | AP-100 to AP-105 |
| Learning proposals and inbox | AP-106 to AP-125 |
| Architecture operations | AP-126 to AP-133 |
| Decision receipt replay | AP-134 to AP-140 |
| Ledger projections | AP-141 to AP-145 |
| Provider performance | AP-146 to AP-147 and AP-99 family |
| Documentation governance | AP-173 to AP-177 |
| Agent workflow governance | AP-200 family |
| External graph candidates | AP-684 Graphify External Graph Harness |
| Recurring agent behavior schedule | AP-161 keeps `agent_behavior_review` in the default recorrente set across 13 flow profiles |

## AP Acceptance

An AP is not done until:

1. contract is documented;
2. migration/model/service exists when needed;
3. events and read models are declared;
4. CLI/API/MCP surfaces are aligned when applicable;
5. tests include happy path and guard path;
6. docs-health and architecture validation are run;
7. Knowledge sync/index is refreshed.
