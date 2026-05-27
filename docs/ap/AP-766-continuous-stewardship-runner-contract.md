---
id: AP-766-continuous-stewardship-runner
type: ap_contract
title: AP-766 Continuous Stewardship Runner Contract
status: active
owner: programming
summary: Adds the operator-facing 24h runner control plane for the Atlas Software Company Stewardship Stack. AP-766 does not reimplement the scheduler-safe tick; it composes AP-746 (which composes AP-745 -> AP-744) and adds the control-plane primitives those layers do not own - per-area kill switch, daily run budget per area, runner-scoped idempotent lock, explicit dry-run/execute modes, a component-bridge probe and a single unified run receipt. It creates no scheduler, no provider call, no branch, no Dev/Forge dispatch and no Codex app automation.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - docs/ap/AP-745-continuous-stewardship-loop-scheduler-safe-contract.md
  - docs/ap/AP-746-continuous-stewardship-recurring-scheduler-contract.md
  - docs/ap/AP-764-atlas-native-stewardship-obra-runner-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/ContinuousStewardshipRunnerService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - app/Console/Commands/Concerns/RendersContinuousStewardshipRunner.php
  - config/atlas.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/ContinuousStewardship/ContinuousStewardshipRunnerServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-766 Continuous Stewardship Runner Contract

## Decision

AP-766 is the operator-facing 24h **runner control plane** for the Atlas
Software Company Stewardship Stack, focused on the `agentic_engineering_os`
area (Atlas Dev + Forge). It is the single entrypoint an external scheduler,
heartbeat or operator can invoke repeatedly to drive the loop without depending
on Codex automation.

The canonical rule is:

```text
The runner owns scheduling safety (kill, pause, budget, lock, rate) and a
unified run receipt. It owns NO execution authority. The tick itself, the
finding cycle and the Dev/Forge path remain in their existing owners; the
runner composes them and never reimplements them.
```

The run receipt schema is:

```text
atlas.software_company_stewardship.continuous_runner.v1
```

The append-only run record schema is:

```text
atlas.software_company_stewardship.continuous_runner_record.v1
```

The status read-model schema is:

```text
atlas.software_company_stewardship.continuous_runner_status.v1
```

## Duplicate Resolution

AP-766 intentionally overlaps with the existing scheduler stack and resolves the
overlap by reuse only:

- AP-744 remains the active Area Stewardship operating slice (finding cycle,
  spec drafts, branch-handoff preflight).
- AP-745 remains the scheduler-safe tick (its own lock, rate limit, kill switch).
- AP-746 remains the recurring scheduler-safe boundary (pause, scheduler run
  records). **The runner delegates exactly one AP-746 tick when admitted.**
- AP-764 remains the Atlas-native Obra runner that converts ready AP-744
  handoffs into native Obra packets.

AP-766 does not supersede any of them. It adds only the control-plane primitives
that none of them own:

| Primitive | Owner before AP-766 | AP-766 adds |
|---|---|---|
| Global kill switch | AP-745/AP-746 | reused + surfaced |
| Per-area kill switch | none | **new** |
| Daily run budget per area | none | **new** |
| Runner-scoped idempotent lock | none (AP-745 lock wraps only the tick) | **new** |
| Pause-until | AP-746 | reused + surfaced |
| Rate limit / min interval | AP-745 | reused + surfaced |
| dry-run vs execute mode | implicit (project vs run) | **explicit** |
| Component-bridge probe | none | **new** |
| Unified operator run receipt | none | **new** |

## Run Receipt

Every invocation returns a receipt with at least:

- `runner_id`, `area_id`, `mode` (`dry-run`|`execute`);
- `tick_status`, `tick_attempted`, `tick_admitted`;
- `lock_status` (`free`|`held_by_other`|`acquired_released`);
- `budget_status` (`within_budget`|`exhausted`) plus a `budget` block
  (`day`, `max_runs_per_day`, `used_today`, `remaining`);
