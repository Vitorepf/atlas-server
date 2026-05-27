---
id: AP-722-area-focus-operational-orchestrator-contract
type: architecture_proposal
title: AP-722 Area Focus Loop Operational Orchestrator and Certification
status: accepted
owner: programming
created_at: 2026-05-26
summary: Closes the Area Focus Loop operational for agentic_engineering_os by composing the existing slice owners into one read-only governed end-to-end cycle — AP-716 core read model, AP-717 finding engine scan, AP-718 inbox, AP-719 work order router and AP-720 evidence pack — and emitting an operational certification. It wires the previously orphaned deep finding engine (AP-717) and work order router (AP-719) into the operational path. It orchestrates scans, findings, inbox, work orders, evidence and certification only; it implements no fix, opens no branch, dispatches no work, and never merges, deploys, accesses secrets or makes destructive changes. It is not a new OS, not a parallel runtime and creates no new owner.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-716-area-focus-loop-core-read-model-contract.md
  - docs/ap/AP-717-agentic-engineering-os-area-finding-engine-contract.md
  - docs/ap/AP-718-area-focus-inbox-spec-draft-bridge-contract.md
  - docs/ap/AP-719-area-focus-dev-forge-router-contract.md
  - docs/ap/AP-720-area-focus-durable-cycle-evidence-pack-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusLoopOperationalOrchestratorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AgenticEngineeringOsFindingEngineService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusInboxService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusEvidencePackService.php
requires_evidence: true
risk_level: high
---
# AP-722 Area Focus Loop Operational Orchestrator and Certification

## Decision

The Area Focus Loop slices exist as discrete owners (AP-716..AP-721) but the
deep finding engine (AP-717) and the work order router (AP-719) are not wired
into a single operational path. AP-722 closes the loop operational for
`agentic_engineering_os` by composing the existing owners into one read-only,
governed, end-to-end cycle and emitting an operational certification.

Atlas Software Company Stewardship Stack é stack/capability family dentro do
Atlas Autonomous Software Company Runtime, não OS novo. AP-722 is a composition /
certification layer inside that stack. It is not a new OS, not a parallel runtime
and creates no new owner. This block stays read-only / decision-oriented: it
orchestrates scans, findings, inbox, work orders, evidence and certification; it
implements no fix and opens no mutating branch.

## Reuse Contract (no duplication)

AP-722 only conducts existing owners and reuses their output verbatim:

| Stage | Owner | AP | Reused method |
|---|---|---|---|
| core read model / readiness / contract | `AtlasAreaFocusLoopReadModelService` | AP-716 | `project` |
| deep finding scan | `AgenticEngineeringOsFindingEngineService` | AP-717 | `scan` |
| operator inbox | `AreaFocusInboxService` | AP-718 | `project` |
| work order routing | `AreaFocusDevForgeRouterService` | AP-719 | `project` |
| evidence pack | `AreaFocusEvidencePackService` | AP-720 | `build` |

It derives the router's governed budgets (`dev_budget`, `forge_budget`,
`wip_limit`, `risk_policy`) from the AP-716 area contract — it does not invent
its own governance.

## Schemas

```text
atlas.software_company_stewardship.area_focus_operational_cycle.v1
atlas.software_company_stewardship.area_focus_operational_certification.v1
```

## Operational Cycle

```text
core (AP-716) -> scan (AP-717) -> inbox (AP-718) -> work orders (AP-719)
-> evidence pack (AP-720) -> operational certification
```

Each stage output is embedded for the operator. The cycle assembles the AP-720
cycle shape (deterministic hashes of findings, inbox, work orders, input) and
builds the completeness-checked evidence pack. The operational `report_hash` is
computed over the stable stage hashes (excludes wall-clock), so the same input is
deterministic.

## Certification Contract

The operational certification declares:

- `core_resolved`, `scan_ran`, `inbox_projected`, `work_orders_routed`,
  `evidence_pack_complete`;
- `governance_read_only` (no execution, merge, deploy, secrets, destructive);
- `deterministic_hashes_present`;
- `no_parallel_runtime`, `no_new_os`.

Status is `passed` when every check holds, `blocked` when any stage is blocked,
`partial` otherwise. `operational = (status === passed)`.

## Acceptance

- The orchestrator composes AP-716..AP-720 into one read-only cycle for
  `agentic_engineering_os`.
- The deep finding engine (AP-717) and work order router (AP-719) are wired into
  the operational path.
- Router budgets come from the AP-716 area contract.
- The evidence pack reports completeness and `morning_inbox_ready`.
- The operational certification passes for a healthy, governed cycle.
- An unregistered/blocked area blocks the cycle.
- Deterministic `report_hash` for the same input.
- No execution, no branch, no dispatch, no merge/deploy/secrets/destructive.
- No parallel runtime; all stages reuse existing owners.
- docs-health and architecture-validate stay green.
