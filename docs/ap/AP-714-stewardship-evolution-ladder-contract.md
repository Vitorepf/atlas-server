---
id: AP-714-stewardship-evolution-ladder-contract
type: architecture_proposal
title: AP-714 Atlas Stewardship Evolution Ladder Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Canonizes the evolution beyond Atlas Continuous Stewardship Loop and Area Stewardship as a governed ladder: Area Focus Loop, Area Stewardship, Portfolio Stewardship, Autonomous Executive Layer and Self-Expanding Software Company. AP-738 provides Self-Expanding Software Company v0 while preserving no OS/runtime duplication or permissionless autonomy; AP-739 exposes the high-level review state in Product Mode/Cockpit; AP-740/AP-748 bridge AP-731/AP-738/AP-747 outcomes into Evidence Ledger, Morning Inbox and Portfolio; AP-749 gates owner-specific Dev/Forge consumption; AP-758 adapts ready consumption into AP-750-compatible owner results; AP-750 bridges owner runtime results back to Evidence, Morning Inbox and Portfolio; AP-755 reuses AP-731 for Product Mode operational control receipts; AP-756 materializes AP-726 branch sandboxes into local isolated git worktrees only by explicit operator receipt; AP-757 binds AP-749 consumption to that materialized sandbox; AP-741 creates the gated Domain Runtime Creation handoff packet; AP-742 exposes AP-740/AP-741 history inside the same cockpit; AP-743 creates the Area Stewardship active handoff packet after AP-732 readiness; AP-744 consumes it for the first governed active operating slice; AP-745 makes that slice scheduler-safe; AP-746 makes Continuous Stewardship recurring-scheduler-safe.
related_paths:
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
  - docs/ap/AP-730-stewardship-evolution-read-model-contract.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-733-portfolio-stewardship-health-model-contract.md
  - docs/ap/AP-734-portfolio-steward-inbox-contract.md
  - docs/ap/AP-735-autonomous-executive-recommendation-contract.md
  - docs/ap/AP-736-executive-decision-inbox-surface-contract.md
  - docs/ap/AP-737-new-area-proposal-gate-contract.md
  - docs/ap/AP-738-self-expanding-software-company-v0-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-741-self-expanding-domain-runtime-creation-handoff-contract.md
  - docs/ap/AP-742-product-mode-cockpit-stewardship-history-contract.md
  - docs/ap/AP-743-area-stewardship-active-handoff-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - docs/ap/AP-745-continuous-stewardship-loop-scheduler-safe-contract.md
  - docs/ap/AP-746-continuous-stewardship-recurring-scheduler-contract.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-755-product-mode-operational-control-receipts-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-757-owner-queue-sandbox-binding-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingDomainRuntimeCreationHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php
requires_evidence: true
risk_level: critical
---
# AP-714 Atlas Stewardship Evolution Ladder Contract

## Decision

The next evolution beyond Area Stewardship is a ladder, not a new OS:

```text
Atlas Continuous Stewardship Loop
-> Area Focus Loop
-> Area Stewardship Layer
-> Portfolio Stewardship Layer
-> Autonomous Executive Layer
-> Self-Expanding Software Company
```

Each step increases scope of responsibility while preserving operator approval,
evidence, branch isolation, budget, WIP limits, kill switch and no-merge/no-deploy
defaults.

`Atlas Continuous Stewardship Loop` is not the maximum level. It is the
always-on governed motor. The higher levels add ownership, portfolio reasoning,
executive recommendation and governed self-expansion.

Within the Atlas Software Company Stewardship Stack, the ceiling is:

```text
Self-Expanding Software Company
```

Beyond that ceiling, the conversation belongs to the larger Atlas lineage
document, not to a new duplicate stewardship OS.

## Boundary

The ladder reuses:

- Night Shift and Product Mode for cycles and cockpit;
- Atlas Continuous Stewardship Loop for 24h/always-on governed operation;
- Area Stewardship for area ownership;
- Self-Directed Evolution for gaps/specs;
- Dev and Forge for implementation routing;
- Evidence for proof;
- Autonomous Software Company Runtime for organizational coordination.

No layer creates a parallel executor, proposal registry or authority surface.

Self-Expanding Software Company may propose new areas, loops, capabilities or
runtime contracts. It may not promote them without Domain Runtime Creation Gate
where applicable, evidence and operator approval.

## Acceptance

- Canonical ladder doc exists.
- Area Stewardship remains the first stewardship layer.
- Portfolio Stewardship owns multiple areas.
- Autonomous Executive owns strategy and resource allocation.
- Self-Expanding Software Company proposes new areas only through governed gates.
- AP-739 exposes Executive, New Area and Self-Expanding review state in Product Mode/Cockpit without executing work.
- AP-740/AP-748 record AP-731/AP-738/AP-747 outcomes through canonical Evidence Ledger, Morning Inbox and Portfolio owners.
- AP-749 gates AP-747/AP-748 owner-specific Dev/Forge consumption before owner runtime input is accepted.
- AP-758 adapts ready AP-749 consumption into an AP-750-compatible owner result
  while reusing existing Atlas Dev/Forge projections.
- AP-750 records owner runtime result outcomes after Dev/Forge owners report back,
  while keeping merge, deploy, secrets and destructive changes operator-gated.
- AP-741 turns accepted/evidenced AP-738 proposals into Domain Runtime Creation Gate handoff packets without creating domains.
- AP-742 exposes AP-740/AP-741 history inside the existing Product Mode/Cockpit without creating a new cockpit, ledger, inbox, runtime or executor.
- AP-743 turns accepted/readiness-proven Area Stewardship into a reviewable active handoff packet.
- AP-744 consumes the AP-743 packet and runs the first active operating slice, still with no provider calls, no branch creation, no Dev/Forge dispatch and no irreversible action without operator review.
- AP-745 makes AP-744 scheduler-safe for Continuous Stewardship; AP-746 adds recurring scheduler admission with pause policy, evidence and no scheduler/executor authority.
- AP-755 reuses AP-731 for Product Mode control receipts instead of creating a
  second control ledger.
- AP-756 creates only the local isolated branch/worktree sandbox under explicit
  operator receipt; it does not execute Dev/Forge, providers, fixes, merge,
  deploy, push or secrets.
- AP-757 requires AP-749 to carry that matching AP-756 sandbox into owner
  runtime input before consumption is ready.
- All stages remain future targets until runtime evidence exists.
- Continuous Stewardship Loop is documented as motor, not ceiling.
- Self-Expanding Software Company is documented as stack ceiling, not permissionless autonomy.
