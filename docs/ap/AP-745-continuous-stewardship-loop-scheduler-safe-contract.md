---
id: AP-745-continuous-stewardship-loop-scheduler-safe-contract
type: architecture_proposal
title: AP-745 Continuous Stewardship Loop Scheduler-Safe Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Promotes AP-744 Area Stewardship active operation into a scheduler-safe Atlas Continuous Stewardship Loop tick. The loop enforces disabled-by-default operation, kill switch, lock lease, rate limit, one-cycle budget and append-only JSONL cycle state. It reuses AP-744 and Product Mode; it creates no new OS, executor, provider path, branch manager, Dev/Forge dispatcher or mutation authority.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-711-night-shift-product-mode-contract.md
  - docs/ap/AP-713-area-stewardship-layer-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-745 Continuous Stewardship Loop Scheduler-Safe Contract

## Decision

AP-745 is the first scheduler-safe slice of **Atlas Continuous Stewardship
Loop**, the canonical 24h/always-on motor of the Atlas Software Company
Stewardship Stack.

It does not create a new OS or executor. It wraps AP-744 with operational
controls required before a scheduler, heartbeat or launchd job can call it:

```text
Product Mode controls
-> AP-745 loop admission
-> kill switch / lock lease / rate limit / one-cycle budget
-> AP-744 Area Stewardship active operation
-> append-only continuous cycle record
-> Product Mode review
```

## Anti-Duplication Resolution

The placement gate correctly reports overlap with Product Mode, Night Shift,
Area Focus, Area Stewardship and scheduler infrastructure. AP-745 resolves the
overlap by extension only:

- Product Mode remains the cockpit and control owner.
- Atlas Continuous Stewardship Loop remains the canonical 24h name.
- AP-744 remains the active Area Stewardship operation owner.
- Area Focus remains the cycle owner.
- Atlas Dev and Forge remain implementation owners.
- Existing scheduler/automation infrastructure may call AP-745 later; AP-745
  itself does not install launchd, create automations or spawn providers.

## Boundary

AP-745 may:

- project scheduler-safe loop state without running work;
- run one loop tick only when explicitly enabled;
- enforce kill switch, lock lease, min interval and max one cycle per tick;
- call AP-744 once per admitted tick;
- record an idempotent JSONL loop cycle when requested;
- expose status and controls in the existing Product Mode Cockpit.

AP-745 must not:

- run by default;
- bypass Product Mode controls;
- invoke providers;
- dispatch Atlas Dev or Forge;
- create branches, worktrees, commits, PRs or domains;
- mutate target repos;
- merge, deploy, push externally, touch secrets or perform destructive action;
- auto-approve AP-718 specs, AP-724 decisions or AP-726 handoffs;
- install scheduler jobs by itself;
- create a new OS/runtime/executor.

## Schemas

```text
atlas.continuous_stewardship.loop_state.v1
atlas.continuous_stewardship.loop_tick.v1
atlas.continuous_stewardship.loop_record.v1
```

## CLI

Projection/default-safe call:

```text
php artisan atlas:software-company-stewardship continuous-stewardship-loop --area=agentic_engineering_os --json
```

One admitted tick:

```text
php artisan atlas:software-company-stewardship continuous-stewardship-loop --area=agentic_engineering_os --enable-continuous-loop --json
```

Durable tick recording:

```text
php artisan atlas:software-company-stewardship continuous-stewardship-loop --area=agentic_engineering_os --enable-continuous-loop --record-continuous-cycle --json
```

## Status Semantics

| State | Meaning |
|---|---|
| `paused` | Loop is disabled or kill switch is active. |
| `ready_to_tick` | Controls admit one AP-744 tick. |
| `tick_completed` | One AP-744 tick completed in projection-only mode. |
| `tick_recorded` | One AP-744 tick completed and was recorded. |
| `rate_limited` | Last tick is still inside min interval. |
| `locked` | Another tick lease is still active. |
| `blocked` | AP-744 or admission policy blocked the tick. |

## Acceptance

- Service emits `atlas.continuous_stewardship.loop_state.v1` for projection.
- Service emits `atlas.continuous_stewardship.loop_tick.v1` for ticks.
- Default call is paused/disabled and never calls AP-744.
- Enabled tick calls AP-744 at most once.
- Kill switch blocks ticks even if enabled.
- Lock lease blocks concurrent ticks.
- Min interval blocks repeated ticks unless force is explicitly requested.
- Recording is append-only JSONL and idempotent.
- Product Mode exposes AP-745 status, counters and command anchors.
- Claim policy proves no provider, no Dev/Forge invocation, no branch, no repo
  mutation, no merge/deploy/secrets, no scheduler installation and operator
  review required.
