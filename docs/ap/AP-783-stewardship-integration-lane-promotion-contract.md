---
title: "AP-783 Stewardship Integration Lane Promotion Contract"
status: implemented
owner: software_company_stewardship
ap: AP-783
created: 2026-05-27
updated: 2026-05-27
---

# AP-783 Stewardship Integration Lane Promotion Contract

## Purpose

AP-783 is the real, non-deferred step that fast-forwards a visible
`atlas/integration/*` lane into a **clean** base ref (typically `main`) after
AP-782 advanced safe candidates into the lane.

It exists because AP-782 deliberately never mutates `main` while the operator
worktree is dirty. AP-783 is the governed promotion gate that runs only when the
base worktree is clean, the repo/base lease is acquired, and AP-769 proves the
lane is ff-only auto-merge eligible.

## Canonical Command

```bash
php artisan atlas:software-company-stewardship:integration-lane-promote \
  --lane-ref=atlas/integration/agentic_engineering_os/main \
  --base-ref=main \
  --area=agentic_engineering_os \
  --record \
  --json
```

## Owner

- Service:
  `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLanePromotionService.php`
- Command:
  `app/Console/Commands/AtlasSoftwareCompanyIntegrationLanePromoteCommand.php`
- Test:
  `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLanePromotionServiceTest.php`

## Composes

- AP-769 Stewardship Branch Merge Governor (eligibility + ff-only merge)
- AP-775 Stewardship Repo Merge Lease (serialise base promotion)
- AP-782 Stewardship Integration Lane (lane must already exist and be visible)

## Promotion Gates (all required)

1. `lane_ref` starts with `atlas/integration/`.
2. `base_ref` exists and the repo root is a git worktree.
3. **Base worktree is clean** (`git status --porcelain` empty) before any ref move.
4. AP-775 lease acquired for `repo_root_hash + base_ref`.
5. AP-769 reports `auto_merge_eligible` for `lane_ref` against `base_ref`.
6. AP-769 executes `git merge --ff-only` via `execute_merge`.
7. Lease is always released when this service acquired it.

## Hard Boundary

AP-783 never:

- runs on a dirty base worktree;
- push;
- deploy;
- call providers;
- access secrets;
- `git reset --hard`, `checkout --`, force-push, squash, or destructive rebase.

## Receipt

Schema: `atlas.software_company_stewardship.integration_lane_promotion.v1`

Statuses:

- `promoted` — base fast-forwarded to the lane commit.
- `promoted_noop` — merge reported success but base already matched lane.
- `already_promoted` — base already at lane commit before merge attempt.
- `blocked` — no base mutation when `base_untouched_on_block` is true.

Required fields:

- `promotion_id`, `lane_ref`, `base_ref`, `area_id`
- `lease_status`, `governance_status`
- `base_before`, `base_after`, `lane_commit`
- `promoted`, `base_untouched_on_block`, `blockers`, `evidence`
- `claim_policy.dangerous_actions` = false

## Acceptance

- All six disposable-git unit scenarios pass.
- AP-776 certifies AP-783 component + `integration_lane_promotion` policy.
- Dedicated CLI registered in `bootstrap/app.php`.
