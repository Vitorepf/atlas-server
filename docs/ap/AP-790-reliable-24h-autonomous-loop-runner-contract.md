---
id: AP-790-reliable-24h-autonomous-loop-runner
type: ap_contract
title: AP-790 Reliable 24h Autonomous Loop Runner Contract
status: active
owner: software_company_stewardship
summary: Durable supervisor that keeps the AP-786 autonomous evolution session running for a long horizon (e.g. 24h) on a single area/focus. It wraps AP-786 one cycle at a time and owns everything around the cycle - exclusive lock, append-only run ledger, cycle/runtime/merge/blocked budgets, rate limit, pause + kill-switch files, crash recovery from the last receipt, duplicate-finding protection, safe worktree cleanup hooks and post-merge pull-main behaviour. It never reimplements selection/execution/merge, never invokes a provider, never merges and never mutates the repo itself.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Console/Commands/AtlasSoftwareCompanyReliable24hLoopCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-790 Reliable 24h Autonomous Loop Runner Contract

## Authority

AP-790 is the durable supervisor that lets the operator leave Atlas running for a
long horizon (e.g. 24h) on a single `area_id`/`focus` (default
`agentic_engineering_os` / `dev_forge`, `scope-profile=factory_max`). It is not a
new OS, scheduler or runtime and it does not replace the AP-745/AP-746 Continuous
Stewardship scheduler or the AP-766 runner. It is a thin, resilient driver loop
around AP-786.

AP-790 **wraps** `AutonomousEvolutionSessionService` (AP-786), invoking it **one
cycle per iteration**, and owns everything around the cycle. It **does not**
reimplement finding selection, provider execution or merge — those remain in
AP-786 and the AP-747 -> AP-756 -> AP-757 -> AP-749 -> AP-758 -> AP-759 -> AP-750
owner-flow chain.

## Required Behavior

For each iteration the runner:

1. checks the kill-switch file and pause file (before touching the lock);
2. holds an exclusive per-area/focus lock with a TTL lease (stale locks expire so
   a crashed run can be resumed);
3. resumes cumulative state (cycle index, merges, blocked-in-row, seen findings)
   from the append-only ledger after a crash;
4. enforces budgets before spending a cycle: `max_cycles`, `max_runtime_minutes`,
   `max_merges`, `max_blocked_in_row`;
5. invokes the real AP-786 session for exactly one cycle;
6. classifies the cycle as merged / blocked / progress and updates counters;
7. **stops on a repeated finding** (never grinds the same finding in a loop);
8. on a blocked cycle, records an inbox/receipt and continues only when
   `--continue-on-blocked` is set; otherwise stops cleanly;
9. on a merge, records the merge, lets AP-786 pull `main` when `--pull-main`, and
   continues;
10. appends a cycle receipt to the run ledger (JSONL, append-only);
11. rate-limits between cycles with `--sleep-seconds`;
12. releases the lock on exit (only the lock it acquired);
13. optionally runs safe worktree cleanup that only removes clean, merged sandboxes
    (delegated to the AP-756 materializer, which refuses dirty/uncontrolled paths).

## Real-Authority Rule (no test doubles at runtime)

AP-790 follows the AP-78x family rule:

- At runtime the loop drives the **real** injected AP-786 session only. The
  `*ForTesting` seams (session runner, clock, sleeper) are **test doubles confined
  to unit tests**, named for testing, and must never be wired at runtime, never
  appear in canonical docs as authority and never influence `claim_policy`.
- When real authority is absent (no real Obra, no live Atlas Decide Decision
  Receipt, no real provider topology, no AWIS workspace readiness, no real handoff
  pack/evidence refs), the wrapped AP-786 cycle is `blocked`/`partial`, and the
  runner records exactly that. **`partial`/`blocked` is the correct result.**
- A synthetic or "valid shape" input can never make the loop report genuine
  progress, a merge or success. Genuine progress can only come from a real AP-786
  cycle that itself proved the owner-flow chain.

## CLI

```bash
php artisan atlas:software-company-stewardship:reliable-24h-loop \
  --area=agentic_engineering_os --focus=dev_forge --scope-profile=factory_max \
  --provider=cursor_cli --model=composer-2.5-fast \
  --max-runtime-minutes=1440 --max-cycles=50 --max-merges=20 --sleep-seconds=30 \
  --execute --auto-merge --allow-code-auto-merge --continue-on-blocked --pull-main --record --json
```

`--dry-run` forces `execute=false` (AP-786 plans a cycle, no provider/branch/commit/merge).
Pause/kill are files under the loop storage dir: `<key>.pause`, `<key>.kill`.

## Output

Schema: `atlas.software_company_stewardship.ap790_reliable_24h_loop.v1`

`status` is one of `completed`, `dry_run_planned`, `lock_held`,
`stopped_kill_switch`, `stopped_pause`, `stopped_budget`, `stopped_on_blocked`,
`stopped_repeated_finding`. The report carries `stop_reason`,
`resumed_from_cycle_index`, `cycles_this_run`, `cycles_total`, `merges_total`,
`blocked_in_row`, `budgets`, per-cycle summaries, `ledger_path` and `claim_policy`.
Each cycle is appended to the ledger as
`atlas.software_company_stewardship.ap790_reliable_24h_loop_cycle.v1`.

## Acceptance

- A held, non-expired lock blocks a second instance (`lock_held`); the second
  instance never deletes the first's lock.
- A kill-switch file stops the loop cleanly with zero cycles and no lock disturbed.
- A pause file stops the loop cleanly.
- A blocked cycle does not break the loop when `--continue-on-blocked`; without it
  the loop stops with `stopped_on_blocked`.
- The same finding is never processed twice (`stopped_repeated_finding`).
- After a crash, the runner resumes cumulative state from the ledger.
- `max_cycles`, `max_runtime_minutes`, `max_merges`, `max_blocked_in_row` each stop
  the loop with an explicit `stop_reason`.
- The runner never invokes a provider, merges or mutates the repo itself; all real
  work and authority stay inside AP-786. Test doubles are unit-test-only and never
  cross runtime, docs or `claim_policy`.
