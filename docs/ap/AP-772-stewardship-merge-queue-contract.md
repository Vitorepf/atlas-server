# AP-772 · Stewardship Merge Queue

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php`
- **CLI:** `php artisan atlas:software-company-stewardship merge-queue` (+ `merge-queue-records`)
- **Composes:** AP-769 branch merge governor · AP-771 priority engine · AP-770 branch lifecycle registry.

## Purpose

AP-772 is the merge train for the 24/7 stewardship loop. AP-769 governs one
branch at a time; AP-772 governs a batch of branches so Atlas does not
auto-merge stale assumptions in parallel.

Core rule:

```text
rank branches by advancement/robustness, then re-check the live base before
each optional ff-only auto-merge.
```

## Flow

```
branch refs
  -> AP-769 initial governance per branch
  -> AP-771 priority order
  -> sequential live AP-769 recheck
  -> auto_merged_ff_only | review_required | blocked
```

## Command

Plan/review only:

```bash
php artisan atlas:software-company-stewardship merge-queue \
  --branch-ref="atlas/area-focus/a,atlas/area-focus/b" \
  --base-ref=main \
  --json
```

Policy-gated execution:

```bash
php artisan atlas:software-company-stewardship merge-queue \
  --queue-file=/path/to/branches.json \
  --auto-merge \
  --execute-queue \
  --record-queue \
  --json
```

The queue file may be a list of branch refs or an object with `branch_refs` or
`branches`.

## Schema

- `atlas.software_company_stewardship.merge_queue.v1`
- `atlas.software_company_stewardship.merge_queue_record.v1`
- `atlas.software_company_stewardship.merge_queue_records.v1`

## Enterprise Guarantees

- No parallel merge.
- Every branch receives AP-769 GitKraken review metadata.
- Every branch is ranked by AP-771 before execution.
- Each branch is re-evaluated against the live base immediately before merge.
- Auto-merge still uses AP-769 fast-forward-only policy.
- If one auto-merge updates `main`, later branches based on the old `main` are
  blocked/reviewed instead of silently merged.
- No provider call, branch creation, worktree creation, deploy, push or secret access.

## Tests

`tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueServiceTest.php`

Coverage:

- plans visible order without merging;
- executes one safe ff-only merge, then blocks/reviews a stale next branch after
  live-base recheck;
- blocks when no branches are supplied.
