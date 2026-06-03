# Atlas Evolution Loop — the 24h Autonomous Runtime (implemented)

Status: **built + proven at the mechanism level** (27 tests, 133 assertions) + a real
provider campaign on real Atlas code. This is the record of what the loop became and the
governed decision behind it.

## What the Loop is (operator's framing)

Atlas already has two programming products:

- **Dev** — interactive, high-performance engineering (the Claude-Code/Codex/Cursor
  surface as an *engine*, human-in-command).
- **Forge** — heavy, months-long obras, human-supervised.

The **Loop is a distinct third product**: **autonomous, runs for a wall-clock budget
(up to 24h) with no human, depends on the operator only every few days.** It does
research at scale (code/docs), explores many evolution scenarios, refines to extreme
polish, and accumulates **propose-only** certified-for-review changes. It *orchestrates*
Dev/Forge/providers as muscle — it never re-implements them, and it never merges.

## The decision that unblocked it (why 4 days of prior attempts failed)

A full 24h-loop stack already existed: `software_company_stewardship` AP-790
`Reliable24hLoopRunnerService` — 3971 lines of genuinely good safety engineering (lock
+ lease, budgets, pause/kill, crash recovery, append-only ledger, `--max-runtime-minutes=1440`).
**But its execution core is a self-documented FIXTURE** (its own code:
`forge_real_execution_implemented => false`, *"does NOT generate real code"*,
`AtlasForgeLiveExecutionService` writes `forge-live-execution-fixture.txt`), and its
terminal action is **ff-only merge-to-main**. So it could run 24h, "merge" fixtures, and
deliver nothing real — elaborate theater with a hollow core.

The proven `AtlasEvolution*` engine is the exact inverse: a **real** execution core (it
edits real Atlas code, frozen-judges it, propose-only) with **no 24h runtime**.

**Decision (governed, not drift):** give the ONE real engine a thin, clean,
**propose-only** durable 24h runtime. **Reuse AP-790's proven safety *patterns*** (lock
lease + orphan reclaim, kill/pause files, JSONL ledger, chunked responsive sleep) but
**do NOT route through its merge-capable session** and **do NOT inherit its fixture
core or its merge-to-main behavior**. This prevents a *third* execution stack while
refusing the two things the operator rejected (provider hardcode + auto-merge).
Consolidating the fixture AP-786/790 path with this real engine is a separate, future,
gated obra — explicitly not this layer.

## Architecture (one campaign = one wall-clock run)

```
AtlasLoopCampaignSupervisor.run():
  acquire file lock (lease + dead-pid reclaim)  ->  rebuildInFlight (reclaim crashed tasks)
  sweepOrphans (/tmp scenario copies)           ->  loop while not over budget / killed:
     ensurePendingDepth   (countPending < watermark -> refiller.refill: discover -> generate(RED) -> enqueue)
     claimNextTask        (atomic FOR UPDATE SKIP LOCKED, lease-reclaim folded in)
     grinder.grind        (materialize durable snapshot -> AtlasEvolutionLoopRunner -> persist)
     loopBack.reflect     (Results -> Sources: neighbor / sibling / quarantine)
     heartbeat + ledger + responsiveSleep (kill > pause precedence)
  finally: reclaim; release lock; mark stop_reason
```

### Durability (5 Postgres tables, `atlas_loop_*`)

- `atlas_loop_campaigns` — the 24h unit: goal, budgets (`max_seconds`/`max_tasks`/
  `max_proposals`/`max_usd_cents`), persisted `elapsed_seconds` (paused time never burns
  budget; a crash resumes against REMAINING budget), rolling totals, lock lease, heartbeat.
- `atlas_loop_tasks` — the durable, leaseable queue. `payload` is a restart-safe SNAPSHOT
  (target content + frozen test bodies + acceptance) so a task is reproducible across a
  crash. `self_contained` is a first-class claim predicate.
- `atlas_loop_proposals` — the certified-for-review ledger. **Never merged.**
- `atlas_loop_explorations` — the "19 that didn't work" audit.
- `atlas_loop_targets` — the discovery candidate ledger (the Ladder "Sources" at repo scale).

### The never-merge invariant (3 layers)

1. DB `CHECK (merged_to_main = false)` + a `BEFORE INSERT OR UPDATE` plpgsql trigger
   (`atlas_loop_block_merge`) — pgsql refuses any merged row (proven: P0001 raised).
