# AP-775 · Stewardship Repo Merge Lease

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipRepoMergeLeaseService.php`
- **Consumer:** AP-772 `StewardshipMergeQueueService`
- **CLI:** `repo-merge-lease-acquire`, `repo-merge-lease-release`, `repo-merge-lease-records`

## Purpose

AP-775 prevents concurrent 24/7 stewardship runners from executing merge queues
against the same repo/base line at the same time.

AP-772 already serializes branches inside one queue. AP-775 serializes queues
across runners.

## Rule

```text
one active merge lease per repo_root_hash + base_ref.
```

## Flow

```
runner wants execute_queue
  -> AP-775 acquire(repo_root, base_ref, owner, ttl)
  -> AP-772 live recheck + optional ff-only merge train
  -> AP-775 release when queue finishes
```

## Schema

- `atlas.software_company_stewardship.repo_merge_lease.v1`
- `atlas.software_company_stewardship.repo_merge_lease_record.v1`
- `atlas.software_company_stewardship.repo_merge_lease_records.v1`

## Enterprise Guarantees

- Append-only lease ledger.
- Same owner may reacquire reentrantly.
- Different owner is blocked while an active lease exists.
- Expired leases do not block future execution.
- Release requires the active owner.
- AP-775 never creates branches, worktrees, commits, merges, pushes, deploys or
  touches secrets.
- AP-772 execution mode requires AP-775; planning/review mode does not.

## CLI

```bash
php artisan atlas:software-company-stewardship repo-merge-lease-acquire \
  --repo-root=/path/to/repo \
  --base-ref=main \
  --lease-owner=runner-a \
  --lease-ttl=1800 \
  --json

php artisan atlas:software-company-stewardship repo-merge-lease-release \
  --repo-root=/path/to/repo \
  --base-ref=main \
  --lease-owner=runner-a \
  --release-reason=merge_queue_finished \
  --json
```

## Tests

`tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipRepoMergeLeaseServiceTest.php`

Coverage:

- acquire/reentrant acquire;
- block competing owner;
- release owner mismatch;
- release allows next owner;
- active lease records.
