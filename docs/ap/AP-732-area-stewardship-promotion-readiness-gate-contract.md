---
id: AP-732-area-stewardship-promotion-readiness-gate-contract
type: architecture_proposal
title: AP-732 Area Stewardship Promotion Readiness Gate
status: accepted
owner: programming
created_at: 2026-05-27
summary: Adds a read-only promotion readiness gate for moving an AP-730 Area Stewardship proposal toward an active Area Stewardship implementation slice. It requires health model, roadmap, Dev/Forge policy, operator inbox, evidence refs, no-mutation claims and an AP-731 operator accept decision. It never executes promotion, creates branches, invokes providers or mutates the target repo. AP-743 consumes its ready_for_active_handoff output to create a reviewable handoff packet.
related_paths:
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-713-area-stewardship-layer-contract.md
  - docs/ap/AP-730-stewardship-evolution-read-model-contract.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-743-area-stewardship-active-handoff-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipPromotionReadinessService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipPromotionReadinessServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-732 Area Stewardship Promotion Readiness Gate

## Decision

AP-732 creates the gate between proposal-only Area Stewardship and an active
Area Stewardship implementation slice.

It answers:

```text
Can this area move from Area Focus Loop output to Area Stewardship active?
```

The answer can be:

```text
ready_for_operator_review
ready_for_active_handoff
blocked
```

## Required Evidence

- AP-730 Area Stewardship projection exists.
- Area health model exists.
- Roadmap candidates exist.
- Dev/Forge routing policy exists.
- Operator inbox is present and auto-approval is false.
- Area Focus Loop is ready and has evidence refs.
- No-mutation claim policy is intact.
- AP-731 has an explicit operator `accept` decision for
  `target_type=area_stewardship`.

## Boundary

This gate does not implement active stewardship by itself. It produces a
read-only readiness report that can be used as evidence for a later active
implementation AP.

AP-743 is that next boundary: it consumes `ready_for_active_handoff` and emits
an operator-reviewable handoff packet. AP-743 still does not start active
operation; it only makes the transition explicit and durable when requested.

It must not call providers, create branches, dispatch Dev/Forge, merge, deploy,
touch secrets, mutate the repo, auto-promote or create a parallel OS/runtime.

Schema:

```text
atlas.area_stewardship.promotion_readiness.v1
```

## Acceptance

- Service emits readiness, target hash and blockers.
- Missing only AP-731 accept returns `ready_for_operator_review`.
- AP-731 accepted target returns `ready_for_active_handoff`.
- Missing health/roadmap/evidence/policy returns `blocked`.
- CLI exposes `area-stewardship-readiness`.
- Tests prove no execution and deterministic report hash.
