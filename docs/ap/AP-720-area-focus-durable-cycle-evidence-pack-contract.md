---
id: AP-720-area-focus-durable-cycle-evidence-pack-contract
type: architecture_proposal
title: AP-720 Area Focus Durable Cycle and Evidence Pack Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Adds a read-only durable, replayable cycle and an evidence pack projection for the Area Focus Loop (AP-712) inside the Atlas Software Company Stewardship Stack. Each read-only Area Focus projection can be recorded to append-only local JSONL, replayed and inspected by cycle_id, and turned into a completeness-checked evidence pack for the Morning Inbox. It opens no branch, executes no provider, mutates no repo and stores no secrets.
related_paths:
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-717-agentic-engineering-os-area-finding-engine-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - app/Services/Ai/NightShift/AreaFocusLoopReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusCycleRecorderService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusEvidencePackService.php
  - app/Console/Commands/AtlasNightShiftAreaFocusCycleCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusCycleRecorderServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusEvidencePackServiceTest.php
requires_evidence: true
risk_level: high
---
# AP-720 Area Focus Durable Cycle and Evidence Pack Contract

> Atlas Software Company Stewardship Stack is a stack/capability family inside
> the Atlas Autonomous Software Company Runtime, not a new OS.

## Context

The Area Focus Loop (AP-712) is a read-only read model
(`AreaFocusLoopReadModelService`, namespace `App\Services\Ai\NightShift`). Its
projection is deterministic but ephemeral. To deliver an auditable Morning Inbox
the operator needs each cycle to be **durable**: recorded, replayable and
inspectable later, with an evidence pack that pins down what was found without
re-running the scan.

## Decision

Add two read-only services under
`App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop`:

1. `AreaFocusCycleRecorderService` — records one Area Focus projection as an
   append-only JSONL cycle, replays a cycle by `cycle_id`, and lists cycles.
2. `AreaFocusEvidencePackService` — projects a recorded cycle into a
   completeness-checked evidence pack for the Morning Inbox.

These are projections/recorders over the canonical read model. They are NOT a
new OS, NOT a parallel runtime and NOT an executor.

## Persistence

Append-only JSONL, one file per area:

```text
storage/atlas/software_company_stewardship/area_focus_cycles/{area_id}.jsonl
```

Append uses an exclusive lock; reads skip malformed lines (corruption-tolerant).
A testing seam (`setStorageRootForTesting`) redirects the root to a temp path so
no test touches real storage.

## Schemas

```text
atlas.software_company_stewardship.area_focus_cycle.v1
atlas.software_company_stewardship.area_focus_evidence_pack.v1
```

A cycle record must carry: `cycle_id`, `area_id`, `report_schema_version`,
`report_status`, `report_hash`, `finding_count`, a sanitized `input_digest`
(+ `input_hash`), `findings_hash`, `inbox_hash`, `work_orders_hash`,
`routing_summary`, `validation_refs`, `claim_policy`, `cycle_hash`,
`generated_at` and a wall-clock `recorded_at`.

`cycle_id` is deterministic from cycle content (area_id + report/findings/inbox/
work-order hashes), so the same projection yields the same id and recording is
idempotent (dedup on `cycle_id`). `cycle_hash` and `cycle_id` exclude
`recorded_at`.

## Safety / Claim Policy

- no branch, no merge, no deploy, no provider, no secret access;
- never mutates the target repo (only appends local JSONL runtime state);
- `input_digest` is a whitelist of safe scalars/flags — raw overrides
  (`gap_read_model`, `owner_doc_status`, `area_findings`) and any secret-like
  values are never persisted;
- `validation_refs` are DECLARED requirements (build, tests, docs-health,
  architecture-validate, evidence_pack), not proof of execution;
- every cycle requires operator review before any downstream action.

## Acceptance

- A read-only Area Focus projection is recorded to JSONL and replayed by
  `cycle_id` byte-for-byte (excluding `recorded_at`).
- Deterministic `cycle_id`/`cycle_hash` for identical input; idempotent append.
- Corrupted JSONL lines are skipped and counted, never crash replay/list.
- The evidence pack reports completeness (present/missing required fields).
- No secrets ever appear in the persisted payload.
- Tests cover append, replay, deterministic ids, corruption handling, evidence
  completeness and no-secrets. docs-health and architecture-validate stay green.
