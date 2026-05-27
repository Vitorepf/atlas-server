# AP-777 · Continuous Stewardship 24h Readiness Gate

- **Owner:** Atlas Software Company Stewardship Stack / Atlas Continuous Stewardship Loop
- **Runtime:** `ContinuousStewardshipDayReadinessService`
- **CLI:** `php artisan atlas:software-company-stewardship continuous-24h-readiness --json`
- **Composes:** AP-745 scheduler-safe loop · AP-746 recurring scheduler · AP-754 Product Mode controls · AP-755 control receipts · AP-765 runtime result bridge · AP-766 continuous runner · AP-776 branch system certification.

## Intent

AP-777 is the start gate for the first real full-day stewardship run. It is
read-only and does not install a scheduler. It tells the operator whether Atlas
is safe to leave running for 24 hours in Area Focus mode for
`agentic_engineering_os/dev_forge`.

## Required Proofs

AP-777 requires:

1. AP-776 branch system certification is `certified`.
2. AP-766 runner status is `ready`.
3. Continuous runner is explicitly enabled.
4. Global and area kill switches are clear.
5. Daily budget allows at least one admitted run.
6. Runner lock is free.
7. 24h duration is requested.
8. Minimum interval is not dangerously low for a full-day run.
9. Product Mode controls, control receipts, cockpit and runtime result bridge are present.
10. CLI actions for readiness, runner, branch certification and Product Mode review are present.

## Output

Schema: `atlas.software_company_stewardship.continuous_24h_readiness.v1`

Statuses:

- `ready_for_24h_run`;
- `blocked`.

The report includes:

- policy;
- runner status;
- branch system certification;
- Product Mode / result bridge surface coverage;
- command coverage;
- operator start command;
- blockers;
- claim policy.

## Safety Boundary

AP-777 never starts the loop, creates a branch, creates a worktree, calls a
provider, runs Dev/Forge, merges, pushes, deploys or touches secrets. It only
certifies readiness and prints the exact command a scheduler/operator can use.

## Acceptance

- Default invocation blocks because the runner is disabled by default.
- Explicit enabled invocation with sane budget and interval returns
  `ready_for_24h_run`.
- Missing branch certification or Product Mode coverage blocks.
- CLI action `continuous-24h-readiness` exposes the report.
- Focused tests, docs-health and architecture-validate stay green.
