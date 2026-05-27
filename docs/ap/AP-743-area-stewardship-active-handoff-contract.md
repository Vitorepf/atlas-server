---
id: AP-743-area-stewardship-active-handoff-contract
type: architecture_proposal
title: AP-743 Area Stewardship Active Handoff Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Creates the governed handoff packet between AP-732 promotion readiness and AP-744 active Area Stewardship operating slice. It consumes AP-730 Area Stewardship projection, AP-731 operator accept receipts and AP-732 ready_for_active_handoff evidence, emits an operator-reviewable active handoff packet and exposes it in the existing AP-739 Product Mode/Cockpit without starting the active loop, invoking Dev/Forge, creating branches, mutating repos or creating a parallel runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-713-area-stewardship-layer-contract.md
  - docs/ap/AP-730-stewardship-evolution-read-model-contract.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-732-area-stewardship-promotion-readiness-gate-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipPromotionReadinessService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffServiceTest.php
  - tests/Feature/Ai/SoftwareCompany/AreaStewardshipActiveHandoffCommandTest.php
requires_evidence: true
risk_level: critical
---
# AP-743 Area Stewardship Active Handoff Contract

## Decision

AP-743 creates the missing boundary between Area Stewardship readiness and
future active operation.

AP-732 answers whether an AP-730 Area Stewardship proposal has enough evidence
and AP-731 operator acceptance to move forward. AP-743 turns that readiness into
a deterministic handoff packet that can be reviewed, recorded and used by a
later active Area Stewardship implementation slice. AP-744 now provides that
first active operating slice.

The packet is **not** the active loop itself. It is a governed claim boundary:

```text
AP-730 Area Stewardship proposal
+ AP-731 operator accept decision
+ AP-732 ready_for_active_handoff
-> AP-743 active handoff packet
-> AP-744 active Area Stewardship operating slice
```

## Anti-Duplication Resolution

The placement gate reports high overlap with Area Stewardship, Stewardship
Evolution, Product Mode, Domain Runtime Creation and Self-Directed Evolution.
AP-743 resolves that overlap by reuse:

- AP-730 remains the Area/Portfolio/Executive/Self-Expanding projection owner.
- AP-731 remains the operator decision ledger owner.
- AP-732 remains the Area Stewardship promotion readiness gate.
- Area Focus Loop remains the area cycle owner.
- Self-Directed Evolution remains the gap/spec proposal owner.
- Atlas Dev and Forge remain the implementation owners.
- Evidence remains the proof owner.
- Product Mode/Cockpit remains the operator review surface and reads AP-743
  packets through the existing AP-739 surface.
- AP-744 is the downstream active operating slice that consumes AP-743.

AP-743 does not create a new OS, runtime, executor, scheduler, branch manager,
proposal registry, cockpit, area registry or domain registry.

## Boundary

AP-743 may:

- read AP-732 readiness;
- accept a readiness report override in tests and controlled orchestration;
- map AP-732 `ready_for_operator_review` to
  `awaiting_operator_acceptance`;
- map AP-732 `ready_for_active_handoff` to `ready`;
- emit `atlas.area_stewardship.active_handoff.v1`;
- emit one `atlas.area_stewardship.active_handoff_packet.v1` when ready;
- derive an active mode envelope for routing, budgets, WIP and safety;
- append the packet to JSONL only when `--record-active-handoff` is explicitly
  requested;
- return an idempotent existing packet when the same handoff was already
  recorded.
- expose AP-743 status, counters, command anchors and ready packets in the
  existing Product Mode/Cockpit review queue.

AP-743 must not:

- start the active Area Stewardship loop;
- invoke providers;
- invoke Atlas Dev or Forge;
- create branches, worktrees or commits;
- mutate target repos;
- open PRs, merge, deploy, touch secrets or perform destructive actions;
- create domains, departments, manifests, runtimes or OSes;
- auto-promote Area Stewardship;
- bypass operator review.

## Schemas

```text
atlas.area_stewardship.active_handoff.v1
atlas.area_stewardship.active_handoff_packet.v1
atlas.area_stewardship.active_mode_envelope.v1
```

## CLI

AP-743 extends the existing stewardship command:

```text
php artisan atlas:software-company-stewardship area-stewardship-active-handoff --area=agentic_engineering_os --json
```

Default mode is projection-only. Durable recording is explicit:

```text
php artisan atlas:software-company-stewardship area-stewardship-active-handoff --area=agentic_engineering_os --record-active-handoff --json
```

The JSONL storage path is area-scoped:

```text
storage/atlas/software_company_stewardship/area_stewardship_active_handoffs/{area}.jsonl
```

## Product Mode/Cockpit

AP-743 reuses the existing AP-739 cockpit. It adds:

```text
area_stewardship_active_handoff
counters.area_active_handoff_packets
counters.ready_area_active_handoffs
counters.pending_area_active_acceptance
review_queue[].source_ap = AP-743
operator_controls.area_active_handoff_record_command
```

The cockpit remains read-only. It may show the handoff and command anchor, but
it must not record the handoff or start active stewardship.

## Status Semantics

| AP-732 status | AP-743 status | Meaning |
|---|---|---|
| `ready_for_active_handoff` | `ready` | Handoff packet can be reviewed or recorded. |
| `ready_for_operator_review` | `awaiting_operator_acceptance` | AP-731 accept is still missing. |
| `blocked` or unknown | `blocked` | Readiness blockers must be repaired first. |

## Acceptance

- Service emits `atlas.area_stewardship.active_handoff.v1`.
- Ready reports include `source_ap_contracts = AP-730, AP-731, AP-732`.
- Ready reports include exactly one active handoff packet.
- Handoff packet includes target, readiness hash, operator acceptance, active
  mode envelope, required gate sequence, boundary and packet hash.
- Claim policy proves no provider, no Dev/Forge, no branch, no repo mutation,
  no merge/deploy/secrets and no auto-promotion.
- `--record-active-handoff` appends JSONL idempotently.
- CLI exposes `area-stewardship-active-handoff`.
- Product Mode/Cockpit exposes AP-743 as a read-only section and review queue
  source without recording or executing it.
- Tests prove awaiting-acceptance, blocked, ready, idempotent recording and
  deterministic hashes.
