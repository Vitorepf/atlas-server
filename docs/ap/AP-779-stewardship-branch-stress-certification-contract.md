---
title: "AP-779 Stewardship Branch Stress Certification Contract"
status: implemented
owner: software_company_stewardship
ap: AP-779
created: 2026-05-27
updated: 2026-05-27
---

# AP-779 Stewardship Branch Stress Certification Contract

## Purpose

AP-779 is the real git-backed stress certification for the Atlas Software
Company Stewardship branch stack. It does not create a second branch system. It
extends and certifies AP-769 through AP-776 using disposable git repositories so
24/7 stewardship can trust branch review, conflict prevention, priority ordering
and safe auto-merge boundaries before touching operator repositories.

## Canonical Command

```bash
php artisan atlas:software-company-stewardship branch-stress-certify --json
```

Optional AP-776 extension:

```bash
php artisan atlas:software-company-stewardship branch-system-certify --include-branch-stress --json
```

## Runtime Owner

`App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchStressCertificationService`

## Source Contracts

- AP-769 Stewardship Branch Merge Governor
- AP-770 Stewardship Branch Lifecycle Registry
- AP-771 Stewardship Priority Engine
- AP-772 Stewardship Merge Queue
- AP-773 Stewardship Branch Safety Audit
- AP-774 Stewardship Merge Autonomy Policy
- AP-775 Stewardship Repo Merge Lease
- AP-776 Stewardship Branch System Certification
- AP-779 Stewardship Branch Stress Certification

## Required Stress Scenarios

AP-779 must run real `git` commands against disposable repositories under
`storage/atlas/software_company_stewardship/branch_stress_certification` (or a
test-provided `tmp_root`) and prove:

1. Documentation-only branch exposes GitKraken review metadata and can be
   fast-forward auto-merged when AP-769/AP-774 allow it.
2. Tests-only branch can be fast-forward auto-merged when policy allows it.
3. Tests-only branch with green validation can still auto-merge when validation
   is explicitly run.
4. Code or mixed branch blocks automatic merge without explicit operator code
   authorization and validation evidence.
5. A real content conflict blocks before merge execution.
6. A stale or diverged branch blocks before merge execution even when there is
   no content conflict.
7. AP-773 blocks orphan branches that lack an active lifecycle registry record.
8. AP-775 prevents two owners from holding the same repo/base merge lease.
9. AP-772 blocks queue execution when another runner already holds the lease.
10. AP-772 never uses rebase, squash or force-push (ff-only via AP-769).
11. AP-772 keeps `main` as the primary integration branch after execution.
12. AP-772 orders advancement work ahead of cosmetic or risky branches via AP-771.
13. AP-769 GitKraken review surface links `finding_id`, `spec_id`, `receipt_id`,
    `handoff_id` and `sandbox_id` when supplied.
14. AP-771 ranks high-advancement Dev/Forge risk reduction above cosmetic doc
    polish.

## Forbidden Operations

The certification must never:

- mutate the operator target repository;
- push;
- deploy;
- access secrets;
- force-push;
- rebase;
- squash;
- reset target history;
- claim provider, Dev or Forge execution.

## Output Schema

`atlas.software_company_stewardship.branch_stress_certification.v1`

Required fields:

- `status`: `certified | blocked`
- `scenario_count`
- `passed_scenario_count`
- `scenarios[]`
- `enterprise_guarantees`
- `blockers[]`
- `claim_policy`
- `stress_certification_hash`

## Acceptance

AP-779 is accepted when:

- the CLI action `branch-stress-certify` exists;
- all fourteen stress scenarios pass in disposable git repositories;
- focused tests pass;
- `docs-health` and `architecture-validate` remain green;
- AP-779 is referenced by the Software Company Stewardship Stack docs;
- AP-776 can optionally embed AP-779 via `--include-branch-stress`.
