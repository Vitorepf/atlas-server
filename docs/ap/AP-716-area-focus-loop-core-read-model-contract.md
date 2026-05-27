---
id: AP-716-area-focus-loop-core-read-model-contract
type: architecture_proposal
title: AP-716 Area Focus Loop Core Read-Only Runtime Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Adds the first real read-only runtime/read-model of the Area Focus Loop inside the Atlas Software Company Stewardship Stack. It resolves a canonical area contract (first area_id agentic_engineering_os), resolves owner docs, computes readiness, emits coarse finding seeds, a health summary, claim policy, evidence refs and next actions. It is read-only, reuses existing owners and is NOT a new OS or parallel runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AtlasAreaFocusLoopReadModelServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-716 Area Focus Loop Core Read-Only Runtime Contract

## Decision

Implement the first concrete read-only runtime/read-model of the **Area Focus
Loop**, the priority capability of the **Atlas Software Company Stewardship
Stack**.

Atlas Software Company Stewardship Stack is a stack/capability family inside the
Atlas Autonomous Software Company Runtime, not a new OS.

This runtime does NOT create a new OS, a parallel executor or a parallel finding
detector. It is a read-only core read-model that resolves a canonical area, its
owner docs and budgets, computes readiness, emits coarse finding seeds and hands
deeper scanning, spec drafting and execution to existing canonical owners
(Self-Directed Evolution, the Agentic Engineering OS finding engine, Atlas Dev,
Forge and the Morning Inbox).

## Boundary vs Sibling Slices

The Area Focus Loop is being built as coordinated slices, each with its own AP
and its own file; this AP owns only the core read-model:

| AP | Slice | Owner file |
|---|---|---|
| AP-712 | Night Shift Area Focus Loop (Product Mode control plane) | `app/Services/Ai/NightShift/*` |
| **AP-716 (this)** | **Area Focus Loop Core Read-Only Runtime** | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php` |
| AP-717 | Agentic Engineering OS Area Finding Engine (deep scan) | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AgenticEngineeringOsFindingEngineService.php` |
| AP-721 | Area Focus Product Mode Surface | `app/Services/Ai/SoftwareCompany/*` |

AP-716 produces **finding seeds** (coarse, read-only). Deep findings remain the
job of AP-717. AP-716 must not duplicate the finding engine, the Night Shift
control plane or the product surface.

## Schema

```text
atlas.software_company_stewardship.area_focus_loop.v1
```

## Area Contract (minimum input)

An area focus run resolves a contract with:

- `area_id`;
- `area_name`;
- `owner_docs`;
- `repo_scope`;
- `autonomy_tier`;
- `dev_budget`;
- `forge_budget`;
- `wip_limit`;
- `risk_policy`;
- `stop_conditions`.

First supported `area_id`: `agentic_engineering_os`. Unknown areas return a
`blocked` report with `area_not_registered`.

## Output (minimum)

- `area_map` (area_id, area_name, owner_docs, repo_scope, covers);
- `owner_docs_resolved` (each owner doc with `exists`);
- `readiness` (`ready|partial|blocked` + checks);
- `finding_seeds` (coarse read-only seeds with recommended canonical owner);
- `health_summary` (counts + readiness);
- `claim_policy` (read-only invariants);
- `evidence_refs`;
- `next_actions`;
- deterministic `report_hash` (excludes `generated_at`).

## Max Governed Safety Boundary

`max_governed` means maximum useful throughput inside the canonical safety
boundary:

- no merge without operator;
- no deploy without operator;
- no secrets;
- no destructive change;
- branch isolation required (declared, not created in this read-only slice);
- budget and WIP limits required;
- kill switch required;
- evidence pack required;
- Morning Inbox required.

## Acceptance

- A canonical read-only read-model service exists and emits the schema.
- `agentic_engineering_os` resolves with canonical defaults and owner docs.
- The report is deterministic (stable `report_hash` for equal input).
- `claim_policy` proves read-only: no writes, no provider, no branch, no merge,
  no deploy, no secrets, no destructive change, no parallel runtime.
- An unknown area returns `blocked` with `area_not_registered`.
- A CLI entrypoint exposes the read-model.
- Unit tests cover schema, defaults, owner docs list, deterministic hash,
  read-only claim policy, invalid area and the no-merge/no-deploy/no-secrets
  invariants.
- docs-health and architecture validation stay green.
