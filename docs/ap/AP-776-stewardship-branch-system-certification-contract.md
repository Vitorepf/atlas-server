# AP-776 · Stewardship Branch System Certification

- **Owner:** Atlas Software Company Stewardship Stack / Area Focus Loop
- **Runtime:** `StewardshipBranchSystemCertificationService`
- **CLI:** `php artisan atlas:software-company-stewardship branch-system-certify --json`
- **Composes:** AP-769 branch merge governor · AP-770 branch lifecycle registry · AP-771 priority engine · AP-772 merge queue · AP-773 branch safety audit · AP-774 merge autonomy policy · AP-775 repo merge lease.

## Intent

AP-776 is the enterprise readiness certificate for the branch and merge system
used by 24/7 stewardship loops. It does not create a new branch system. It
proves the existing AP-769..AP-775 cluster is installed, wired, documented,
tested and exposed through the CLI before autonomous merge queues are trusted.

## Certification Scope

AP-776 verifies:

1. Required runtime classes exist.
2. Required runtime methods exist.
3. Required AP docs exist.
4. Required focused tests exist.
5. Required CLI actions exist.
6. The policy matrix covers visual review, conflict prevention, parallel
   collision prevention, priority ordering, safe auto-merge and forbidden
   operations.

## Output

Schema: `atlas.software_company_stewardship.branch_system_certification.v1`

Statuses:

- `certified`: all required components, docs, tests, CLI actions and policies
  are present.
- `blocked`: one or more required proofs are missing.

The certificate emits:

- `enterprise_requirements`;
- `components`;
- `command_actions`;
- `policy_matrix`;
- `blockers`;
- `claim_policy`;
- `certification_hash`.

## Safety Boundary

AP-776 is read-only. It never creates branches, worktrees or commits; never
merges, pushes, deploys or accesses secrets; never invokes providers. It is a
proof gate for readiness, not an executor.

## Optional Readiness Extension

AP-776 may embed AP-779 when the operator requests real git stress:

```bash
php artisan atlas:software-company-stewardship branch-system-certify --include-branch-stress --json
```

When `--include-branch-stress` is set, AP-776 blocks if AP-779 stress is not
`certified`. Without the flag, `optional_readiness_extensions.branch_stress`
remains `not_run` and AP-776 stays a fast static install proof.

## Acceptance

- `branch-system-certify --json` returns `status=certified` in the current repo.
- `branch-stress-certify --json` returns `status=certified` with fourteen disposable git scenarios.
- Removing any required doc/test/method/CLI action makes the certificate block.
- Focused tests cover certified and blocked paths.
- `docs-health` and `architecture-validate` remain green.
