# AP-706 - Next Patamar Authority Correction Contract

Status: proposed
Owner: autonomous-holding
Area: documentation-governance
Risk: critical

## Problem

The factory-created next-patamar docs introduced useful ideas, but some of
their authority claims duplicate canonical layers that already exist:

- `atlas-autonomous-intelligence-operating-system.md` already defines Atlas as
  the multi-domain Autonomous Intelligence OS.
- `atlas-domain-company-runtimes.md` and `atlas-domain-runtime-contract.md`
  already define Domain Company Runtimes, registry, maturity and handoff.
- `domains/domain-routing-governance.md` already defines Domain Creation Gate.
- `atlas-ai-autonomous-holding-operating-system.md` already defines the
  Autonomous Holding governor above the nine company runtimes.
- TEOS counterfactual docs already own counterfactual planning.

The dangerous drift is not the content itself; it is a parallel authority path
where "Genesis" or "Autonomous Company OS" becomes a new next OS even though
the Holding/Domain architecture already owns that layer.

## Goal

Correct the four factory docs so they become subordinate hardening contracts:

- `atlas-autonomous-company-os-genesis-initiative.md` becomes a compatibility
  bridge for Holding-to-World-Action hardening, not a new OS and not the owner
  of Autonomous Company promotion.
- `atlas-reality-outcome-gates.md` becomes an outcome gate taxonomy that feeds
  Evidence, ASRE, Mission Control and Holding scorecards.
- `atlas-domain-runtime-creation-gate.md` becomes a strict extension of the
  existing Domain Creation Gate, Domain Runtime Contract and Department
  Contract Runtime; it must not create a parallel domain authority.
- `atlas-architecture-evolution-proposal-runtime.md` remains a Self-Construction
  OS sub-runbook and removes its dependency on Genesis.

## Non Goals

- Do not delete the useful safety, reality, domain or architecture concepts.
- Do not create AGOS, ACSL, ASRL or any new OS acronym.
- Do not rename files in this AP; keep compatibility with existing index paths.
- Do not promote external execution. Holding external actions remain blocked
  by mandate, approvals and manual/supervised execution policy.

## Acceptance Criteria

- No doc claims that AAEOS v1 jumps to a new "Genesis OS" or "AGOS".
- No doc claims "L7 AAEOS -> L8 Autonomous Company OS" as a local promotion
  owned by Genesis.
- `Genesis` is explicitly documented as a legacy codename / compatibility
  bridge, not a runtime, OS or canonical promotion authority.
- Reality Outcome Gates depends on Evidence, ASRE, Trust Ledger and Holding,
  not Genesis.
- Domain Runtime Creation Gate depends on Domain Runtime Contract and Domain
  Routing Governance, not Genesis.
- Architecture Evolution Proposal Runtime depends on Self-Construction OS,
  Evidence and Trust Ledger, not Genesis.
- Architecture index and authority map describe the corrected ownership.

## Verification

- `php artisan atlas:engineering:knowledge docs-health --json`
- `php artisan atlas:engineering:knowledge sync --prune --json`

