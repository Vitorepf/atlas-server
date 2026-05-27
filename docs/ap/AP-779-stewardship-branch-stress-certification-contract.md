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

AP-779 must run real `git` commands against disposable repositories and prove:

1. Documentation-only branch exposes GitKraken review metadata and can be
   fast-forward auto-merged when AP-769/AP-774 allow it.
2. Tests-only branch can be fast-forward auto-merged when policy allows it.
3. Code or mixed branch blocks automatic merge without explicit operator code
   authorization and validation evidence.
4. A real content conflict blocks before merge execution.
5. A stale or diverged branch blocks before merge execution even when there is
   no content conflict.
6. AP-775 prevents two owners from holding the same repo/base merge lease.
7. AP-771 ranks high-advancement Dev/Forge risk reduction above cosmetic doc
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
- all seven stress scenarios pass in a real git disposable repository;
- focused tests pass;
- `docs-health` and `architecture-validate` remain green;
- AP-779 is referenced by the Software Company Stewardship Stack docs.
