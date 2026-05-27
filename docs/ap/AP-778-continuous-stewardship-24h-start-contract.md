# AP-778 · Continuous Stewardship 24h Start

- **Owner:** Atlas Software Company Stewardship Stack / Atlas Continuous Stewardship Loop
- **Runtime:** `ContinuousStewardshipDayStartService`
- **CLI:** `php artisan atlas:software-company-stewardship continuous-24h-start --json`
- **Composes:** AP-777 readiness gate · AP-766 continuous runner.

## Intent

AP-778 is the smallest real start slice for the Atlas Continuous Stewardship
Loop. It proves the loop can move from readiness into one AP-766 `execute`
runner invocation, records an append-only JSONL start receipt, and prints the
external recurrence command for the operator.

## Contract

AP-778 must:

1. run AP-777 readiness first;
2. block unless readiness is `ready_for_24h_run`;
3. run at most one AP-766 runner tick in `mode=execute` when
   `--execute-first-tick` is explicit;
4. record the AP-778 start receipt when `--record` is explicit;
5. return machine-readable JSON with readiness, runner receipt, tick status,
   next allowed time, blockers, next operator action and claim policy;
6. never install launchd/cron, create branches/worktrees, call providers,
   dispatch Dev/Forge, merge, deploy, push or touch secrets.

## Statuses

- `first_tick_executed`: AP-777 was ready and AP-766 admitted the first tick.
- `blocked`: readiness failed, `--execute-first-tick` was missing, or AP-766 did
  not admit the tick.

## Safety Boundary

AP-778 is a start wrapper, not a scheduler. It only emits
`operator_next_command` for the operator or an explicitly separate scheduler to
run later. If AP-766 reports `not_due`, locked, paused or blocked, AP-778 reports
`blocked` and does not claim a completed start.

## Acceptance

- Readiness blocked prevents AP-766 invocation.
- Readiness ready invokes AP-766 once.
- Kill switch and low interval block before the runner.
- Start receipt is append-only JSONL and replay/list are available.
- CLI smoke emits JSON.
