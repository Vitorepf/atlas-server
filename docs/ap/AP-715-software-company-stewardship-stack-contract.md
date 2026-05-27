---
id: AP-715-software-company-stewardship-stack-contract
type: architecture_proposal
title: AP-715 Atlas Software Company Stewardship Stack Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Canonizes Atlas Software Company Stewardship Stack as the umbrella area containing Night Shift, Night Shift Product Mode, Atlas Continuous Stewardship Loop, Area Focus Loop, Area Stewardship, Portfolio Stewardship, Autonomous Executive Layer and Self-Expanding Software Company. AP-738 adds Self-Expanding Software Company v0 while preserving that the stack lives inside Atlas Autonomous Software Company Runtime, not as a new OS or runtime; AP-739 integrates the upper review queue into Product Mode/Cockpit; AP-740/AP-748 bridge AP-731/AP-738/AP-747 outcomes into Evidence Ledger, Morning Inbox and Portfolio; AP-749 gates owner-specific Dev/Forge consumption; AP-758 adapts ready owner consumption into an AP-750-compatible owner result through existing Atlas Dev/Forge projections; AP-759 executes an explicitly approved allowlisted owner CLI inside the AP-756 sandbox; AP-760 exposes AP-759 in Product Mode/Cockpit; AP-761 renders the full end-to-end Product Mode pipeline in Atlas Desktop; AP-762 certifies the whole chain end-to-end in projection and optional sandbox-execution modes; AP-763 audits the operator's 29 practical requirements item-by-item before allowing a 100% completion claim; AP-750 bridges owner runtime results back to Evidence, Morning Inbox and Portfolio; AP-751 feeds owner results into Portfolio; AP-752 turns accepted executive recommendations into owner allocation handoffs; AP-753 makes those handoffs visible in the same cockpit; AP-754 adds operational controls visibility to that cockpit; AP-755 turns those controls into AP-731 append-only receipts; AP-756 materializes AP-726 branch sandboxes into local isolated git worktrees only with explicit operator receipt; AP-757 binds AP-749 owner consumption to that materialized sandbox; AP-741 creates gated handoff packets for Domain Runtime Creation Gate; AP-742 exposes AP-740/AP-741 history inside the same cockpit; AP-743 creates Area Stewardship active handoff packets after AP-732 readiness; AP-744 runs the first governed active operating slice; AP-745 wraps AP-744 in a scheduler-safe tick; AP-746 wraps AP-745 in a recurring scheduler-safe runner; AP-747 releases AP-726 handoffs to real Dev/Forge owner queues by explicit operator receipt.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
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
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - docs/ap/AP-752-autonomous-executive-allocation-handoff-contract.md
  - docs/ap/AP-753-product-mode-cockpit-executive-allocation-handoff-visibility-contract.md
  - docs/ap/AP-754-product-mode-operational-controls-read-model-contract.md
  - docs/ap/AP-755-product-mode-operational-control-receipts-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-757-owner-queue-sandbox-binding-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-760-product-mode-owner-sandbox-runtime-visibility-contract.md
  - docs/ap/AP-761-product-mode-desktop-end-to-end-stewardship-console-contract.md
  - docs/ap/AP-762-end-to-end-stewardship-live-cycle-certification-contract.md
  - docs/ap/AP-763-software-company-stewardship-completion-audit-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipLiveCycleCertificationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingDomainRuntimeCreationHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php
requires_evidence: true
risk_level: critical
---
# AP-715 Atlas Software Company Stewardship Stack Contract

## Decision

The canonical umbrella name for the entire Night Shift / Product Mode / Area
Focus / Stewardship family is:

```text
Atlas Software Company Stewardship Stack
```

This is a capability stack inside Atlas Autonomous Software Company Runtime. It
is not a new OS, not a parallel runtime and not a replacement for AAEOS,
Autonomous Software Company Runtime, Night Shift, Product Mode or Evidence.

## Stack Members

```text
Night Shift
-> Night Shift Product Mode
-> Atlas Continuous Stewardship Loop
-> Area Focus Loop
-> Area Stewardship Layer
-> Portfolio Stewardship Layer
-> Autonomous Executive Layer
-> Self-Expanding Software Company
```

## AI Rule

If an AI sees any of these names, it must first load
`atlas-software-company-stewardship-stack.md` before creating a new doc, OS,
runtime, ladder or umbrella name.

`Night Shift` means scheduled/batch/overnight operation. `Atlas Continuous
Stewardship Loop` means 24h/always-on governed operation with budget, locks,
rate limits, pause policy, inbox and kill switch. `NS-v3 Continuous Loop` is a
legacy alias only, never the canonical name.

`Atlas Continuous Stewardship Loop` is not the maximum. It is the always-on
motor. Higher autonomy must use the existing ladder:

```text
Area Focus Loop
-> Area Stewardship Layer
-> Portfolio Stewardship Layer
-> Autonomous Executive Layer
-> Self-Expanding Software Company
```

