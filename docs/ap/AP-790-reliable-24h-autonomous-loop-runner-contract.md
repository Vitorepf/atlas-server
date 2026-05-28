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
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-794-finding-slice-planner-contract.md
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

AP-790 must also honor AP-793. That means the runner can drive a long horizon,
but it cannot certify progress unless the wrapped AP-786 cycle carries the
isolated-agent substrate facts: materialized sandbox, provider port authority,
session/result record, owner-flow result, validation, evidence, inbox and true
main advancement when merged.

AP-790 must also honor AP-794. The runner may supervise `factory_max`, but it
must not let broad findings become repeated provider timeouts or fake starvation
recovery. When AP-786 reports a large finding without an executable AP-794 slice,
AP-790 records the blocker and advances according to the retry/quarantine policy;
it must not count the blocked broad finding as progress.

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
6. forwards AP-788/AP-789 Forge authority fields unchanged when supplied, including
   real Obra id, live topology, live decision receipt, dispatch mode, role,
   provider authorization and budget approval;
7. passes every previously attempted **executed** finding key from the AP-790 ledger into
   AP-786 as `session_review_locked`, so AP-786 selects the next eligible item
   instead of re-opening the same blocked branch; dry-run/planning receipts never
   consume a candidate because they created no branch, provider run, diff, inbox
   proof or merge attempt;
8. classifies the cycle as merged / blocked / progress and updates counters;
9. records AP-794 slice/blocker evidence for `factory_max` broad findings;
10. **stops on a repeated finding only if AP-786 still returns a locked finding**
   (fail-closed protection, not the normal advancement path);
11. on a blocked cycle, records an inbox/receipt and continues only when
   `--continue-on-blocked` is set; otherwise stops cleanly;
12. on a merge, records the merge, lets AP-786 pull `main` when `--pull-main`, and
   continues;
13. appends a cycle receipt to the run ledger (JSONL, append-only);
14. rate-limits between cycles with `--sleep-seconds`;
15. releases the lock on exit (only the lock it acquired);
16. optionally runs safe worktree cleanup that only removes clean, merged sandboxes
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

Forge-owned findings may be executed only through real Forge authority. AP-790
accepts and forwards the AP-788/AP-789 flags:

```bash
--forge-obra=<real Obra UUID>
--forge-live-topology-json='{"status":"live",...}'
--forge-live-decision-json='{"decision":"dispatch","operator_actor":"operator",...}'
--forge-dispatch-mode=forge_runtime_dispatch
--forge-role=primary_builder
--bootstrap-forge-authority --forge-operator-actor=operator
```

`--bootstrap-forge-authority` derives only real authority. If live topology,
Atlas Decide receipt or AWIS handoff readiness is missing, the loop reports the
blocker honestly and keeps the finding review-locked for later iterations.

`--dry-run` forces `execute=false` (AP-786 plans a cycle, no provider/branch/commit/merge).
Recorded dry-run receipts are replay/audit facts only; crash recovery must not
turn them into `session_review_locked` keys for a later execute run.
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
- Previously attempted findings from the AP-790 ledger are passed into AP-786 as
  `session_review_locked` using all known aliases (`finding_id`, hash and title),
  so the normal path advances to the next eligible item.
- Dry-run findings are not treated as previously attempted execution; an execute
  run after a dry-run may still select and process the same candidate.
- Broad `factory_max` findings without AP-794 executable slice evidence are
  blocked or review-locked, not counted as progress and not retried unchanged.
- The same finding is never processed twice; `stopped_repeated_finding` is a
  fail-closed signal if AP-786 ever returns a locked finding anyway.
- After a crash, the runner resumes cumulative state from the ledger.
- AP-788/AP-789 Forge authority flags are forwarded to AP-786; AP-790 never
  fabricates an Obra, topology, decision, budget approval or provider authorization.
- `max_cycles`, `max_runtime_minutes`, `max_merges`, `max_blocked_in_row` each stop
  the loop with an explicit `stop_reason`.
- The runner never invokes a provider, merges or mutates the repo itself; all real
  work and authority stay inside AP-786. Test doubles are unit-test-only and never
  cross runtime, docs or `claim_policy`.