2. `AtlasLoopProposal::saving()` forces `merged_to_main=false` + `certified_for_review`
   (proven on sqlite — writing `merged=true,status=merged` stored `false,certified`).
3. `AtlasLoopRunPersister::assertNeverMerged()` aborts a persist if any engine result
   ever shows `merged_to_main:true`. The supervisor has no merge code path at all.

### Provider-agnostic, end-to-end

No table/column/index/class/method/config key/worker id names a provider. All execution
descends through the single `LoopExecutionDriver` (bound to `SeniorLoopExecutionDriver`,
decorated by `TimeBoundedLoopExecutionDriver`). `provider=''` means "loop default / Atlas
Decide". Remove any provider — the loop still runs. Rebind one line to swap the engine.

### The adversarial guards (from the design critic)

- **`TimeBoundedLoopExecutionDriver`** — per-attempt hard wall-clock kill (pcntl alarm +
  process-group kill where signal delivery fires; always-on post-hoc `timed_out` backstop).
  One hung provider call can never wedge the 24h run. (proven: a 2s hang under a 1s
  deadline returns `timed_out` in ~1.1s.)
- **`AtlasLoopResourceGate`** — disk-floor admission (backpressure, never crash) + orphan
  reaper for the `cp -R` scenario workspaces a crash/kill leaves in `/tmp`.
- **metric_finite honoring** — the explorer treats a clamped ±1e308 metric as non-improving
  so it can never win a minimize/maximize task (inert for the GATE metric used today;
  closes the hole before numeric metrics ship). Additive + default-identical: the 17
  engine tests stayed green.

### Parallelism

The serial single-worker path is the **proven default**. The bounded worker pool (one
`atlas:loop:grind-task` process per task) ships **config-gated OFF** (`atlas.loop.parallel.enabled=false`);
enabling N workers is a measured flip (needs a real-Postgres no-double-claim run under
actual parallel spawn + per-worker `/tmp` namespacing + disk cap divided by workers).

## Surfaces

- `atlas:loop:campaign` — the 24h entry (`--max-seconds` clamped 1..86400, `--scenarios`,
  `--max-proposals/-tasks/-usd-cents`, `--provider`, shadow default, `--no-shadow`,
  `--campaign-id` to resume). Never merges.
- `atlas:loop:campaign:stop {--pause}` — graceful kill/pause switch (honored within seconds).
- `atlas:loop:campaign:status [--proposals]` — read-only heartbeat/lock/budget/totals/ledger/stack.
- `atlas:loop:grind-task` — the per-task worker (unit of parallelism; runnable standalone).
- `atlas:loop:evolve` — the existing in-memory single-batch path (unchanged).

## Proof (mechanism, not endurance — stated honestly)

- 27 tests / 133 assertions: 17 engine (unchanged), durable grind, supervisor (full
  self-feeding cycle -> 2 distinct certified proposals never merged; wall-clock budget
  stop mid-queue; crash/resume reclaims an in-flight task + persists exactly once), and
  the guards in isolation.
- A real provider campaign over real `app/Support` self-contained targets through the new
  supervisor (discover -> generate RED -> grind -> frozen-judge -> persist).

## Honest residual (NOT solved here — no false-green)

1. **Workspace materialization is not extended** to non-self-contained files (vendor/env/DB).
   Throughput is bounded to self-contained pure-PHP single-file targets; when that pool
   drains a campaign honestly stops with `queue_starved_no_refill`. Extending it is the
   biggest follow-on AND the easiest place to fake green — it MUST be a separate gated
   obra whose judge FAILS when the diff is reverted but ambient state is green.
2. **24h endurance is not soak-proven.** The mechanism cycles + crash-recovers at minute
   scale; real provider rate-limits, token-cost envelope, and memory growth across
   thousands of cycles are unmeasured until a real operator-authorized long run.
3. **The cost cap is inert** until the execution layer reports per-attempt cost
   (`spend_usd_cents` wire exists, degrades safe at 0).
4. **Parallelism ships disabled** (serial is the proven default).
5. **A hung-but-alive supervisor** (deadlock, not crash) is detectable only externally
   (heartbeat age + stalled `tasks_done` deltas) — the status command exposes those
   fields for a thin launchd/cron watchdog, which is not implemented here.
6. **diff_text is capped at 20k chars** upstream; a large winning patch persists truncated.
7. Discovery heuristics are line-based regex, not AST — a mis-score only mis-orders
   (the frozen judge stays authoritative; it cannot create a false proposal).
