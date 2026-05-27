---
id: AP-722-area-focus-loop-operational-certification-contract
type: architecture_proposal
title: AP-722 Area Focus Loop Operational Certification
status: accepted
owner: programming
created_at: 2026-05-26
summary: Closes the Area Focus Loop for area_id=agentic_engineering_os with a read-only, decision-oriented operational certification that composes the existing slices (AP-716 read model, AP-717 finding engine, AP-718 inbox + spec bridge, AP-719 dev/forge router, AP-720 durable cycle + evidence pack) into one end-to-end verdict. It proves the loop runs scan -> findings -> inbox -> work orders -> evidence and certifies it operational, partial or blocked. It creates no new OS, no parallel runtime and no parallel registry, and it never merges, deploys, accesses secrets, implements fixes or opens mutative branches.
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
  - docs/ap/AP-721-area-focus-product-mode-surface-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusLoopOperationalCertificationService.php
requires_evidence: true
risk_level: high
---
# AP-722 Area Focus Loop Operational Certification

## Decision

The Area Focus Loop slices AP-716..AP-721 exist independently. AP-722 closes the
loop for `agentic_engineering_os` with a single **operational certification**:
a read-only, decision-oriented verdict that composes the existing owners into one
end-to-end run and certifies whether the loop is operational.

Atlas Software Company Stewardship Stack é stack/capability family dentro do
Atlas Autonomous Software Company Runtime, não OS novo. AP-722 is a certification
capstone, not a new OS, not a parallel runtime and not a new proposal registry.

## Reuse Contract (no duplication)

AP-722 composes — never reimplements — these owners:

| Slice | Owner | Reused for |
|---|---|---|
| AP-716 | `AreaFocusLoopReadModelService` (NightShift, integrated) | area contract, findings, morning inbox, routing |
| AP-718 | `AreaFocusInboxService` | operator-reviewable inbox items |
| AP-719 | `AreaFocusDevForgeRouterService` | governed work order plan |
| AP-720 | `AreaFocusCycleRecorderService` | durable append-only cycle (evidence) |
| AP-720 | `AreaFocusEvidencePackService` | evidence pack |

AP-717 (finding engine) is the upstream finding source consumed by the read
model; AP-721 (product surface) is the presentation layer. Both are referenced,
not reimplemented.

## Certification Contract

`AreaFocusLoopOperationalCertificationService::certify()` must:

- run a read-only / decision-oriented composition of the slices above;
- emit a per-slice check matrix and an overall verdict
  `operational | partial | blocked`;
- record evidence only as an append-only local cycle (AP-720); never mutate the
  target repo, never invoke a provider, never route work for execution, never
  open a branch, never merge, deploy, access secrets or implement a fix;
- enforce governance invariants by aggregating the slices' claim policies (no
  merge/deploy/secrets/destructive/execution/autoapproval, no parallel
  runtime/OS);
- produce a deterministic `cert_hash`.

Schema:

```text
atlas.software_company_stewardship.area_focus_loop_certification.v1
```

## Acceptance

- Certification composes AP-716/718/719/720 owners and emits the verdict.
- `operational` only when every spine check passes and governance holds.
- A blocked read model or a governance violation yields `blocked`.
- Deterministic `cert_hash` for the same inputs.
- Evidence recording is append-only and idempotent; nothing else is written.
- No new OS, no parallel runtime, no parallel registry.
- docs-health and architecture-validate stay green.
