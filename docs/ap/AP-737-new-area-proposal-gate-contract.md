---
id: AP-737-new-area-proposal-gate-contract
type: architecture_proposal
title: AP-737 New Area Proposal Gate Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Adds the first concrete Self-Expanding Software Company gate inside the Atlas Software Company Stewardship Stack. It projects AP-730 new-area proposals, AP-731 operator decisions and the existing Atlas Domain Runtime Creation Gate into a proposal-only gate that can say awaiting review, accepted for Domain Runtime Creation Gate, rejected, deferred or blocked. AP-739 now exposes these gate items in the Stewardship Product Mode Cockpit. It creates no domain, department, OS, executor, branch, scheduler, Dev/Forge dispatch or autonomous mutation path.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md
  - docs/ap/AP-730-stewardship-evolution-read-model-contract.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-736-executive-decision-inbox-surface-contract.md
  - docs/ap/AP-738-self-expanding-software-company-v0-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-737 New Area Proposal Gate Contract

## Decision

AP-737 materializes the first runtime-safe slice of **Self-Expanding Software
Company**: Atlas may detect a missing area/capability and produce a reviewable
new-area proposal, but the proposal must pass a gate before it can be handed to
the existing **Atlas Domain Runtime Creation Gate**.

This AP closes the dangerous gap between "Atlas can propose new areas" and "Atlas
accidentally creates sprawl". It is a gate, not an executor.

## Boundary

AP-737 must:

- reuse AP-730 new-area proposals;
- reuse AP-731 operator decision receipts;
- reuse `atlas-domain-runtime-creation-gate.md` as the owner of actual domain or
  department birth;
- project canonical gate items with stable anchors;
- emit a draft `atlas.domain.creation_proposal.v1` envelope;
- block existing-area collisions;
- block known Atlas capability duplication (`atlas_dev`, `atlas_forge`,
  `self_directed_evolution`, `evidence`, `agentic_engineering_os`) and route it
  to Area/Portfolio Stewardship instead of Domain Runtime Creation;
- mark sensitive candidates with safety/sovereignty locks;
- allow an `accept` receipt to mean only "ready for Domain Runtime Creation Gate
  review";
- never create a domain runtime, department, branch, worktree, executor,
  scheduler, provider call, Dev/Forge dispatch, merge, deploy or secret access.

## Schemas

```text
atlas.software_company.new_area_proposal_gate.v1
atlas.software_company.new_area_proposal_gate.item.v1
atlas.domain.creation_proposal.v1
```

## Gate States

| State | Meaning |
|---|---|
| `awaiting_operator_review` | Proposal is structurally valid and waits for AP-731 decision. |
| `blocked_awaiting_operator_review` | Proposal exists, but collision or sensitive-domain blockers must be handled before promotion. |
| `accepted_for_domain_runtime_creation_gate` | Operator accepted and no AP-737 blockers remain; next owner is Domain Runtime Creation Gate. |
| `accepted_but_blocked_by_gate` | Operator accepted, but AP-737 blockers still prevent handoff. |
| `rejected` | Operator rejected. |
| `deferred` | Operator deferred. |
| `changes_requested` | Operator requested proposal revision. |

## Sensitive Domain Lock

If a candidate area matches legal, healthcare, finance, trading, medical, cyber,
security or red-team semantics, AP-737 must include:

```text
sovereignty_class: sensitive | cyber
operation_mode: assistive_research_draft_analysis_only
licensed_human_review_required: true
no_autonomous_professional_advice: true
mandatory_gates:
  - jurisdiction_check
  - consent_chain_verified
  - audit_trail_complete
  - liability_carrier_documented
  - policy_gate_passed
replay_minimum_intents: 100
```

## Existing Capability Anti-Duplication

AP-737 is also a duplication gate. If AP-730 emits a "new area" proposal for a
capability that already has canonical owners, AP-737 must not draft a new domain
birth as the recommended path.

Known existing capabilities route to stewardship handoff instead:

| Candidate | Owner |
|---|---|
| `agentic_engineering_os` / `aaeos` | `atlas-agentic-engineering-os.md` |
| `atlas_dev` | `atlas-dev-efficient-programming-flow-v1.md` |
| `atlas_forge` | `atlas-forge-operating-system.md` |
| `self_directed_evolution` | `atlas-self-directed-evolution-layer.md` |
| `evidence` | `atlas-evidence-certification-runtime.md` |

In those cases `creation_recommended=false`, `blocked_by_existing_owner=true`
and `required_next_gate.owner=area_stewardship_existing_capability_handoff`.

## CLI

```text
php artisan atlas:software-company-stewardship new-area-proposal-gate --json
php artisan atlas:software-company-stewardship new-area-proposal-gate --proposal-id=<proposal> --json
php artisan atlas:software-company-stewardship new-area-proposal-decision --proposal-id=<proposal> --actor=<operator> --decision=<accept|reject|defer|request_changes> --rationale=<why> --json
```

## Acceptance

- `NewAreaProposalGateService` projects AP-730 new-area proposals into AP-737 gate
  items.
- Each item carries stable `target_type=new_area_proposal`, `target_id` and
  `target_hash` anchors for AP-731.
- Accepting a proposal records AP-731 state and changes gate status, but never
  creates or promotes the area.
- Sensitive-domain candidates carry safety/sovereignty lock and elevated replay
  requirement.
- Existing-area collisions block handoff.
- CLI exposes read and decision commands.
- AP-739 exposes gate items in the combined Stewardship Product Mode Cockpit
  without creating or promoting domains.
- Focused tests prove proposal-only behavior, AP-731 decision integration,
  sensitive lock, collision blocking and no mutation/runtime creation.
