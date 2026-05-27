# AP-769 · Stewardship Branch Merge Governor

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php`
- **CLI:** `php artisan atlas:software-company-stewardship branch-merge-governor`
- **Composes:** AP-756 branch/worktree sandbox · AP-765 result/evidence bridge · AP-767/768 execution/cycle receipts · Product Mode operator review.

## Purpose

Make every 24/7 stewardship branch safe, visual and governable before it can
reach `main`.

AP-769 exists because a cycle is not operationally complete just because a
provider produced a patch. The operator must see a clean branch/commit in
GitKraken, Atlas must detect conflicts before merge, and low-risk changes may
auto-merge only when a strict policy proves the branch is safe.

## Flow

```
AP-756 sandbox branch/worktree
  -> AP-767/AP-768 owner execution result
  -> AP-765 evidence + inbox + Product Mode event
  -> AP-769 branch merge governor
  -> review_required | auto_merge_eligible | merged | blocked
```

## Command

```bash
php artisan atlas:software-company-stewardship branch-merge-governor \
  --branch-ref=<cycle-branch> \
  --base-ref=main \
  --json
```

Optional policy-gated auto-merge:

```bash
php artisan atlas:software-company-stewardship branch-merge-governor \
  --branch-ref=<cycle-branch> \
  --base-ref=main \
  --auto-merge \
  --execute-merge \
  --record-governance \
  --json
```

Code auto-merge is stricter:

```bash
php artisan atlas:software-company-stewardship branch-merge-governor \
  --branch-ref=<cycle-branch> \
  --base-ref=main \
  --auto-merge \
  --execute-merge \
  --auto-merge-class=bugfix \
  --allow-code-auto-merge \
  --run-validation \
  --test-command="php artisan test <focused-test>" \
  --json
```

## Schemas

- `atlas.software_company_stewardship.branch_merge_governor.v1`
- `atlas.software_company_stewardship.branch_merge_governor_record.v1`
- `atlas.software_company_stewardship.branch_merge_governor_records.v1`

## Enterprise Gates

AP-769 blocks when:

- branch ref is missing or not found;
- base ref is missing;
- branch is stale/diverged (`base` is not ancestor of branch);
- branch is already merged;
- `git merge-tree --write-tree base branch` detects conflict;
- base worktree is dirty;
- auto-merge is requested but policy is not satisfied.

AP-769 allows auto-merge only when:

- merge is conflict-free;
- base is ancestor of branch;
- branch has at least one commit;
- changed file count is within policy;
- class is `documentation_only`, `tests_only`, or `docs_and_tests`; or code
  class is explicitly declared `bugfix|cleanup`, `--allow-code-auto-merge` is
  present, and validations passed;
- merge strategy is `ff_only`.

## GitKraken Review Surface

Every report includes:

- `visible_branch_ref`;
- `visible_base_ref`;
- `reviewable_commit_count`;
- `reviewable_commits`;
- `changed_files`;
- `graph_shape`;
- operator review hint.

This is the contract that keeps branch output visually inspectable in GitKraken.

## Hard Invariants

- No rebase.
- No force-push.
- No squash.
- No deploy.
- No secret access.
- No merge by default.
- Auto-merge is ff-only and policy-gated.
- Anything code-like defaults to operator review unless explicitly authorized
  with focused validation.

## Tests

`tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorServiceTest.php`

Coverage:

- docs-only branch is auto-merge eligible and GitKraken-visible;
- code/mixed branch requires operator review by default;
- conflict blocks before merge;
- `--execute-merge` fast-forwards only when policy allows.