- `kill_switch_status` (`clear`|`area_active`|`global_active`);
- `pause_status` (`clear`|`paused_until`);
- `next_allowed_at`;
- `invoked_components` (the four named bridges, each with presence + status);
- `evidence_refs`, `inbox_refs`;
- `errors`, `blockers`, `next_actions`;
- `claim_policy` and a deterministic `run_hash`.

## Component Bridges

The runner is prepared to drive four bridges and reports each honestly:

| Bridge | Class | Runner may invoke | This-slice status |
|---|---|---|---|
| `finding_engine` | `AgenticEngineeringOsFindingEngineService` | yes (read-only, via tick) | `invoked_via_tick` / `simulated_via_injected_operation` |
| `branch_materializer` | `AreaFocusBranchSandboxMaterializerService` | no | `deferred_by_governance` |
| `dev_forge_bridge` | `AreaFocusDevForgeReleaseService` | no | `deferred_by_governance` |
| `evidence_bridge` | `StewardshipOutcomeEvidenceBridgeService` | no | `deferred_by_governance` |

A bridge whose class is not present is reported as `deferred_component_missing`,
never silently skipped. Governance-deferred and dry-run bridges report
`deferred_by_governance` / `deferred_dry_run`; an unadmitted tick reports
`not_reached`.

## CLI

Projection (safe, default mode, never writes unless `--record-runner-run`):

```bash
php artisan atlas:software-company-stewardship continuous-runner --area=agentic_engineering_os --mode=dry-run --json
```

Execute (one admitted AP-746 tick, records the run receipt):

```bash
php artisan atlas:software-company-stewardship continuous-runner --area=agentic_engineering_os --enable-continuous-runner --mode=execute --json
```

Status read-model (last run, budget usage, gate state, next steps):

```bash
php artisan atlas:software-company-stewardship continuous-runner-status --area=agentic_engineering_os --json
```

Manual verification may use `--min-interval-seconds=0`, `--max-runs-per-day`
and `--runner-lock-ttl-seconds`, but unattended operation must respect the
AP-766 gates and the AP-745/AP-746 locks, rate limits, pause and kill switch.

## Boundary

AP-766 may:

- be invoked repeatedly by an external scheduler/heartbeat/operator;
- enforce per-area kill switch, daily budget, runner lock and pause;
- delegate exactly one AP-746 tick per execute invocation when admitted;
- read the AP-744 active operation surfaced by the tick;
- persist append-only JSONL run receipts in Atlas Server storage;
- expose a status read-model and the component-bridge probe;
- prove that no Codex app automation is used.

AP-766 must not:

- install or own a scheduler;
- run unattended when disabled by config/Product Mode;
- reimplement the AP-745 tick, lock or rate limit;
- create branches/worktrees, call providers, dispatch Dev/Forge work, or
  consume AP-747/AP-756/AP-749/AP-758/AP-759/AP-750 directly;
- merge, deploy, push externally, access secrets or make destructive changes;
- emit Morning Inbox items (that stays an AP-740 operator-reviewed step).

## Acceptance

- Disabled by default: a dry-run with no flags returns `paused` with
  `continuous_runner_disabled_by_default` and `provider_invoked=false`.
- Execute, when admitted, delegates one AP-746 tick, returns `ran` and surfaces
  `evidence_refs`; `claim_policy.ap746_tick_called_when_admitted=true` while
  provider/dev/forge/branch/merge/deploy/secrets remain false.
- A held area lock blocks a duplicate invocation (`locked`, tick not attempted).
- Global and per-area kill switch, pause-until and an exhausted daily budget
  each block before any tick is attempted.
- The daily budget counts only admitted execute runs for the current UTC day.
- Run receipts are append-only and idempotent by content hash.
- The status read-model reports gates, budget usage and the last run.
- Focused tests prove the no-execution boundary and the deferred-bridge claims.
