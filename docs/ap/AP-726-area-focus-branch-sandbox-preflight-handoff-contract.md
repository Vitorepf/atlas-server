---
id: AP-726-area-focus-branch-sandbox-preflight-handoff-contract
type: architecture_proposal
title: AP-726 Area Focus Branch Sandbox Preflight + Governed Handoff Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Adds the branch sandbox preflight + governed Dev/Forge handoff stage to the Area Focus Loop. After an operator accept (AP-724) of an emitted work order (AP-719) that passes the safety gates (AP-723), Atlas prepares a branch-metadata-only, dry-run sandbox plan and a handoff packet for Atlas Dev / Forge. It creates no branch, touches no target code, and never merges, deploys, pushes, accesses secrets or makes destructive changes. Materializing the branch requires an explicit branch-creation receipt in a future slice.
related_paths:
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-719-area-focus-dev-forge-router-contract.md
  - docs/ap/AP-723-area-focus-safety-gates-contract.md
  - docs/ap/AP-724-area-focus-operator-decision-receipts-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxPreflightService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOperatorDecisionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusGateEvaluatorService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxPreflightServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-726 Area Focus Branch Sandbox Preflight + Governed Handoff Contract

> Atlas Software Company Stewardship Stack is a stack/capability family inside
> the Atlas Autonomous Software Company Runtime, not a new OS.

## Context

The Area Focus Loop is read-only and operational through Slice 10: read model
(AP-716), finding engine (AP-717), inbox + spec bridge (AP-718), Dev/Forge work
order router (AP-719), durable cycle + evidence pack (AP-720), HTTP surface
(AP-721), operational certification/orchestrator (AP-722), safety gates
(AP-723), operator decision receipts (AP-724) and loop certification (AP-725).

The next stage moves from "operational read-only" toward execution: after the
operator accepts a work order, Atlas must **prepare** a branch sandbox and a
governed handoff to Atlas Dev / Forge — without yet mutating anything.

## Decision

Add `AreaFocusBranchSandboxPreflightService` (a pure transformer reusing existing
owners) that turns an operator accept into a **branch-metadata-only, dry-run
preflight plan** plus a **handoff packet**.

- Mode is `dry_run_preflight`. It creates **no branch**, **no worktree** and
  touches **no target code**.
- It reuses, never re-derives: the operator decision receipt (AP-724), the work
  order (AP-719) and the safety gate report (AP-723). It creates no new owner,
  OS, runtime or parallel registry.
- Materializing the branch requires an explicit branch-creation receipt in a
  future slice; this slice declares `branch_creation_receipt_required = true`.

## Preconditions (block if any fails)

1. Operator decision is `accept` and `executed = false` (AP-724).
2. The decision and work order reference the same work order (when the receipt
   carries `work_order_id`).
3. The work order route is executable: `atlas_dev` or `forge`
   (`self_directed_evolution` / `operator_review` do not get a branch sandbox).
4. The work order status is `emitted` (not `blocked`).
5. The safety gate report (AP-723) decision is not `block`.

## Output schema

`atlas.software_company_stewardship.area_focus_branch_sandbox_preflight.v1`:

| Key | Meaning |
|---|---|
| `status` | `ready` (preflight prepared) or `blocked` (precondition failed) |
| `preconditions` | each check with pass/fail + detail |
| `branch_plan` | deterministic branch name, base ref plan, planned worktree path, isolation policy — none created |
| `handoff_packet` | target owner (Dev/Forge), work order + decision refs, required validations, scope constraints |
| `safety` | the reused gate decision + blocked/warned reasons |
| `governance` | max_governed envelope + claim policy |
| `next_actions` | operator-facing steps; materialization needs an explicit receipt |
| `preflight_hash` | deterministic (excludes wall-clock) |

## Forbidden in this slice

- Creating a real branch or worktree; touching, staging or fixing target code.
- Merging, deploying, pushing externally, accessing secrets or any destructive
  change.
- Invoking a provider, dispatching Dev/Forge, or executing the work order.
- Re-deriving routing/classification (anchor on the AP-719 work order route).
- Creating a new OS, runtime or parallel Area Focus executor.

## Acceptance

- Accept + emitted executable work order + non-blocking gates → `status=ready`
  with a deterministic branch plan, handoff packet and `branch_created=false`.
- Non-accept decision, route mismatch, non-executable route, blocked work order
  or blocking gates → `status=blocked` with a stable reason; nothing prepared.
- Deterministic `preflight_hash`; no branch/worktree/code mutation.
- Focused tests pass; docs-health and architecture-validate stay green.
