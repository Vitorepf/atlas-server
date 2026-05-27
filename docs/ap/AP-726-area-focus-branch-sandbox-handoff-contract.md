---
id: AP-726-area-focus-branch-sandbox-handoff-contract
type: architecture_proposal
title: AP-726 Area Focus Branch Sandbox Preflight and Governed Dev/Forge Handoff
status: accepted
owner: programming
created_at: 2026-05-26
summary: Advances the Area Focus Loop from read-only operational to a preflight branch sandbox plan and a governed Dev/Forge handoff packet for agentic_engineering_os inside the Atlas Software Company Stewardship Stack. It composes the AP-719 work order plan, the AP-724 operator decision receipts and the AP-723 safety gates. It prepares branch metadata only (proposed branch name, base ref, worktree path, scope, rollback) in preflight/dry-run mode — it NEVER creates a branch/worktree, applies a fix, alters finding target code, dispatches Dev/Forge, merges, deploys, pushes or accesses secrets. A handoff becomes ready_for_handoff only with an explicit operator accept receipt; otherwise it is awaiting_operator_approval. If safety gates block, no handoff is prepared.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-719-area-focus-dev-forge-router-contract.md
  - docs/ap/AP-723-area-focus-safety-gates-contract.md
  - docs/ap/AP-724-area-focus-operator-decision-receipts-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOperatorDecisionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusGateEvaluatorService.php
  - app/Console/Commands/AtlasAreaFocusHandoffCommand.php
requires_evidence: true
risk_level: critical
---
# AP-726 Area Focus Branch Sandbox Preflight and Governed Dev/Forge Handoff

## Decision

The Area Focus Loop produces governed work orders (AP-719) and operator decision
receipts (AP-724). AP-726 adds the next governed step: for each operator-accepted
work order, it prepares a **preflight branch sandbox plan** (branch metadata
only) and a **governed handoff packet** routed to Atlas Dev (small/local) or
Forge (long-horizon). It prepares; it never executes.

Atlas Software Company Stewardship Stack é stack/capability family dentro do
Atlas Autonomous Software Company Runtime, não OS novo. AP-726 is a preflight /
handoff-preparation slice inside that stack. It is not a new OS, not a parallel
runtime and creates no new owner.

## Hard Boundary (preflight / metadata only)

- mode is always `preflight_dry_run`: it prepares **branch metadata only**
  (proposed branch name, base ref, worktree path, scope, rollback plan);
- it NEVER creates a branch or worktree (`branch_created = false`), NEVER applies
  a fix, NEVER alters the target code of a finding, NEVER dispatches Dev or
  Forge, and NEVER merges, deploys, pushes externally or accesses secrets;
- a handoff is `ready_for_handoff` ONLY with an explicit operator `accept`
  receipt (AP-724); without one it is `awaiting_operator_approval`;
- if the AP-723 safety gates return `block`, the whole preflight is blocked and
  no handoff/branch plan is prepared.

## Reuse Contract

| Input | Owner | AP |
|---|---|---|
| work orders | `AreaFocusDevForgeRouterService` | AP-719 |
| operator accept receipt (gate) | `AreaFocusOperatorDecisionService` | AP-724 |
| safety gates precondition | `AreaFocusGateEvaluatorService` | AP-723 |
| branch scope (allowed/forbidden paths) | `AtlasNightShiftAreaFocusContractRegistry` | AP-712 |

Receipts are matched to work orders by `finding_hash` (== work order
`source_ref`), optionally tightened by `work_order_id`.

AP-726 must pass AP-724 receipts through the active-operation path, including
CLI runs that use `--operator-receipts-file=<ap724.json|jsonl>`. Without that
file the correct status is `awaiting_operator_approval`; with a matching
`decision=accept` receipt the matching Dev/Forge handoff may become
`ready_for_handoff`.

Owner-doc compatibility: AP-726 delegates AP-723 safety gates and therefore
must treat AP-712 `area_owner_docs` and AP-716 `owner_docs` as equivalent owner
doc declarations. This prevents false `area_owner_docs_present` blocks when the
cycle originates from the AP-716 read model.

## Schemas

```text
atlas.software_company_stewardship.area_focus_branch_sandbox_plan.v1
atlas.software_company_stewardship.area_focus_handoff.v1
```

## Handoff Status

```text
ready_for_handoff               -> dev/forge work order with an operator accept receipt
awaiting_operator_approval      -> dev/forge work order with no accept receipt
rejected_by_operator            -> receipt decision = reject
deferred_by_operator            -> receipt decision = defer
changes_requested               -> receipt decision = request_changes
routed_to_self_directed_evolution -> route = self_directed_evolution (spec first, no branch)
not_handoffable_operator_review -> route = operator_review
```

Only `ready_for_handoff` carries a branch sandbox plan, and even then
`branch_created = false` (a future slice creates the real worktree under a
receipt).

## Acceptance

- An accepted dev work order yields `ready_for_handoff` to Atlas Dev with a
  branch plan and `branch_created = false`.
- An accepted forge work order yields `ready_for_handoff` to Forge.
- A work order without a receipt is `awaiting_operator_approval` with no branch
  plan.
- reject/defer/request_changes receipts never produce a handoff or branch.
- `operator_review` and `self_directed_evolution` routes are not Dev/Forge
  handoffs.
- A blocking safety gate blocks the whole preflight.
- No branch/worktree created, no fix applied, no target code altered, no
  dispatch, no merge/deploy/push/secrets/destructive change.
- Deterministic `report_hash` for the same input.
- docs-health and architecture-validate stay green.
