# AP-773 · Stewardship Branch Safety Audit

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchSafetyAuditService.php`
- **CLI:** `php artisan atlas:software-company-stewardship branch-safety-audit` (+ `branch-safety-audit-records`)
- **Composes:** AP-769 branch merge governor · AP-770 branch lifecycle registry · AP-772 merge queue.

## Purpose

AP-773 is the read-only safety audit that runs before the 24/7 stewardship loop
hands branches to AP-772. AP-769 can govern one branch and AP-772 can govern a
queue, but an always-on system also needs a branch hygiene layer that prevents
stale, orphaned or conflicted branches from silently entering the queue.

Core rule:

```text
only queue branches that are AP-769 safe and AP-770 lifecycle-owned.
```

## Flow

```
branch refs or branch prefix
  -> discover local cycle branches
  -> AP-769 governance per branch
  -> AP-770 active lifecycle lookup
  -> queue_ready | blocked
  -> queue_ready_branch_refs for AP-772
```

## Command

Audit supplied branches:

```bash
php artisan atlas:software-company-stewardship branch-safety-audit \
  --branch-ref="atlas/area-focus/a,atlas/area-focus/b" \
  --base-ref=main \
  --json
```

Discover local branches by prefix:

```bash
php artisan atlas:software-company-stewardship branch-safety-audit \
  --branch-prefix=atlas/area-focus/ \
  --record-branch-audit \
  --json
```

## Schema

- `atlas.software_company_stewardship.branch_safety_audit.v1`
- `atlas.software_company_stewardship.branch_safety_audit_record.v1`
- `atlas.software_company_stewardship.branch_safety_audit_records.v1`

## Enterprise Guarantees

- Read-only: no provider call, branch creation, worktree creation, cleanup,
  merge, push, deploy or secret access.
- Reuses AP-769 for graph/conflict/auto-merge policy instead of duplicating
  merge logic.
- Reuses AP-770 for branch ownership/lifecycle instead of trusting branch names
  alone.
- Blocks branches that are stale, conflicted, already merged or missing active
  lifecycle ownership.
- Emits `queue_ready_branch_refs` as the only safe input to AP-772.
- Keeps GitKraken review metadata per branch so the operator can visually audit
  commits and changed files.

## Tests

`tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchSafetyAuditServiceTest.php`

Coverage:

- audits queue-ready, stale and orphaned branches without mutating `main`;
- discovers local branches by prefix and records the audit append-only;
- blocks non-git roots and empty branch sets.
