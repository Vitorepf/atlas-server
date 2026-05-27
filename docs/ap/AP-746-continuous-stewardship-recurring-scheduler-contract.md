---
id: AP-746-continuous-stewardship-recurring-scheduler-contract
type: architecture_proposal
title: AP-746 Continuous Stewardship Recurring Scheduler Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Promotes AP-745 into a recurring scheduler-safe runner that an external cron, heartbeat, launchd or automation caller may invoke repeatedly. It is disabled by default, enforces pause policy, kill switch, AP-745 lock lease/rate limit and max one AP-745 tick per invocation, and can record append-only JSONL scheduler evidence. It does not install a scheduler, create a new OS/runtime/executor, invoke providers, dispatch Dev/Forge, create branches or mutate target repos.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-711-night-shift-product-mode-contract.md
  - docs/ap/AP-713-area-stewardship-layer-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - docs/ap/AP-745-continuous-stewardship-loop-scheduler-safe-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipRecurringSchedulerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipRecurringSchedulerServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-746 Continuous Stewardship Recurring Scheduler Contract

## Decision

AP-746 is the recurring-scheduler-safe invocation layer for **Atlas Continuous
Stewardship Loop**. It exists so an external scheduler can call the 24h motor
without giving the scheduler new authority.

Canonical flow:

```text
operator-owned scheduler/heartbeat/launchd
-> AP-746 recurring scheduler admission
-> pause policy / kill switch / due check
-> AP-745 one tick max
-> optional AP-746 scheduler JSONL record
-> Product Mode/Cockpit review
```

AP-746 is a thin runner over AP-745. It is not Night Shift, not Product Mode,
not an OS, not an executor, not a scheduler installer and not a Dev/Forge
dispatcher.

## Anti-Duplication Resolution

The placement gate reports overlap with AP-745, Product Mode, Night Shift,
Area Stewardship and scheduler infrastructure. That overlap is correct.

AP-746 resolves it by reuse:

- AP-745 remains the owner of one scheduler-safe Continuous Stewardship tick.
- Product Mode/Cockpit remains the visual review and operator-control surface.
- Existing external automation infrastructure may call AP-746, but AP-746 never
  installs or owns that automation.
- Area Stewardship, Area Focus, Self-Directed Evolution, Atlas Dev, Forge and
  Evidence remain their own owners.
- No `Stewardship OS`, `Night Shift OS`, scheduler runtime or executor is
  created for this capability.

## Boundary

AP-746 may:

- project recurring scheduler state without ticking;
- accept repeated external invocations;
- enforce disabled-by-default admission;
- enforce pause policy and kill switch;
- reuse AP-745 lock lease and rate limit;
- call AP-745 at most once per invocation;
- optionally persist idempotent scheduler run records as append-only JSONL;
- expose scheduler state, counters and controls in Product Mode/Cockpit.

AP-746 must not:

- run unless explicitly enabled;
- install cron, launchd, heartbeat, automations or background daemons;
- create a new runtime, OS, executor, branch manager or provider path;
- invoke providers directly;
- dispatch Atlas Dev or Forge directly;
- create branches, worktrees, commits, PRs, domains or departments;
- mutate target repos;
- merge, deploy, push externally, touch secrets or perform destructive action;
- auto-approve specs, handoffs, operator decisions or promotions;
- bypass AP-745, Product Mode, Evidence or operator review.

## Schemas

```text
atlas.continuous_stewardship.recurring_scheduler.v1
atlas.continuous_stewardship.recurring_scheduler_run.v1
atlas.continuous_stewardship.recurring_scheduler_record.v1
```

## CLI

Default-safe projection/run boundary:

```text
php artisan atlas:software-company-stewardship continuous-stewardship-scheduler --area=agentic_engineering_os --json
```

One admitted recurring scheduler invocation:

```text
php artisan atlas:software-company-stewardship continuous-stewardship-scheduler --area=agentic_engineering_os --enable-continuous-scheduler --json
```

Durable scheduler evidence:

```text
php artisan atlas:software-company-stewardship continuous-stewardship-scheduler --area=agentic_engineering_os --enable-continuous-scheduler --record-scheduler-run --record-continuous-cycle --json
```

Manual verification only:

```text
php artisan atlas:software-company-stewardship continuous-stewardship-scheduler --area=agentic_engineering_os --enable-continuous-scheduler --force-scheduler-run --json
```

## Status Semantics

| State | Meaning |
|---|---|
| `paused` | Scheduler runner is disabled, paused or kill switch is active. |
| `scheduled` | One AP-745 tick is due and may be admitted. |
| `not_due` | AP-745 min interval says the next tick is not due. |
| `run_completed` | AP-746 admitted one AP-745 tick without scheduler record. |
| `run_recorded` | AP-746 admitted one AP-745 tick and recorded scheduler evidence. |
| `rate_limited` | AP-745 returned rate limit during tick execution. |
| `locked` | AP-745 lock lease is active. |
| `blocked` | AP-745 or policy blocked the run. |

## Product Mode

Product Mode/Cockpit must show:

- scheduler status;
- scheduler run id and hash;
- AP-745 tick status;
- paused/scheduled/not-due/run/recorded counters;
- operator commands for run and record;
- review queue item for scheduled or completed scheduler runs;
- claim policy proving the cockpit did not execute the scheduler.

## Acceptance

- Service emits `atlas.continuous_stewardship.recurring_scheduler.v1`.
- Service emits `atlas.continuous_stewardship.recurring_scheduler_run.v1`.
- Default invocation is `paused` and never calls AP-745.
- Enabled projection becomes `scheduled` when AP-745 is ready.
- Enabled run calls AP-745 at most once.
- Pause policy blocks even when enabled.
- Kill switch blocks even when enabled.
- AP-745 rate limit becomes AP-746 `not_due`.
- Recording is append-only JSONL and idempotent.
- CLI exposes `continuous-stewardship-scheduler`.
- Product Mode exposes AP-746 status, counters, commands and review queue item.
- Claim policy proves no provider, no Dev/Forge invocation, no branch, no repo
  mutation, no merge/deploy/secrets, no scheduler installation and operator
  review required.
