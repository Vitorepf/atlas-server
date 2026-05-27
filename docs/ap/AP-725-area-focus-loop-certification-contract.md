---
id: AP-725-area-focus-loop-certification-contract
type: architecture_proposal
title: AP-725 Area Focus Loop Certification Command
status: accepted
owner: programming
created_at: 2026-05-26
summary: Adds a structural, read-only certification that proves the Area Focus Loop family is present and healthy for agentic_engineering_os inside the Atlas Software Company Stewardship Stack. It checks presence/health of the stack and Product Mode docs, the AP contracts (AP-715, AP-716..AP-725), the read model, finding engine, inbox/spec bridge, Dev/Forge router, cycle/evidence services, the orchestrator + operational certification, the operator command, the canonical schemas, the safety gates, the operator decision receipts and the declared validations. It complements (does not duplicate) the AP-722 runtime operational certification: AP-722 proves the loop RUNS; AP-725 proves the loop EXISTS and is wired. When a required component is missing it returns status blocked with missing_components; when all are present it returns ready_read_only and never ready_for_autonomous_mutation.
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
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusLoopCertificationService.php
  - app/Console/Commands/AtlasAreaFocusLoopCertifyCommand.php
requires_evidence: true
risk_level: high
---
# AP-725 Area Focus Loop Certification Command

## Decision

AP-725 adds a structural, read-only certification that proves the Area Focus
Loop family is present and healthy for `agentic_engineering_os`. It is the
"does the loop EXIST and is it wired?" gate. It complements the AP-722 runtime
operational certification ("does the loop RUN correctly?"); it does not replace,
re-run or duplicate it — the AP-722 operational certification is itself one of
the components AP-725 checks for presence.

Atlas Software Company Stewardship Stack é stack/capability family dentro do
Atlas Autonomous Software Company Runtime, não OS novo. AP-725 is a presence /
health certification inside that stack. It is not a new OS, not a parallel
runtime and creates no new owner. It runs no scan, opens no branch, dispatches
no work and never merges, deploys, accesses secrets or makes a destructive
change. It does not execute the loop.

## Schema

```text
atlas.software_company_stewardship.area_focus_certification.v1
```

## Command

```text
atlas:software-company-stewardship:area-focus-certify {--area=agentic_engineering_os} {--json}
```

## Certified Components

The certification checks presence/health of:

- docs: stack doc, Product Mode doc;
- AP contracts: AP-715 and AP-716..AP-725 (required AP-715..AP-722 and AP-725;
  AP-723/AP-724 are reported as pending and do not block read-only readiness);
- Area Focus read model (AP-716);
- finding engine (AP-717);
- inbox + spec draft bridge (AP-718);
- Dev/Forge work order router (AP-719);
- durable cycle + evidence pack services (AP-720);
- operational orchestrator + operational certification (AP-722);
- the operator orchestrator command;
- the canonical schemas declared by those services;
- the safety gates (no merge/deploy/secrets/destructive; execution disabled);
- the operator decision receipts (operator-gated, no auto-approval);
- the declared validations (test, docs-health, architecture-validate, git diff).

## Status Contract

```text
blocked          -> one or more required components missing/unhealthy (missing_components listed)
ready_read_only  -> every required component present and healthy
```

The certification NEVER returns `ready_for_autonomous_mutation`. It always
declares `mutation_ready = false` and `read_only = true`. Read-only readiness is
not an authorization to mutate, merge, deploy or run autonomously.

## Determinism

The `certification_hash` is computed over the component results (it excludes the
wall-clock `generated_at`), so the same component map is reproducible. A `probes`
input seam lets tests assert presence/absence deterministically without touching
the filesystem or the container.

## Acceptance

- Returns `ready_read_only` when every required component is present.
- Returns `blocked` with `missing_components` when a required AP is missing.
- Returns `blocked` with `missing_components` when a required service is missing.
- Never returns `ready_for_autonomous_mutation`; always `mutation_ready = false`.
- Deterministic `certification_hash` for the same component map.
- Reuses the AP-722 operational certification as a checked component; no
  duplication, no loop execution, no parallel runtime.
- docs-health and architecture-validate stay green.