The ceiling inside this stack is `Self-Expanding Software Company`: Atlas may
propose new areas, loops, capabilities and runtime contracts, but promotion
requires gates, evidence and operator approval. Anything beyond that belongs to
the larger Atlas evolution lineage, not a new stewardship OS.

## Acceptance

- Canonical stack doc exists.
- Architecture indexes point to it.
- Child docs point back to it.
- It declares aliases and forbidden duplicate names.
- It preserves operator review, evidence, branch isolation, budget, WIP and
  no-merge/no-deploy defaults.
- It states that Continuous Stewardship Loop is the 24h motor and Self-Expanding
  Software Company is the stack ceiling.
- AP-739 exposes Executive, New Area and Self-Expanding review state inside the
  existing Product Mode/Cockpit without creating a new OS, runtime or executor.
- AP-740/AP-748 reuse canonical Evidence Ledger and Morning Inbox owners for
  AP-731/AP-738/AP-747 outcomes and Portfolio feed without creating parallel ledgers or inboxes.
- AP-741 turns accepted/evidenced self-expansion proposals into gated handoff
  packets without registering manifests or creating domains.
- AP-742 exposes AP-740/AP-741 history inside the existing Product Mode/Cockpit
  without creating a new cockpit, ledger, inbox, runtime or executor.
- AP-743 creates Area Stewardship active handoff packets after AP-731 accept and
  AP-732 readiness.
- AP-744 consumes AP-743 and runs the first active Area Stewardship operating
  slice through AP-722, AP-718 and AP-726 without creating a parallel executor,
  invoking providers, creating branches, dispatching Dev/Forge or taking
  irreversible action.
- AP-745 wraps AP-744 in a disabled-by-default, scheduler-safe Continuous
  Stewardship Loop tick with kill switch, lock lease, rate limit and append-only
  JSONL recording, without installing a scheduler or gaining mutation authority.
- AP-746 wraps AP-745 in a recurring scheduler-safe runner with pause policy,
  idempotent scheduler evidence and no scheduler installation or mutation
  authority.
- AP-747 releases AP-726 handoffs to real Atlas Dev/Forge owner queues only
  under explicit operator receipt; it still does not create branches, invoke
  providers, mutate repos, merge, deploy or touch secrets.
- AP-748 turns AP-747 releases into evidence, inbox and Portfolio signals
  without moving Dev/Forge execution authority.
- AP-749 gates owner-specific Dev/Forge consumption using AP-747 release,
  AP-748 Evidence/Morning Inbox/Portfolio visibility and operator execution receipt.
- AP-758 adapts a ready AP-749 consumption packet into an AP-750-compatible
  owner result by reusing existing Atlas Dev/Forge owner projections; it does
  not create a provider path or mutate repos.
- AP-759 executes an explicitly approved allowlisted owner CLI command inside
  the AP-756 worktree and emits an AP-750-compatible owner result; it does not
  create OS/runtime/provider path, merge, deploy, external push, secrets or
  destructive change.
- AP-760/AP-761 make that owner sandbox run visible in Product Mode and Atlas
  Desktop without letting the cockpit execute AP-759 or bypass AP-750.
- AP-762 certifies the entire stewardship live cycle end-to-end by reusing
  AP-722/AP-743/AP-744/AP-745/AP-746/AP-747/AP-748/AP-749/AP-758/AP-759/AP-750/AP-751/AP-733/AP-734/AP-735/AP-752/AP-739/AP-761,
  in projection mode and optional AP-759 sandbox execution mode, without
  creating a new runtime, provider path, cockpit, merge/deploy authority or
  secret/destructive capability.
- AP-763 audits the operator's 29 practical Stewardship requirements
  requirement-by-requirement and is the only local authority that may answer
  `current_practical_number=29/29`; projection-only audit stops at item 18,
  while full AP-759 sandbox execution certification may allow the 29/29 claim.
- AP-750 bridges Dev/Forge owner runtime result receipts back into Evidence,
  Morning Inbox and Portfolio without executing Dev/Forge or authorizing merge/deploy/secrets.
- AP-751 feeds AP-750 owner-runtime result signals into Portfolio health, risk and rebalance.
- AP-752 converts accepted AP-735 executive recommendations into owner allocation
  handoff packets without execution.
- AP-753 exposes AP-752 handoff packets in Product Mode/Cockpit without turning
  the cockpit into an executor.
- AP-754 exposes Product Mode operational controls in the same cockpit without
  authorizing repos, changing tiers, updating budgets, creating branches,
  invoking providers, merging, deploying or touching secrets.
- AP-755 records Product Mode operational controls as AP-731 receipts and lets
  AP-754 consume accepted receipts without creating a second ledger or executor.
- AP-756 materializes AP-726 branch sandbox metadata into a local isolated git
  branch/worktree only with explicit operator receipt; it still does not execute
  Dev/Forge, invoke providers, apply fixes, merge, deploy, push externally or
  touch secrets.
- AP-757 binds AP-749 owner runtime input to a matching AP-756 materialized
  sandbox record before owner consumption is ready.
