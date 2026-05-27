# AP-782 · Stewardship Integration Lane

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLaneService.php`
- **Test:** `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLaneServiceTest.php`
- **Composes:** AP-769 branch merge governor · AP-780 branch review packet.

## Purpose

AP-782 gives the 24/7 loop a safe place to advance auto-merge candidates when
the operator's primary `main` worktree is dirty.

It exists because merging `main` from a parallel worktree while the primary
worktree is dirty can move the branch ref and leave the primary checkout looking
like it deleted files. That is not enterprise-safe. AP-782 therefore advances a
visible integration branch instead:

```text
candidate branch
  -> AP-769 review-only governance
  -> AP-780 review packet
  -> atlas/integration/<area>/<base>
  -> later AP-769/AP-772 ff-only merge to main when base is clean and leased
```

## Guarantees

- Only AP-769 `auto_merge_eligible` branches may enter the lane.
- The integration lane ref is always under `atlas/integration/*`.
- The base branch is never mutated by AP-782.
- The candidate must fast-forward the current lane.
- No rebase, squash, force-push, push, deploy, provider call or secret access.
- The lane is visible in GitKraken as an ordinary local branch.

## Receipt

Schema: `atlas.software_company_stewardship.integration_lane.v1`

Required fields:

- `integration_id`
- `repo.base_ref`
- `repo.base_commit_before`
- `repo.base_commit_after`
- `repo.base_untouched`
- `candidate.branch_ref`
- `candidate.branch_commit`
- `integration_lane.lane_ref`
- `integration_lane.lane_commit_before`
- `integration_lane.lane_commit_after`
- `governance_report`
- `branch_review_packet`
- `claim_policy`

`repo.base_untouched` must stay true. AP-782 prepares integration; AP-769/AP-772
perform the final fast-forward to the base branch only when the base worktree is
clean and the repo/base lease is acquired.
