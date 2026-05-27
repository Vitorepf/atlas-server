# AP-770 · Stewardship Branch Lifecycle Registry

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchLifecycleRegistryService.php`
- **CLI:** `php artisan atlas:software-company-stewardship branch-lifecycle-reserve` (+ `branch-lifecycle-records`)
- **Composes:** AP-756 branch sandbox materializer · AP-768 first full cycle · AP-769 branch merge governor.

## Purpose

AP-770 prevents branch conflicts before git mutation. AP-756 owns the physical
branch/worktree and AP-769 owns merge governance; AP-770 owns the durable branch
identity reservation so parallel 24/7 cycles cannot accidentally claim the same
branch name in the same repo.

## Flow

```
AP-768 cycle intent
  -> AP-770 branch lifecycle reservation
  -> AP-756 git worktree materialization
  -> owner execution
  -> AP-769 merge governance
  -> released | merged | blocked
```

## Branch Identity

The canonical collision key is:

```text
sha256(repo_root_hash | branch_name)
```

Active statuses are `reserved` and `materialized`. A second cycle with the same
branch identity is blocked unless it is idempotently replaying the same
`handoff_hash` or `sandbox_id`. Closed statuses (`merged`, `released`) do not
block reuse.

## Commands

```bash
php artisan atlas:software-company-stewardship branch-lifecycle-reserve \
  --branch-ref=atlas/area-focus/<area>/<slice> \
  --repo-root=/path/to/repo \
  --base-ref=main \
  --record-branch-registry \
  --json
```

```bash
php artisan atlas:software-company-stewardship branch-lifecycle-records \
  --area=agentic_engineering_os \
  --json
```

## Schemas

- `atlas.software_company_stewardship.branch_lifecycle_registry.v1`
- `atlas.software_company_stewardship.branch_lifecycle_registry_record.v1`
- `atlas.software_company_stewardship.branch_lifecycle_registry_records.v1`

## Enterprise Guarantees

- Branch reservation happens before AP-756 calls `git worktree add`.
- Parallel branch identity collision blocks before git mutation.
- Records are append-only JSONL and idempotent on `registry_id`.
- Lifecycle transitions can close a reservation as `merged` or `released`, so
  collision protection does not become a permanent stale lock.
- AP-770 never creates branches, worktrees, merges, pushes, deploys or touches secrets.
- AP-756 embeds an AP-770 summary in every materialized sandbox receipt.
- AP-769 remains the only merge governor; AP-770 does not replace merge policy.

## Tests

`tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchLifecycleRegistryServiceTest.php`

Coverage:

- reservation records idempotently;
- active duplicate branch identity blocks;
- released branch identity can be reused;
- AP-756 blocks a parallel materialization collision before another git mutation.
