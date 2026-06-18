> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.

# ACDE LOOP — MASTER HANDOFF (for the Claude that runs the loop)

> You are taking over an in-flight, high-stakes engineering mission on the operator's **personal, local-first**
> Atlas system. This file is your full context. Read it top to bottom **before touching anything**. Last updated
> 2026-06-16 by the building session (`00a58378`). Repo: `/Users/vitorepf/develop/Atlas/atlas-server`.

---

## 0. TL;DR — your mission in one paragraph

Atlas runs an autonomous **Evolution Loop** that improves software by itself. The loop runs on a **weak engine
(MiniMax-M3 via the Hermes agentic CLI)**, and the whole point is to make it **match-or-beat Opus-4.8 "ultracode"
on delivery quality by ARCHITECTURE and a perfect deterministic flow — NOT by the model.** A deterministic
anti-gaming **moat** (the cert pipeline) is built + armed and the loop is **already running** (campaign
`019ece72`, supervisor PID will differ after any recycle). Your job: **keep the heavy loop running for hours,
monitor/heal it (~every 10 min), read its real output, and — if the operator wants — continue the 5-leap
large-obra roadmap** (Wave A is mid-flight). Be **brutally honest** (the operator's #1 rule: "não me engana") —
declare nothing "done" without green tests + a commit + real evidence.

---

## 1. WHO ATLAS IS (thesis — do not dilute, do not violate)

- **Atlas is the BRAIN; providers (MiniMax/Hermes now, a strong engine later) are the MUSCLE.** Atlas governs
  intent, context, verification, memory; providers are rented compute.
- **Beat ultracode by FLOW, not model.** Quality comes from the deterministic, ungameable cert pipeline re-proven
  against a **HUMAN-frozen bar** — never from trusting the model. Prove the moat on the *weakest* engine so
  swapping to a strong engine is a **clean multiplier** (antifragility N×M).
- **Local-first sovereignty.** Everything runs on the operator's MacBook. No cloud/multiuser dependency. `.env`
  is **secret-class** — never print/exfiltrate its values; you may toggle non-secret `ATLAS_LOOP_*` selectors only.
- **BANNED VOCABULARY in any code/doc/comment you write:** `Jarvis`, `Rivals`, `benchmark`, `superiority`, and the
  literal word `concurrent` (use "parallel"). Do not call Atlas a "wrapper" or say it "competes with" Claude
  Code/Codex — Atlas *substitutes those products* and *uses them as engine*.
- **Atlas memory is canonical.** `CLAUDE.md`/`AGENTS.md` are generated projections, not the source of truth.
  Before implementation, consult the Atlas Open Brain (see §9).

## 2. OPERATOR RULES (hard constraints — these override convenience)

- **Brutal honesty.** "não me engana." "Done" = green tests + commit + (for runtime claims) real evidence. Never
  inflate. If tests fail, say so with output. If something is a stub, say so.
- **Do NOT burn the Claude token limit on heavy Workflow fan-outs** *unless the operator explicitly says
  "ultracode"* in the message. The loop runs on **Hermes/MiniMax (free)**; build PHP-native and **validate via the
  loop + the PHPUnit suite, not via Claude subagents.** (The exhausted paid limit is *Codex's*, not Claude's — but
  the rule stands: don't fan out without the "ultracode" opt-in.)
- **Hermes is always strongest mode** when used: `reasoning_effort: high`, model from `.env`
  (`ATLAS_AI_HERMES_MODEL`), never a weak profile.
- **Loops run for HOURS, unattended.** Check ~every 10 min. **Never stop a loop early.** "No winner" / few certs is
  **EXPECTED** — the strict moat *should* reject most weak-engine attempts; that is the moat working, not a bug.
- **Ladder principle (Blackink):** perfect the base before scaling; don't skip rungs; prefer PHP-native over shell
  hacks.
- **`.env` is sovereign/secret-class.** Only flip non-secret loop/provider selectors; never edit/print secrets/keys.

## 3. WHERE THE BUILD IS RIGHT NOW (the moat — SHIPPED + ARMED)

All on `main`, head commit **`989d5bdf`** (plus a checkpoint `898ac72c`). **333 frozen tests green**; every feature
is **flag-gated default-OFF + byte-identical when OFF**.

**Shipped + committed (the deterministic moat):**
- Tier-0: best-of-N decorrelation (#1), iterate-against-the-judge-bar (#2), universal behavioral-equivalence floor
  + deterministic overfit-constant-return probe (#3+#4).
- Tier-1: autonomous escalation **conductor** wired into the grinder (#5); dependency-**BODY** grounding (#7).
- Tier-2/3: bounded **parallel scenario-wave** dispatcher (#6); **planning** phase machinery
  IntentSpec→DecompositionPlanner→ReadinessGate w/ create-class-at-seq-0 (#8); machine-verified **completeness**
  resolver (#9); feature-lane **≥9 quality grade** + **confidence-calibration** flywheel (#10).

**ARMED in `.env` (verified live via `config`):** `iterate_against_judge` (#2);
`mutation_adequacy_gate` enabled + `max_mutants=3` + `mutation_kill_ratio_floor=0.5` (#3); `conductor_escalation_enabled`
(#5); `inject_dependency_bodies` (#7); `completeness_gate_enabled` (#9); `delivery_bar.armed` +
`confidence_calibration.enabled` (#10).
**Deliberately OFF:** the confidence **GATE** (`confidence_gate_enabled` — stays OFF until calibrated, n≥20);
`scenario_fanout.enabled` (#6); `planning_enabled` (#8 — its provider seam was a fail-open stub; Leap 1 fixes that).

**HONEST VERDICT (do not overclaim):** the moat makes the weak engine **match-or-beat ultracode on SINGLE-TARGET,
well-specified, verified-against-a-frozen-bar delivery.** It **narrows but does NOT close** large multi-file obras
that need **NOVEL decomposition** — the model still *originates* the plan; the flow only *verifies structure*.

## 4. THE 5-LEAP LARGE-OBRA ROADMAP (the current goal)

Operator goal (`/goal`, 2026-06-16): *"deliver ALL 5 leaps, complete + working + ready, then run the heavy loop for
hours; use /workflows with ultracode."* Full roadmap: memory `acde-large-obra-leaps.md`; design-panel raw output:
`/private/tmp/claude-501/-Users-vitorepf-develop-Atlas-atlas-server/00a58378-8833-49e0-943e-7e7bfc2558cc/tasks/wzowc007w.output`
(read `leaps[*]` for full architecture per leap). Sequenced by **real dependencies**:

1. **Leap 1 — Truth-in-wiring (IN FLIGHT).** (a) Make `generateSpecViaProvider`/`generatePlanViaProvider` in
   `AtlasLoopObraExecutionAdapter` do REAL hermes spec/DAG calls (today `return []` stubs → always fall back to
   buildPlan), mirroring `app/Services/Ai/Obra/ProviderObraNodeDelivery.php`; fail-open; create-class node at
   `seq=0`. (b) Expose public `AtlasLoopSemanticImplementationCertifier::measureScopedStructuralDrop(...)` (the
   anti-relocation primitive `AtlasLoopSignalAnalyzer::structuralComplexityReduced` + `measureComplexityReduction(...,
   structural:true)` **already exist** ~lines 522/806) and route `certifyAggregateDrop` to it when `$newFiles!==[]`
   behind new flag `atlas.loop.complexity_method_identity_gate` (default OFF). **No dep.**
2. **Leap 2 — Human-frozen decomposition boundary-oracle.** `frozen/obra-decompositions/<goal-hash>.json` lists
   REQUIRED node boundaries; `AtlasLoopObraPlanValidator` superset-checks the DAG (else
   `decomposition_missing_required_boundary:<seam>` → REPLAN); wire the **dead `decompose` tier** of the conductor
   (`AtlasLoopTaskGrinder`, where all 4 tiers map to the same width closure) to actually call `maybePlan`→executor.
   *The only leap that makes decomposition CORRECTNESS much-better.* Kills the seductive-wrong LLM-plan-judge.
   **Dep: Leap 1.**
3. **Leap 3 — Obra net-diff full certification.** `certifyAggregateDrop` already materializes the assembled net diff
   into a base_head worktree; add `certifyObraNetDiff()` that runs the FULL `certify()` there (behavioral-equiv,
   overfit, diff-earned, mutation, completeness, cross-node consumer contracts from the Code-Intelligence graph)
   against the obra's HUMAN-frozen acceptance. Closes "independently-green steps that conflict once assembled."
   **Dep: Leap 1; composes with 2.**
4. **Leap 4 — Obra throughput + recovery (XL).** `AtlasObraWaveScheduler` antichain (Kahn) fan-out reusing the
   shipped `ScenarioWaveDispatcher`; streaming fail-fast prefix checkpoints; structural escalation via the conductor
   on a NAMED refusal (repair_from_refutation→decompose). Determinism anchored on the `depends_on` DAG — parallelism
   changes WHEN, never WHICH diff certifies. **Dep: Leaps 1-3.**
5. **Leap 5 — Decomposition Outcome Ledger.** Durable `atlas_loop_decomposition_outcomes` table (mirror
   `atlas_loop_confidence_samples`) + structural plan **fingerprint** + Wilson lower-bound **shape-prior** as a
   NON-fatal advisory band in `AtlasLoopPlanReadinessGate` ("shape_historically_thrashes"→REPLAN). Anchored on real
   terminal frozen-bar outcomes + post-merge canary, never self-report. Completes the two-evolutions thesis.
   **Dep: Leaps 2-3.**

**Core = Leaps 1-3 (import the moat into the obra layer). 4 = speed. 5 = compounding.**

**RESIDUAL CEILING (survives all 5, by design — say this honestly):** the model still ORIGINATES the decomposition.
(1) design-judgement (wrong internal abstraction below named seams — no deterministic out-of-model anchor);
(2) greenfield with no frozen oracle/prior → degrades to structural-only (plan trusted); (3) spec/index completeness
(un-tested seam, missing graph edge, dynamic dispatch). **Parity is on verified-against-a-frozen-bar large-refactor
+ create-class + recoverable + compounding-on-trodden-classes — NOT greenfield novel-decomposition origination.**

## 5. CURRENT RESUME POINT (exactly where the building session stopped)

- **Loop:** QUIESCED for the build window (campaign `019ece72` kill-switched; 0 procs). Relaunch it at the very end
  (§6) once all leaps land. New-leap flags default OFF, so the relaunched loop is byte-identical until armed.
- **Wave A (Leap 1): DONE + committed `2fc0e4f4`.** Real planner provider seam (generateSpec/PlanViaProvider now
  invoke hermes via `obraPlanningProviderRaw`, parse spec + create-class-at-seq-0 DAG, fail-open) + public
  `measureScopedStructuralDrop` on the certifier + structural routing in `certifyAggregateDrop`
  (flag `complexity_method_identity_gate`). 280 regression tests green, byte-identical-OFF. 3 new tests:
  `AtlasLoopStructuralDropCertifierTest`, `AtlasLoopObraPlannerProviderSeamTest`, `AtlasLoopObraAggregateDropRoutingTest`.
- **Wave B (Leaps 2+3): NEXT.** **Wave C (Leaps 4+5): after.** Then arm + relaunch.
- **⚠️ CRITICAL LEARNING — do NOT use `isolation:'worktree'` Workflow agents for waves on top of committed work.**
  Workflow worktrees branch from a PINNED session base (~the session's first commit), NOT current HEAD — so their
  patches are stale and won't apply over your intervening commits (this is exactly how Wave A's `leap1.patch` died,
  superseded by #8). The reliable pattern that WORKED: a **non-worktree** subagent (shares the real cwd → sees
  current HEAD AND has `vendor/`, so it can actually run phpunit) implements + tests-to-green on current `main`,
  run **sequentially** (one at a time → no race), and you review the diff + re-run the suite + commit each. Use
  read-only blueprint agents or sequential writing agents — never stale worktree patches.
- **Tasks #19-23** (`TaskList`) track Wave A→B→C→arm→relaunch with blockedBy deps (#19 done).
- **Prior-wave specs** (apply-ready, verified): `…/tasks/wjt0nof61.output` (items #5-#10). **Leap specs:**
  `…/tasks/wzowc007w.output`. **Applied patches:** `storage/acde-patches/item{6,8,9,10}.patch`.

## 6. RUNBOOK — running / monitoring / healing the heavy loop

**Provider (source of truth = `.env`):** `ATLAS_LOOP_DEFAULT_PROVIDER=hermes_cli`,
`ATLAS_LOOP_SCENARIO_PROVIDER_PORTFOLIO=hermes_cli`, `ATLAS_AI_HERMES_MODEL=MiniMax-M3`, the
`ATLAS_AI_HERMES_*_POLICY=atlas_adapter` self-improvement policies. The loop invokes Hermes with `--model MiniMax-M3
--reasoning_effort high --yolo --max-turns 90`. PHP: `/opt/homebrew/bin/php` (8.4+/8.5).

**Is it alive?**
```
ps aux | grep -E 'atlas:loop:(campaign|grind-task)' | grep -v grep
tail -f storage/logs/acde-campaign-armed-*.log
php artisan atlas:loop:campaign:status     # (status command exists)
```
Healthy = 1 `campaign` supervisor + N `grind-task` workers; log advancing; workers cycling tasks. A grind-task holds
a 5400s lease. Hermes executors appear as `hermes chat --quiet …`.

**Launch a fresh detached campaign** (auto-generates a campaign-id; survives your session via launchd reparent):
```
nohup /opt/homebrew/bin/php -d memory_limit=4096M artisan atlas:loop:campaign \
  --workers=4 --scenarios=3 --sleep-seconds=5 \
  > storage/logs/acde-campaign-$(date +%Y%m%d-%H%M).log 2>&1 &
disown
```

**Quiesce (graceful, then hard) — do this before ANY structural surgery on the loop's own code:**
```
php artisan atlas:loop:campaign:stop --campaign-id=<id>   # sets the file kill-switch (storage/atlas-loop/campaign/<id>/KILL)
pkill -TERM -f 'artisan atlas:loop:grind-task'
pkill -TERM -f 'artisan atlas:loop:campaign'
pkill -TERM -f 'hermes chat --quiet'                       # orphaned MiniMax execs reparent to launchd — kill them too
```
Scenarios are throwaway (`/tmp` + `storage/atlas-loop/.../workers`), so killing mid-attempt only loses that attempt.

**Self-heal:** `atlas:loop:keepalive` (scheduled in `routes/console.php`, gated by `atlas.loop.keepalive_enabled`)
respawns a campaign whose heartbeat is stale but process-dead — IF `schedule:run` is driven by cron each minute.
`ATLAS_LOOP_RESTART_ON_CODE_DRIFT` controls drift-restart (memory says it was set OFF at one point — verify before
relying on it). No keepalive watchdog runs as a parent today (campaigns are launchd-parented).

**Merge model:** the campaign banner says *"shadow, propose-only, never merges"* — the campaign phase does NOT touch
`main`. Auto-merge to main is a SEPARATE drain governed by `ATLAS_LOOP_AUTO_MERGE_TO_MAIN` + the
`AtlasLoopAutoMergeService` (with the honest-attribution reconcile fix from checkpoint `898ac72c`). So a running
campaign is safe w.r.t. your git work; but if auto-merge is armed, expect occasional real commits to `main` from the
loop — **quiesce before you do your own structural commits to avoid a race.**

**Failure modes to watch (from memory):** zombie tasks (alive+fresh heartbeat but 0 grinds = attempts==max →
reclaim must revert the claim-increment); starvation (claimable==0 && running==0); code-drift mid-campaign; DB
(Postgres) blips (supervisor retries/parks). If `main` goes RED under a dead campaign, route fix-forward to the live
supervisor, don't hand-patch blindly.

## 7. MEASURE (Bloco C — the real proof, accrues over hours)

This is **runtime evidence, not code you declare done.** With the moat armed:
1. Read the first real certs on `hermes_cli`: `quality_grade`, `delivery_confidence`, `completeness` in the cert
   receipts / `AtlasLoopProposal` rows.
2. **Head-to-head:** run the SAME frozen task through loop-MiniMax vs Opus-4.8-ultracode with an independent judge;
   compare delivery quality (this is the core thesis proof).
3. **Calibration:** let `{predicted,correct}` samples accrue post-merge, then `php artisan atlas:loop:confidence-calibrate
   --json`. Only when it returns a non-null `recommended_threshold` with n≥20 may the operator arm the confidence GATE.
4. **Ratchet** the bars (`mutation_kill_ratio_floor`, `quality_bar`) up as the loop clears them.
5. Measure the **absurd leap**: swap MiniMax → a strong engine and confirm the flow multiplies (zero flow change).

## 8. INTEGRATION PLAYBOOK (the proven pattern for each leap/wave)

The building session used: **ultracode workflow (author in `isolation:'worktree'` → adversarial verify, both emit to
disk) → I integrate on `main`.** Per wave:
1. **Quiesce the loop** (§6) if you're about to commit loop-core changes (avoid main races).
2. `git apply storage/acde-patches/<leap>.patch` (use `--3way` if a later patch shares a file; the building session
   applied #6/#8/#9/#10 cleanly this way). Worktree agents **cannot run phpunit** (no vendor; symlink breaks composer
   autoload → tests would load canonical sources). So agents only `php -l` + self-review; **you run the real suite.**
3. **You own the shared plumbing** (agents don't touch it): `config/atlas.php` flag blocks,
   `app/Providers/AppServiceProvider.php` binds (remember: **Laravel does NOT autowire `?Type $x = null`** — a new
   nullable dep needs an explicit bind or a `?? new` fallback), `routes/console.php` schedules, migrations.
4. Run the frozen suite (§ gotchas): `./vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution tests/Feature/Loop
   tests/Feature/CodeGraph` — must stay green (byte-identical-OFF). Fix integration nits; if a NEW test's *premise*
   is wrong (e.g. it assumed a score the code doesn't produce), fix the TEST honestly, not the gate.
5. `./vendor/bin/pint <changed files>`; commit with a thorough message documenting honest limits.
6. After all leaps green: **arm** the new flags judiciously (some are inert without frozen artifacts, e.g. the
   boundary-oracle without a fixture degrades to structural-only — safe to arm), prove no-false-reject, then relaunch.

## 9. FILE & ARTIFACT MAP

- **Loop core:** `app/Services/Ai/AutonomousEvolution/` — `AtlasLoopSemanticImplementationCertifier.php` (the cert
  gates), `AtlasLoopObraExecutionAdapter.php` (obra execution + planning), `AtlasLoopTaskGrinder.php` (grind + conductor
  escalation), `AtlasEvolutionScenarioExplorer.php` (best-of-N + wave fan-out), `AtlasLoopAutoMergeService.php`,
  `AtlasLoopAutonomousConductor.php` + `AtlasLoopEscalationLadder.php`, `Parallel/` (wave dispatcher + worker pool),
  `Discovery/` (planner, intent-spec, readiness-gate, completeness resolver, target discovery), `Verify/`
  (`AtlasLoopSignalAnalyzer` — complexity + structural anti-relocation, `AtlasEngineeringHonestyGate`).
- **Config:** `config/atlas.php` (the `loop` block ~lines 1764-2520; flags by name, never secret). `.env`
  (secret-class — selectors only).
- **Schedules:** `routes/console.php` (keepalive, calibrate, etc.).
- **Docs:** `docs/acde-beat-ultracode-worklist.md` (10-item moat + honest verdict); THIS file.
- **Memory (canonical, persists across sessions):**
  `/Users/vitorepf/.claude/projects/-Users-vitorepf-develop-Atlas-atlas-server/memory/` — read `MEMORY.md` (index)
  first; key entries: `acde-beat-ultracode-tier0`, `acde-large-obra-leaps`, `loop-runs-on-hermes-minimax`,
  `acde-compounding-delivery-engine`, `operator-rule-no-claude-token-burn`, `hermes-always-strongest-mode`,
  `loop-run-long-with-10min-checks`, `atlas-server-test-suite-infra`, `loop-two-trust-ladders`.
- **Specs/patches/logs:** `…/00a58378-…/tasks/{wjt0nof61,wzowc007w}.output`; `storage/acde-patches/*.patch`;
  `storage/logs/acde-campaign-*.log`; workflow scripts under `…/workflows/scripts/`.

## 10. GOTCHAS (learned the hard way — don't relearn them)

- **Tests:** use `./vendor/bin/phpunit`, **NOT** `php artisan test` (autoloader-redeclare → exit 255). Suite needs
  `-d memory_limit=4096M` for the heavy parts. There is a known crasher (`AutonomousHoldingEnterpriseCommandTest`) and
  some **baseline-RED** tests unrelated to your change (`AtlasLoopTrustLadderRealHistoryFeedTest`,
  `AtlasLoopWiredTargetingTest`, `AtlasLoopMultiFileExecutionWiringTest` → null vs no_winner). Run **targeted dirs**,
  not the full 24k suite; distinguish baseline-red from your regressions.
- **DB:** dev DB that diverges → **nuke + recreate (fresh), not repair migrations.** Migrations must be idempotent
  (`Schema::hasTable` guard) and **NEVER hand-stamped** into the `migrations` table. Test env runs migrations in
  `setUp`/RefreshDatabase.
- **DI:** Laravel does **not** auto-inject `?Type $x = null` ctor params (the codebase documents this at
  `AppServiceProvider:176-180`) — new nullable deps need an explicit bind or a `?? new` fallback.
- **`AtlasEvolutionLoopRunner` is `final`** (can't subclass; fake the `LoopExecutionDriver` interface via the
  container). Grinder built via `app()`/`newInstanceWithoutConstructor`, never positional.
- **Worktree agents have no `vendor/`** and symlinking it breaks composer autoload (tests load canonical sources).
  So agents `php -l` + self-review only; YOU run the real suite on `main`.
- **MCP / Atlas Open Brain:** before non-trivial implementation, consult the brain:
  `php artisan atlas:context-pack "<task>" --workspace="$CLAUDE_PROJECT_DIR" --json` (or MCP `atlas_context_pack`;
  CLI fallback `bin/atlas open-brain context "<task>" --json`). Don't trust blind grep over the canonical docs.

## 11. THE FIRST 15 MINUTES (your checklist)

1. Read `MEMORY.md` + this file fully. Run `TaskList` (#19-23 = the live plan).
2. `git log --oneline -3` (expect `989d5bdf` or later) + `git status` (expect clean or only known untracked junk:
   `tests/atlas_generated_0.php`, `*ServiceSupport.php`, `.env.bak-*`, `dissecar/` — leave them).
3. Confirm the loop is alive (§6). If dead and the operator wants it running, launch a fresh campaign.
4. Check `storage/acde-patches/leap1.patch` — if present, integrate Leap 1 (§8); else re-run the Leap 1 workflow.
5. Confirm armed flags via `php artisan tinker` dumping `config('atlas.loop.*')` (see §3). Confidence GATE must be OFF.
6. Then continue the roadmap (Wave B → C → arm → measure) per the operator's direction, or just babysit + measure if
   that's what they want. **Ask only if genuinely blocked; otherwise execute and report honestly.**

---

**One-line creed:** make the weak engine deliver like a strong one *by the flow*, prove it against a human-frozen
bar, never lie about the ceiling, and keep the loop alive.
