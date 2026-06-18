# THE ATLAS LOOP AS A LIVING OS — Final Frontier Architecture (Build Spec)

**Status:** Projection phase closed (synthesizer pass, folding 3 adversarial critiques). This is the build spec.
**Scope of v1:** the Loop bootstrapped on its OWN code (`app/Services/Ai/AutonomousEvolution/`), the first rung of the territory ladder.
**Code root:** `app/Services/Ai/AutonomousEvolution/` — PHP 8.5, `./vendor/bin/phpunit`, `/opt/homebrew/bin/php`.

Every component names real classes, tagged **[REUSE]**, **[REUSE+EXTEND]**, **[REUSE+WIRE]**, or **[NEW]**. Every disputed fact below was re-verified against the live code in this pass; the **Verified Ground Truth** table (§J) is the corrected map that supersedes the draft's errors.

---

## 1. Purpose & the 4 locked decisions

The Loop must become the **sole, autonomous, exponential self-engineer of Atlas** — it understands a scope's true objective, projects the most leveraged evolution under a persistent cross-model critique loop, implements it multi-agent, certifies it deterministically, and merges it to real `main` 24/7 with no human and no external platform reviewing. v1 proves this on the loop's own code.

1. **Recursive-safety = a tiny IMMUTABLE CONSTITUTION, not a fence.** The loop may rewrite *everything* including its own judge/cert/selector/pipeline. The only immutable thing is a property checker: any new quality-machinery version must still catch a FROZEN battery of known-bad changes (incl. robustness breakages) AND still certify a frozen battery of known-good changes — else *that* change is rejected. It checks a PROPERTY (detection power), never an implementation. A strictly-better judge PASSES; only a blinder/weaker one is REJECTED.
2. **Design/projection engine = gpt-5.5 (Codex) via Hermes**, cross-model critique with GLM-5.2 / MiniMax-M3. **NEVER pin the model in Atlas** — use the `hermes_cli_default` sentinel so Hermes's native fallback chain stays intact.
3. **External research = read-only** (web/GitHub/papers). ZERO Atlas code or secrets ever leave the machine. Local-first sovereignty; fail-**closed** on any egress doubt.
4. **Autonomy = maximum.** The loop depends on no one but itself. No abstain-that-BLOCKS on the human: what it cannot crack it PARKS (full analysis recorded) and CONTINUES. Live, no shadow.

---

## 2. Component architecture (A–G)

### A. THE IMMUTABLE CONSTITUTION

**What it does:** lets the loop rewrite its own cert chain while making it *impossible* to go blinder or less robust. It is the keystone; full machine-checkable spec is §3.

**The decisive ground-truth correction (drives the whole design):** `AtlasLoopHarnessGuard::isForbiddenSelfTarget()` is `str_contains($path, $forbidden)` (`:81`) and the FORBIDDEN list contains the bare directory prefix `app/Services/Ai/AutonomousEvolution/` (`:70`). **Therefore the entire loop tree is forbidden by substring today** — the `meta_harness` flags only gate `HARNESS_PREFIXES`, but the forbidden check fires first and unconditionally. The loop **cannot edit any of its own code right now.** The Constitution is what *safely opens* that door, not what closes an already-open one.

**NEW vs REUSED:**
- **[NEW] `Constitution/AtlasLoopConstitutionGateService`** — the execution-based property gate (NOT hosted in `FormalInvariantGateService` — see §3.4 / critique C1).
- **[NEW] `Constitution/AtlasLoopFrozenBattery`** — append-only, hash-chained, materialized-on-disk corpus (NOT regenerated from `MutationOperators` — see §3.5 / SEV-3).
- **[NEW] `Constitution/AtlasLoopRobustnessProbe`** — the *behavioral* concurrency/structural prober for robustness cases (split out per M3 — a dropped `flock` is invisible to a single-process diff-and-test cert).
- **[REUSE] `AtlasLoopProposalOutOfProcessVerifier`** — confirmed real `createWorktree()` via `git worktree add --detach HEAD` + `Symfony Process` subprocess (`:309-312`). This is how a not-yet-merged certifier is executed (answers SEV-1's "whole ballgame" question — §3.4).
- **[REUSE+EXTEND] `AtlasLoopHarnessGuard`** — gains a THIRD verdict `property_gated` (NOT "remove from list" — critique C2).

**Data/state:** battery on disk at `Constitution/battery/` (N immutable per-case files + append-validated manifest + `battery_root_hash`). One new table `atlas_loop_constitution_receipts` (candidate-cert-hash, battery-version-hash, verdict, surviving-bad/rejected-good receipt).

**Exact rules:** §3.

---

### B. THE DECISION BRAIN — pick the most-exponential next evolution

**What it does:** replaces "grade of a merged diff" with "which unbuilt leap most relieves Atlas's binding constraint per unit cost, with 2nd-order awareness," and **brings the selector INSIDE the Constitution** (the largest hole the critiques found — SEV-3b).

**NEW vs REUSED:**
- **[REUSE+WIRE] `AtlasLoopExpectedValueDecider`** (`Discovery/`, confirmed orphan). Real signature: `decide(array $candidates, array $context)` where `context.axis_values: array<string,float>` and `context.class_stats` (confirmed `:54-58`). Wired into `AtlasLoopObjectiveProducer::produce()` **after** `scorer->rank` + ambition floor and **before** `origination()->build()` (live call sites at `Discovery/AtlasLoopObjectiveProducer.php:106-120`).
- **[NEW] `AtlasLoopSystemAxisService`** — re-tagged from the draft's "[REUSE+EXTEND] UtilityGradeService" because **`AtlasLoopUtilityGradeService` is FORBIDDEN at `:49`** (confirmed — critique H2). The loop may not edit it; extending it in place is a governance contradiction. Instead a NEW service *consumes* `UtilityGradeService`'s re-resolution discipline (re-resolve caller graph + re-measure cyclomatic from the immutable commit, never a stored field) and emits the live per-cycle **system-axis vector** feeding `axis_values`.
- **[REUSE] `AtlasLoopWorkClassPriorService::priorFor()`** — Wilson-lower-bound landing rate → `P_success` and `class_stats`.
- **[REUSE] `AtlasLoopLeverageScorer`** (+ ambition floor) → `value`.
- **[REUSE] `AtlasLoopNextWorkDecider`** banded cost → `cost`.
- **[REUSE+EXTEND] `AtlasLoopStateOfAtlasReader`** — keep the fail-open 3-source brain read (memory recall / domain maturity / reality graph, all wired); replace only `StateOfAtlas::strategicWeightFor()`'s `str_contains(path, keyword)` proxy with a frontier-model comprehension weight, **advisory + fail-open** to the old keyword weight when Hermes is down, cached per region per cycle.

**Data/state:** the system-axis vector (per cycle, in `atlas_loop_pipeline_state`), the EV receipt (`is_optimal=false, estimate_calibrates=true`).

**The objective function (the EV pick):**
```
pick = argmax over floor-passers of:
   P_success(class) · value(candidate) · bottleneck_relief(candidate) − cost(candidate)
```
where `bottleneck_relief(candidate) = how much this candidate raises the current binding (lowest) system axis`. Because axes are re-resolved from git/graph truth every cycle, this is a *moving* objective: fixing the binding axis makes the EV math auto-pivot to the next-binding one — the canonical "made code-gen fast → review is now the bottleneck" *emergent*, not hand-coded.

**The robustness-axis anti-proxy fix (SEV-2/critique-2):** robustness is **NOT** a saturating boolean ("merge-lock present = true"). It is a *continuous, re-violatable* property: `robustness_axis = 1 − (incidents_per_window across all known failure classes)`, measured from the outcome ledger over a rolling window. A failure class can regress and must be *continuously defended*, so the loop can never "check the box and move on." Box-checking finite toggles is explicitly forbidden from earning ladder promotion (§G.2).

**Branching (IMPLEMENT / REFACTOR / RESEARCH / PARK):**
- **IMPLEMENT (feature origination)** — binding axis is a *missing capability*. **[REUSE] `AtlasLoopOriginationBuilder`** (`Discovery/`, RED-verified lane). Flip `producer_feature_origination_enabled` ON (default `false`, `config/atlas.php:2064`, absent from `.env`) — **only as a parked operator-decision step with a Decision Receipt** (it reverses a config default; per critique-3 M4 the loop cannot flip its own flags, and silent override is forbidden).
- **REFACTOR** — binding axis is structural debt throttling COMPOUNDING. Existing refactor lanes **[REUSE]**.
- **RESEARCH** — the critic flags an unknown frontier approach → enqueue a read-only research topic (§C).
- **PARK** — projection won't converge, or a change needs a battery-retire two-key, or research can't fill a gap. **[NEW] `AtlasLoopParkLedger`** with **re-attempt-with-escalation** (more rounds → stronger model → decompose into convergent pieces) and a **loop-health metric** that flags "high-EV items parking faster than delivered" as a regression (SEV-3 critique-2: park-everything is autonomy theater).

---

### C. SELF-RESEARCH INTAKE — read-only, zero-out

**What it does:** lets the loop autonomously discover "Arbor does SELECT better" instead of waiting for a human teardown, with a hard sovereignty boundary.

**NEW vs REUSED:**
- **[NEW] `AtlasLoopExternalResearchService`** — strictly read-only. Inputs: `WebSearch` / `WebFetch` (note: these are **deferred/optional tools** in this environment — the sovereignty guard must **fail-closed**: no research rather than any leak when the tool is absent — critique-3 M2).
- **Hard sovereignty boundary (Locked 3):** the service is *pull-only* — it accepts a `string $topic`, never a `$diff`/`$payload`/file content. A static guard test asserts every call site passes only `string topic`. This is enforced as a **structural battery case** (§3.5 structural engine).
- **Output sink — must be a LIVE seam, not the draft's dormant one.** Critique-3 M2 confirmed `InsightBackpropService` / `ResearchContract` are bound with `rescue(...,null,false)` and gated **default-OFF** behind `idea_tree_enabled` / `insight_backprop_enabled` / `failure_supply_enabled`. So either (a) arming those three flags is a named parked operator-decision step, or (b) v1 ships its own minimal live advisory table `atlas_loop_research_notes` read directly by the projection designer (§D.2) and the strategic read (§B). **v1 chooses (b)** to avoid a dormant dependency; the InsightBackprop path is a later integration once the operator arms it.
- Research **informs** what to try; it **never gates** a cert. The deterministic + battery cert decides what ships (moat preserved).
- **Cadence:** on-demand from the RESEARCH branch, bounded — never a constant crawl.

---

### D. THE 7-STAGE DELIVERY PIPELINE AS PERSISTENT RUNTIME

**What it does:** kills one-shot framing; makes the projection/critique loop ("the phase that guarantees quality") real, persistent, and **asynchronous** (the synchronous-refiller stall, critique-3 C3, is the fatal topology bug it fixes).

**NEW vs REUSED:**
- **[NEW] `AtlasLoopDeliveryPipeline`** — persistent per-objective state machine; one row per objective in new table `atlas_loop_pipeline_state` (stage, checkpoint blob, accrued-EV priority, model-spend-so-far). Resumable: crash/restart resumes mid-pipeline.
- **[NEW] `AtlasLoopProjectionEngine`** — the persistent designer↔critic convergence loop (replaces single-shot `AtlasLoopAdversarialCritic::challenge()`).
- **[REUSE] `AtlasLoopTaskGrinder`** / ADEP iterate-to-green (Stage 5 implement).
- **[REUSE] frozen cert + battery** (Stage 6).
- **[REUSE] `AtlasLoopWiredCallerService`** + the system-axis WIRED axis (Stage 7 wiring/orphan review).

| Stage | Engine | Tag |
|---|---|---|
| 1. UNDERSTAND scope+objective | `StateOfAtlasReader` + frontier comprehension (§B) | [REUSE+EXTEND] |
| 2. IDENTIFY most-exponential evolution | EV decider pick (§B) | [REUSE+WIRE] |
| 3. **PROJECTION / cross-model critique loop** | **`AtlasLoopProjectionEngine`** (§D.2) | [NEW] |
| 4. ORCHESTRATION | **`AtlasLoopOrchestrator`** (§E) | [NEW] |
| 5. IMPLEMENT (multi-agent) | `AtlasLoopTaskGrinder` / ADEP | [REUSE] |
| 6. TEST | frozen cert + battery (§A, §3) | [REUSE+EXTEND] |
| 7. WIRING REVIEW | `WiredCallerService` + WIRED axis + orphan scan | [REUSE] |

**Critical topology fix (C3):** `AtlasLoopObjectiveProducer::produce()` is constructed inside `AtlasLoopQueueRefiller` and runs **synchronously** on the grind hot loop; today `critic()->challenge()` (`:116`) is microsecond in-process arithmetic. The projection loop's unbounded Hermes round-trips **must not** live there — a multi-minute inner loop inside a watchdog-monitored synchronous producer is read as a zombie ("alive+hb-fresh but 0 grinds") and reclaimed, reintroducing the exact stall §F fixes. **Resolution:** `produce()` becomes a *dispatcher* — it enqueues a projection job into `atlas_loop_pipeline_state` and returns immediately. The `ProjectionEngine` runs the designer↔critic loop in its **own async stage** with its own claim/lease, never blocking the refiller.

---

### D.2 Stage 3 — the persistent projection/critique loop

**[NEW] `AtlasLoopProjectionEngine`**:
```
loop until CONTENT-FIXPOINT (NOT until a budget runs out):
  1. DESIGN:   gpt-5.5 (Codex) via Hermes (hermes_cli_default sentinel, no --model pin)
               → concrete design + machine-checkable ACCEPTANCE CONTRACT + risk list.
  2. CRITIQUE: a DIFFERENT model (GLM-5.2, fallback MiniMax-M3) refutes:
               "biggest verifiable leap or easiest-looking refactor? what breaks?
                what's the 2nd-order bottleneck?"  (writer ≠ judge, enforced.)
  3. REVISE:   critique → designer; design + acceptance contract updated.
  4. CONVERGENCE TEST (§D.3): content-fixpoint reached? else loop. Quality-stall → PARK.
```
The critique verdicts are the **real cross-model judges** the cert has always had a slot for but never received: `judge_verdicts` at `AtlasLoopSemanticImplementationCertifier.php:350` is read but **never written by any caller** (confirmed grep-zero). Critically, the certifier ALREADY synthesizes an in-process pseudo-judge from the adversarial panel at `:354-356` — feeding real cross-model verdicts upgrades the *lens diversity* of an existing slot rather than adding a new authority (this matters for §H3 below).

---

### D.3 Killing one-shot — infinite-time convergence with a content-based fixpoint and a liveness floor

**Retire these one-shot mechanisms (confirmed in code):**
- `max_scenarios_per_task=18`, `search_patience=4`, `scenarios_per_task=5` (`AtlasEvolutionScenarioExplorer.php:689`) — finite-budget logic, WRONG for an infinite-time loop.
- `pickWinner` tie → **smallest-diff** (`AtlasEvolutionScenarioExplorer.php:556`, confirmed verbatim) — actively pulls toward minimal patches over root-cause redesign. **Removed.** Tie-break **INVERTED**: prefer higher leverage / deeper root-cause relief.

**Convergence is a CONTENT fixpoint, not a panel-agreement fixpoint** (this is the SEV-1/critique-2 hole: "two rounds, no new material objection" is a non-deterministic, gameable oracle — a low-variance panel day declares a shallow design "maxed"). The machine-checkable replacement:

> **CONVERGED iff** the design's **acceptance contract has stopped gaining new falsifiable obligations** across the last 2 rounds (a *structural count* of testable obligations, not a panel mood) **AND** the contract covers the named binding-axis bottleneck **AND** the EV estimate is no longer improving. The acceptance contract must be expressible as obligations the deterministic cert can later verify — so "maxed" means "the design produced a verifiable spec that addresses the bottleneck," not "the critic ran out of phrasings."

**Liveness floor (no infinite livelock — SEV-3 critique-1):** if the *count of open obligations stops shrinking* for N rounds (oscillation: critic raises A, fix breaks B, critic raises B, fix breaks A), the objective **PARKS** with the full analysis (a quality-stall signal), freeing the pipeline slot. Park, not loop-forever.

**Bounded rate, infinite patience (E.4 reconciliation, honestly stated):** there IS a per-cycle model-spend ceiling. To avoid it being `search_patience` in a costume (SEV-1 critique-2), the **resumption guarantee** is explicit: a checkpointed projection is **pinned at its accrued-EV priority** in `atlas_loop_pipeline_state` and **re-thrown ahead of fresh objectives**, never into the open argmax — so it cannot be starved by new higher-EV work. **Honest residual (§I):** a single designer↔critic *round* is one Hermes turn and is **not checkpointable mid-turn**; the ceiling acts only *between* rounds, so one expensive round is uncapped. And on a genuinely tight budget where the ceiling is hit every cycle, *throughput* drops (fewer objectives reach convergence per day) but *quality* does not — depth-per-leap over breadth-of-shallow. This is the correct infinite-time tradeoff, and it is operator-tunable.

**`AtlasEvolutionScenarioExplorer` is demoted, not deleted:** it stays the Stage-5 *implementation* multi-attempt engine, with `patience`/`max_scenarios` reinterpreted as resource hints under the global budget and the smallest-diff tie-break removed. The *decision of what to build* is the ProjectionEngine's; the explorer's finite search no longer decides.

**Commit-per-piece:** each pipeline objective → one certified change → one commit through the unified locked merge primitive (§F.1). Long obras decompose via existing `AtlasLoopObraDecompositionPlanner` into per-piece commits, each separately certified + battery-checked + merged.

---

### E. MULTI-AGENT ORCHESTRATION

**[NEW] `AtlasLoopOrchestrator`** — thin model-role sequencer onto pipeline stages.

| Role | Model | Where |
|---|---|---|
| Design engine | gpt-5.5 (Codex) via Hermes | Stage 3 DESIGN, Stage 5 lead |
| Critique panel (writer≠judge) | GLM-5.2 primary, MiniMax-M3 fallback | Stage 3 CRITIQUE, Stage 6 real `judge_verdicts` |
| Implementation swarm | cross-provider portfolio | Stage 5 parallel attempts |

All via `hermes_cli_default` → no `--model` pin → Hermes-native fallback intact. **[REUSE]** cross-provider decorrelation (`AtlasLoopAttemptLedger` + portfolio) as parallelism substrate.

**Parallelism map:**
- Stage 3: serial designer↔critic per objective, but **many objectives' projections run in parallel** (bounded by supervisor parallel-pool).
- Stage 5: N decorrelated attempts per objective (existing fan-out), feeding the convergence loop.
- Coordination: existing blackboard (`atlas_claim_task`) + supervisor's `FOR UPDATE SKIP LOCKED` claim is the atomic seam.

**Single-failure-domain honesty (SEV-3 critique-2):** all three models route through Hermes — one transport, one failure domain. **Hermes-unavailable behavior is defined explicitly:** the projection engine **PARKS** the objective (never ships an un-critiqued design — that would be writer-with-no-judge, the moat hole). Stated robustness limit: a multi-hour Hermes outage stalls *all leap work* while refactor-supply (which needs no projection) continues. This is a real bound, not hidden.

**Model-spend ceiling (E.4):** when hit, in-flight projections finish their current round and checkpoint, then resume next cycle pinned at accrued-EV priority (§D.3). Infinite patience, bounded rate.

---

### F. CLOSING THE CYCLE ON MAIN + MAXIMUM AUTONOMY

#### F.1 Unify all merge crossings onto ONE locked primitive — the highest-risk gap

**Confirmed bug (all three critiques + this pass):** `AtlasLoopAutoMergeService` does real `git apply`/`git add`/`git commit` (`:358-541`) with **zero `flock`**, while `bin/atlas-loop-watchdog.sh:43` runs `artisan atlas:loop:automerge --limit=10` every `$INTERVAL` (60s) as a **separate process** from the campaign supervisor grinding+committing in the same `base_path`. Two processes mutating one worktree, no lock. This is the #1 way the loop corrupts main today.

**Corrected facts the routing must respect:**
- `AtlasLoopCycleGitContract` is **NOT dead** — `AtlasLoopCampaignCommand.php:71` calls `(new AtlasLoopCycleGitContract)->commitsBehindMain(...)` (staleness preflight, constructed with `new`, not DI). Only `commitCycle()`/`mergeToMain()`/`acquireMergeLock()` are unused. Its `flock` is `LOCK_EX | LOCK_NB` (`:303`) — **non-blocking**.
- `AtlasLoopObraAutoMergeService` is **not a clean orphan** — bound in `AppServiceProvider:209` and imported by `AtlasLoopObraDecompositionPlanner`; only `autoMerge()` is uninvoked.

**Architecture — route the live drain THROUGH the locked primitive, with a petreo actuator-shell (SEV-2 critique-1):**
- **[REUSE+EXTEND] `AtlasLoopCycleGitContract`** becomes the single git-mechanics primitive. `AtlasLoopAutoMergeService::mergeOne()` must, in order: (1) **run the Constitution battery BEFORE acquiring the lock**, on the immutable proposal commit, memoizing the verdict into the proposal row (SEV-1 critique-2: never run the multi-minute battery *under* the lock — that converts the data-race into a 60s-watchdog liveness stall); (2) acquire the exclusive lock; (3) `git apply` + `commit` (sub-second) inside the lock; (4) release.
- **Lock semantics fixed:** `LOCK_NB` as-is means a contended merge **silently skips** and the 60s watchdog could starve merges indefinitely while the supervisor holds the lock (SEV-2 critique-1). v1 uses **`LOCK_EX` blocking with a bounded timeout + deferred-retry** (the merge waits briefly, then re-queues for the next drain — never a silent drop). The lock is an advisory `flock` on a **stable path** under `$repoRoot` (`:297`), so the `new`-constructed staleness instance in the campaign command and the DI instance in the merge service lock the *same path* — lock correctness does not depend on object identity (H1 critique-3: this is why the two construction styles are safe, now stated).
- **The petreo actuator-shell:** the minimal ~20 lines that (a) acquire the flock, (b) call `ConstitutionGate`, (c) `git commit` **only if** the gate passed — stay **immutable** (the gate-invocation *site*, not just the gate). Otherwise the loop could relocate/neuter the gate call by editing `mergeOne` itself (SEV-2 critique-1 circularity). This shell is a no-blinder property ("gate ran and passed before commit"), not an implementation fence.
- **[REUSE+WIRE] `AtlasLoopObraAutoMergeService::autoMerge()`** gets its live home as the **multi-file branch of the same drain**, through the same lock — giving heavy/obra evolution a live merge path it lacks today, and converging the three fragmented git impls into one drift-proof lifecycle.

#### F.2 Self-heal so it runs alone for weeks

**[REUSE]** the proven robustness layer: atomic claim + **attempt-decrement-on-reclaim** (`AtlasLoopStore`), keepalive multi-layer respawn (death/frozen-alive/drift/starvation/reap), supervisor lock-lease + transient-DB park.

**Code-drift self-propagation — fixed with debounce + external trigger (SEV-2 critique-2, H4 critique-1):** `ATLAS_LOOP_RESTART_ON_CODE_DRIFT=false` today while self-merge auto-merge is ON, so a self-improvement merge lands but the running supervisor keeps executing **stale engine code**. On the bootstrap rung *every* certified self-merge is a pipeline-file merge → naive re-enable = restart-storm (5–10 restarts/hour tearing down projection checkpoints). Resolution:
- **The watchdog (external process, `bin/atlas-loop-watchdog.sh`) detects drift and triggers the restart**, NOT the in-process supervisor restarting itself (a process that edits its own respawn logic and dies is the classic failure — H4 critique-1). The watchdog must be verified **outside `DISCOVERY_ROOTS`** so the loop can never edit its own respawn trigger (enforced as a structural battery case).
- **Debounce/coalesce:** restart at most once per idle window or per N self-merges, after in-flight grinds finish.
- **Checkpoint survival:** in-flight projection state reloads from `atlas_loop_pipeline_state` across the restart (D.1 ↔ F.2 now connected).
- Re-enabling the flag is a **parked operator-decision step** (it reverses a recorded operator directive `loop-code-drift-restart-disabled` — M4 critique-3 forbids silent override), shipped with a Decision Receipt and the safety argument (battery + external-trigger + debounce).

#### F.3 Park-and-continue, never block

**[NEW] `AtlasLoopParkLedger`**: anything uncrackable parks with full analysis; the loop continues. The **only** human dependency is the narrow `battery:retire` two-key (§3.5), and even that does not block (a wrongly-blocked change parks). Park has re-attempt-with-escalation and a loop-health regression metric (§B) so it never silently becomes "park-everything." Live, no shadow — merges to real main under the unified locked primitive.

---

### G. TERRITORY LADDER + LOOP-IMPROVES-LOOP BOOTSTRAP

#### G.1 First rung — bootstrap on the loop's own code
`.env` confirms `DISCOVERY_ROOTS=app/Services/Ai/AutonomousEvolution` and `SELF_IMPROVEMENT_AUTO_MERGE_ENABLED=true`. But (key correction) the loop **cannot edit its own tree today** because the directory prefix is in FORBIDDEN (`:70`). The Constitution + the `property_gated` verdict (§3.4) is precisely what opens that door *safely*. Bootstrap order is the EV-natural ordering of binding axes (§5).

#### G.2 Earn territory by PROVING quality
**[NEW] `AtlasLoopTerritoryLadder`** — scope released gradually, gated on proven quality on the current rung (rung 0 = `AutonomousEvolution/`; rung 1 = broader `app/Services/Ai/`; rung 2 = memory; …). **Promotion rule:** K certified leaps that (a) raised the binding axis **as an originated capability leap, NOT a finite box-check toggle** (SEV-2 critique-2 — the ladder must distinguish "fixed a known gap" from "originated a leap"; box-checking does not promote), (b) each passed the battery, (c) zero RED-main incidents over a sustained window, (d) compounding ledger shows leaps trending *bigger* (TIME×LEVEL up). Reuses `AtlasChangeClassTrustLadder` (single-source, both merge paths feed it) extended from per-change-class to per-territory-scope. Promotion writes a Decision Receipt v2. Operator role shrinks to: describing a *want* (→ `DISCOVERY_BACKLOG_INTENTS=true` list) + the `battery:retire` two-key.

---

## 3. THE IMMUTABLE CONSTITUTION — airtight, machine-checkable spec

### 3.1 What is FROZEN vs MUTABLE

| Layer | Mutability | Mechanism |
|---|---|---|
| Constitution itself (gate + battery format + accept/reject rule) | **FROZEN** | `AtlasLoopConstitutionGateService` added to FORBIDDEN |
| Battery CONTENTS | **APPEND-ONLY** (grow, never shrink/weaken) | `AtlasLoopFrozenBattery`, hash-chained, materialized on disk |
| **Petreo actuator-shell** (the flock+gate+commit-only-if-passed lines in `mergeOne`) | **FROZEN** | the gate-*invocation site* stays petreo (SEV-2) |
| The cert chain (Certifier, FrozenJudge, ProofPanel, mutation gate, consensus gate) | **MUTABLE** (loop may rewrite ANY of it) | routed to `property_gated` verdict, not freely unlocked |
| `AtlasLoopMutationOperators` (the cert's detection vocabulary) | **MUTABLE but property-gated** | **added to FORBIDDEN→property_gated** (SEV-3: today it's editable AND the battery generator — shrinking it would shrink the battery; now battery is frozen-on-disk and the operator map is property-gated) |
| Selector / objective (`LeverageScorer`, `SystemAxisService`, `ExpectedValueDecider`, `StateOfAtlasReader`) | **MUTABLE but property-gated by an OUTCOME battery** (§3.6) | brings the selector INSIDE the Constitution (SEV-3b) |
| Watchdog respawn trigger (`bin/atlas-loop-watchdog.sh`) | **FROZEN** (outside `DISCOVERY_ROOTS`) | structural battery case (H4) |
| Sovereignty/audit invariants (`AtlasConstitutionalKernelService::INVARIANTS`, the 9) | **FROZEN** (unchanged, orthogonal) | as-is |

### 3.2 The verdict mechanism — `property_gated`, NOT "remove from list" (C2)
`isForbiddenSelfTarget` is `str_contains` against a flat list, called from **~26 sites across 13 files**, all meaning "petreo, never touch." Removing the cert-chain files would simultaneously tell the discovery service, refiller, and loss-observer the files are *freely* grindable — **before** the property gate exists, opening a no-guard window. Instead:

- **[REUSE+EXTEND] `AtlasLoopHarnessGuard::admit()`** gains a THIRD verdict: returns `'property_gated'` for cert-chain / mutation-operator / selector files. All ~26 existing call sites keep seeing "not freely admissible"; the new verdict routes the target to `ConstitutionGate::admit()` instead of silent unlock. The broad directory-prefix entry (`:70`) is **narrowed** so the loop's own files become `property_gated` rather than `forbidden`-by-substring — while genuinely-petreo files (the actuator-shell, migrations, the Constitution gate, the watchdog) stay `forbidden`.
- **Ordering invariant:** the guard is loosened (file → `property_gated`) **only in the same atomic slice** that wires the gate into the live cert + merge paths. Never a window where a cert-chain file is editable with no replacement guard.

### 3.3 The frozen battery — concrete contents (materialized on disk)
Each case is a frozen `{diff_bytes, contract, expected_verdict, provenance}` tuple on disk — **NOT regenerated from `MutationOperators` at gate time** (SEV-3: hashing a generator's output is hollow; the generator can move).

**Known-BAD (must be REFUTED by any cert version) — behavioral engine:**
1. fake-green no-op (diff-earned / RED-on-revert).
2. wrong-but-green / overfit short-circuit (held-out delta).
3. behavior-break-with-cx-drop (complexity-earned masking a real break).
4. scope/tamper escape via `.gitignore` self-authoring (census weakening).
5. consumer-break (green locally, breaks a code-graph consumer).
6. survivor mutant (added branch, no killing assertion).

**Known-BAD — ROBUSTNESS, structural/behavioral PROBE engine (M3 split):** a dropped `flock`, removed attempt-decrement, removed boot-smoke, non-exclusive supervisor lock, no-acceptance-contract merge **cannot be caught by the behavioral cert** (concurrency/structure don't change a single-process test's red/green). These run on **`AtlasLoopRobustnessProbe`**: (i) concurrency assertions ("two processes race to merge → assert serialization", run for real, not grepped — a `LOCK_SH`-instead-of-`LOCK_EX` rewrite must still fail), (ii) structural-presence assertions ("the actuator-shell still calls the gate before commit"; "the watchdog trigger is outside `DISCOVERY_ROOTS`"). Expected verdict REJECT.

**Known-GOOD (must be CERTIFIED by any cert version):** a genuine RED→GREEN feature add with a real killing assertion; a genuine cyclomatic reduction that preserves behavior (revert→RED on the characterization test); a genuine cross-file refactor with consumers green. **Expressed as MINIMUM obligations** the candidate must still satisfy (§3.7), so a *stricter* judge that certifies a superset is never rejected for refusing a now-wrong exact case (SEV-2 critique-2).

### 3.4 The exact ACCEPT/REJECT rule — execution-based, never structural (SEV-1 critique-1; C1 critique-3)
The gate is **NOT** hosted in `AtlasLoopFormalInvariantGateService` — confirmed its *only* caller is `AtlasLoopFormalInvariantGateCommand` (a manual artisan command, not in the grind/merge runtime path) and every check it makes is `str_contains` source-text matching (`:218-254`), structurally incapable of expressing "the judge still detects." Hosting the keystone there would make it **born dead-wired** and degrade "cert vN kills the battery" into `str_contains(certifierSource,'mutation')` — the branch-sawing attack through the back door.

Instead, `AtlasLoopConstitutionGateService::admit($candidateCertDiff)` runs **as a subprocess in a clean worktree** (the "whole ballgame" question SEV-1 demanded an answer to):

```
1. Build candidate cert version: apply the diff via AtlasLoopProposalOutOfProcessVerifier's
   real createWorktree() → `git worktree add --detach HEAD` (:309-312).
   The candidate certifier runs as a SUBPROCESS (Symfony Process) inside the worktree —
   NOT `require`d into the live process (avoids the class-redeclaration `exit 255`
   autoloader-redeclare bug already recorded in loop-refactor-cert-autoloader-blocker).
   Its verdict per battery case is captured from subprocess stdout (structured JSON).

2. PASS iff  ∀ bad ∈ battery.bad:   candidateCert.verdict(bad)  == REFUTE
        AND  ∀ good ∈ battery.good (as min-obligations): candidateCert satisfies each
        AND  ∀ robustness ∈ battery.robustness: AtlasLoopRobustnessProbe.assert(candidate) == HOLD

3. REJECT otherwise; receipt = first surviving-bad / rejected-good / violated-robustness.
```

### 3.5 Wiring the gate into the LIVE paths (the call sites that DON'T exist today — C1)
The build order explicitly **creates two call sites** (they are not pre-existing):
- **Grind-time:** `AtlasLoopSemanticImplementationCertifier::certify()` calls `ConstitutionGate::admit()` as a mandatory conjunct before `$reasons === []`, **only when the candidate diff targets a `property_gated` file** (cert-chain / mutation-operator / selector).
- **Merge-time:** inside the petreo actuator-shell of `AtlasLoopAutoMergeService::mergeOne()` (run **before lock acquisition**, §F.1), so the merge boundary is never weaker than certification for self-modifying changes.

### 3.6 Why a strictly-better judge PASSES and a blinder is REJECTED (the operator's two properties, by construction)
- **(a) Strictly-better judge PASSES.** The battery is a **lower bound on detection power**. A judge that catches everything today's catches PLUS more still REFUTEs every battery-bad and satisfies every battery-good min-obligation → PASS. Adding a guard, a real refuter, a stronger mutation operator only *adds* refutations.
- **(b) Blinder/weaker judge REJECTED.** Any fail-open, removed check, or weakened threshold lets ≥1 battery-bad survive (REFUTE→CERTIFY) → REJECT. Checks a PROPERTY, never an implementation — a clean rewrite passes iff it preserves detection power.

### 3.7 How the battery evolves without silent weakening (the hard part, designed)
1. **Append-only, hash-chained.** `battery_root_hash = H(prev_hash || new_case)` (reusing the `kernelHash()` reproducible-receipt pattern). Removing a case breaks the chain → REJECT.
2. **Asymmetric append rules (SEV-3 critique-2 — chain secures removal, not dilution):**
   - Appending a **known-BAD** *strictly strengthens* → the loop may do it autonomously (a normal certified delivery).
   - Appending a **known-GOOD** can *dilute* the floor (a permissive good = a permanent license to weaken). So a known-good append must itself (i) pass the *current* judge AND (ii) pass a meta-check that it does not subsume any known-bad's neighborhood (no new certify-license over a must-catch region). Adding a known-good that fails the meta-check is on the **operator side of the two-key**.
3. **Known-goods are MINIMUM obligations, not exact cases** (SEV-2 critique-2 — the "stricter judge gets rejected" nightmare). A stricter judge certifies a *superset* of behaviors; expressing goods as min-obligations means a stricter judge is never rejected for refusing a now-wrong exact good. **Honest residual (§I):** the irreducible case where a genuinely-better judge correctly refuses a min-obligation that is itself wrong still requires a `battery:retire` two-key — that single act is the one place the loop blocks on operator latency, **by design** (weakening the safety floor is the only non-autonomous act), and the blocked change PARKS and the loop continues.
4. **Two-key retire** = the single, narrow human dependency: `atlas:loop:battery:retire --case=… --reason=…` writes a Decision Receipt v2. Does not block (wrongly-blocked → park → continue).

### 3.8 The OUTCOME battery — the selector inside the Constitution (SEV-3b, the largest hole)
The cert-battery bounds *judge* weakening but says nothing about *selector* drift toward easy-to-certify changes. The loop, optimizing throughput, has a standing incentive to inflate `P_success`/`value` or redefine `bottleneck_relief`. So:

**[NEW] `AtlasLoopFrozenOutcomeBattery`** — a frozen set of `(objective_pair, correct-relative-ranking)` cases where any new selector MUST still rank a genuine leap above a behavior-preserving refactor. When a candidate diff touches a selector file (`property_gated`), `ConstitutionGate` additionally runs the candidate selector against the outcome battery and REJECTs any version that inverts a frozen ranking. This makes the operator's "behavior-preserving refactor = ZERO improvement" rule enforced by an **immutable property**, not by an editable selector. Same append-only / two-key discipline as the cert-battery.

---

## 4. The 7-stage pipeline as infinite-time runtime (one-shot retired)

| Stage | Infinite-time behavior | One-shot mechanism RETIRED |
|---|---|---|
| 1 UNDERSTAND | brain read + frontier comprehension, fail-open | — |
| 2 IDENTIFY | EV argmax over re-resolved moving axes; re-pivots each cycle | `strategicWeightFor` keyword proxy → comprehension |
| 3 PROJECTION | designer↔critic until **content-fixpoint** (acceptance contract stops gaining obligations + covers bottleneck + EV plateaus); **quality-stall → PARK**; **async**, not in the refiller | `max_scenarios=18`, `search_patience=4`, single-shot `challenge()`; smallest-diff tie-break |
| 4 ORCHESTRATION | model-role sequencing; Hermes-down → park, not ship-uncritiqued | — |
| 5 IMPLEMENT | N decorrelated attempts feeding convergence; explorer demoted to resource-hinted impl engine | explorer's finite *decision* role |
| 6 TEST | deterministic cert + cert-battery + outcome-battery; `final = deterministic AND (panel advisory only)` | — |
| 7 WIRING | WiredCaller + WIRED axis + orphan scan; nothing dead | — |

**Resumption guarantee (not a renamed cap):** checkpointed projections pin at accrued-EV priority and re-enter ahead of fresh objectives; the spend ceiling time-slices *rate*, never truncates *a* projection into a shallow ship. Honest residual: one round is not mid-turn-checkpointable; tight budget lowers throughput not quality (§D.3, §I).

---

## 5. ORDERED IMPLEMENTATION SLICE PLAN (bootstrap on the loop's own scope first)

Ordered so each slice makes the loop more capable of safely building the next. Slices 1–4 are the **safety + topology foundation** that must land before any self-edit is admitted. **Model-bound-capped** slices are flagged ⚑.

**Slice 0 — Parked operator-decision receipts (no code-merge by the loop).** *Delivers:* Decision Receipts for the three config reversals the loop must not flip silently — `producer_feature_origination_enabled` ON, `RESTART_ON_CODE_DRIFT` ON (with the §F.2 safety argument), and (if chosen) the three advisory flags for InsightBackprop. *New/Reuse:* parked items in `AtlasLoopParkLedger`. *Touches:* `config/atlas.php:2064`, `.env`, `loop-code-drift-restart-disabled` memory. *Proof:* receipts exist; loop does not auto-flip; flags remain operator-gated.

**Slice 1 — The petreo actuator-shell + merge-lock unification (the #1 main-corruption bug).** *Delivers:* `AtlasLoopAutoMergeService::mergeOne()` routed through `AtlasLoopCycleGitContract`'s exclusive lock; **`LOCK_EX` blocking + bounded-timeout deferred-retry** (no silent `LOCK_NB` drop); battery memoized **before** lock; `git apply`+commit sub-second inside lock; `AtlasLoopObraAutoMergeService::autoMerge()` given the multi-file branch. *New/Reuse:* [REUSE+EXTEND] `AtlasLoopCycleGitContract`; [REUSE+WIRE] `AtlasLoopObraAutoMergeService`. *Touches:* `AtlasLoopAutoMergeService.php:358-541`, `AtlasLoopCycleGitContract.php:297/303`, `AtlasLoopCampaignCommand.php:71` (audit the `new`-construct staleness caller), `bin/atlas-loop-watchdog.sh:43`. *Proof:* concurrency test — two processes race to merge, assert serialization; no commit applied while lock held by the other; deferred merge re-queues, never drops.

**Slice 2 — `AtlasLoopRobustnessProbe` + robustness battery cases.** *Delivers:* the behavioral/structural prober for lock-drop, attempt-decrement, boot-smoke, supervisor-lock-exclusivity, no-acceptance-contract merge, and watchdog-outside-`DISCOVERY_ROOTS`. *New/Reuse:* [NEW] `Constitution/AtlasLoopRobustnessProbe`. *Touches:* new `Constitution/` tree; reads `AtlasLoopStore`, `AtlasLoopAutoMergeService`, watchdog. *Proof:* each robustness case REJECTs its breakage diff including a `LOCK_SH`-substitution rewrite (proves it's not a grep); HOLDs the clean code.

**Slice 3 — `AtlasLoopFrozenBattery` (materialized on disk, hash-chained) + behavioral cases.** *Delivers:* the 6 known-bad + 3 known-good cases as frozen `{diff_bytes, contract, expected_verdict}` files + append-validated manifest + `battery_root_hash`; asymmetric append rules; `atlas:loop:battery:retire` two-key CLI. *New/Reuse:* [NEW] `AtlasLoopFrozenBattery`; reuses `kernelHash()` pattern; reuses `AtlasLoopMutationOperators::map()` **once, to author the case files** (then frozen — not regenerated). *Touches:* new `Constitution/battery/`; **add `AtlasLoopMutationOperators` to FORBIDDEN→property_gated**. *Proof:* removing a case breaks the chain → REJECT; appending a known-bad passes autonomously; appending a permissive known-good is meta-rejected.

**Slice 4 — `AtlasLoopConstitutionGateService` (execution-based) + `property_gated` verdict + live call sites.** *Delivers:* subprocess-in-worktree candidate-cert execution against the battery; `AtlasLoopHarnessGuard::admit()` third verdict; narrow the `AutonomousEvolution/` prefix so loop files become `property_gated`; create the two call sites (certify + actuator-shell). *New/Reuse:* [NEW] `AtlasLoopConstitutionGateService`; [REUSE] `AtlasLoopProposalOutOfProcessVerifier` worktree+subprocess; [REUSE+EXTEND] `AtlasLoopHarnessGuard`. *Touches:* `AtlasLoopHarnessGuard.php:33/70/76/113`, `AtlasLoopSemanticImplementationCertifier.php` (new conjunct), `AtlasLoopAutoMergeService.php` (actuator-shell). *Proof:* a blinder cert diff (removes mutation check) → REJECT with surviving-bad receipt; a strictly-better cert diff (adds a guard) → PASS; **subprocess avoids `exit 255` redeclare** (regression-tested against `loop-refactor-cert-autoloader-blocker`); the ~26 `isForbidden` call sites unchanged in meaning. **GATE: no self-edit admitted before this slice is green.**

**Slice 5 — `AtlasLoopFrozenOutcomeBattery` (selector inside the Constitution).** *Delivers:* frozen `(objective_pair, correct-ranking)` cases; `ConstitutionGate` rejects a selector version that inverts a frozen ranking (leap-over-refactor). *New/Reuse:* [NEW]; same append/two-key discipline. *Touches:* `Constitution/`, gate. *Proof:* a selector diff that ranks a behavior-preserving refactor above a genuine leap → REJECT.

**Slice 6 — `AtlasLoopSystemAxisService` (live moving axis vector).** *Delivers:* per-cycle re-resolved system-axis vector (WIRED/REAL_TARGET/NON_TRIVIAL/COMPOUNDING/SAFETY + continuous re-violatable robustness axis) feeding `axis_values`. *New/Reuse:* **[NEW]** (NOT an in-place edit of the FORBIDDEN `AtlasLoopUtilityGradeService` — it *consumes* its re-resolution discipline). *Touches:* reads `AtlasLoopUtilityGradeService.php:154/368` (read-only), `AtlasLoopWiredCallerService`. *Proof:* axis vector recomputes from a fresh git commit, never a stored field; robustness axis regresses when an incident is injected into the ledger.

**Slice 7 — Wire `AtlasLoopExpectedValueDecider` into the rédea.** *Delivers:* EV pick (`P_success·value·bottleneck_relief − cost`) inserted in `produce()` after rank+floor, before `origination()->build()`. *New/Reuse:* [REUSE+WIRE]; depends on Slice 6's `axis_values`. *Touches:* `Discovery/AtlasLoopObjectiveProducer.php:106-120`, `AtlasLoopExpectedValueDecider.php:54`, `AtlasLoopWorkClassPriorService.php:103`. *Proof:* EV receipt honest (`is_optimal=false`); the pick re-pivots to the next-binding axis after the prior is relieved.

**Slice 8 ⚑ — `AtlasLoopDeliveryPipeline` (async state machine) + sever `produce()` from the refiller.** *Delivers:* `atlas_loop_pipeline_state` table; `produce()` becomes a dispatcher that enqueues a projection job and returns immediately (kills the synchronous-refiller stall, C3); resumable checkpoints. *New/Reuse:* [NEW]. *Touches:* `Discovery/AtlasLoopObjectiveProducer.php`, `Discovery/AtlasLoopQueueRefiller.php:47`. *Proof:* refiller returns in microseconds while a projection runs async; the zombie-detector does not reclaim a projecting worker. **Model-bound-capped:** projection quality rides Hermes.

**Slice 9 ⚑ — `AtlasLoopProjectionEngine` (designer↔critic) + feed real `judge_verdicts`.** *Delivers:* gpt-5.5-designs ↔ GLM/MiniMax-critiques loop with content-fixpoint convergence + quality-stall PARK; real cross-model verdicts fed to `Certifier:350`. *New/Reuse:* [NEW]; [REUSE] `AtlasLoopJudgeConsensusGate`. *Touches:* replace `Discovery/AtlasLoopObjectiveProducer.php:116`; `AtlasLoopSemanticImplementationCertifier.php:350`. *Proof (H3 — necessary-but-not-sufficient):* (a) empty→populated `judge_verdicts` does NOT change the pass threshold for non-self-modifying changes (no mass-RED regression — verified against the `:345` "absent those, the cert's own signals serve" branch); (b) a CERTIFY-leaning panel **cannot lift a deterministic REFUTE** — assert `final = deterministic AND (panel advisory)`; (c) for cert-chain + feature-origination changes a missing/REFUTE cross-model verdict **blocks (fail-closed → PARK when panel unavailable)** while low-risk refactors stay fail-open (the §H reconciliation of writer≠judge vs never-block). **Model-bound-capped.**

**Slice 10 — Kill one-shot in the explorer.** *Delivers:* remove smallest-diff tie-break; invert tie to higher-leverage; demote `patience`/`max_scenarios` to budget hints. *New/Reuse:* [REUSE+EXTEND]. *Touches:* `AtlasEvolutionScenarioExplorer.php:556/689`. *Proof:* given two maxed designs, the higher-leverage one wins; no attempt-cap truncates a projection.

**Slice 11 — `AtlasLoopOrchestrator` + Hermes-down behavior.** *Delivers:* model-role sequencing; explicit park-on-Hermes-outage; per-cycle spend ceiling with accrued-EV-pinned resumption. *New/Reuse:* [NEW]; [REUSE] cross-provider portfolio. *Touches:* `AtlasLoopAttemptLedger`, supervisor budget plumbing. *Proof:* Hermes-down → leap objectives park, refactor-supply continues; checkpointed projection resumes ahead of fresh objectives. **Model-bound-capped.**

**Slice 12 ⚑ — `AtlasLoopExternalResearchService` (read-only, fail-closed).** *Delivers:* topic-only `WebSearch`/`WebFetch` intake → `atlas_loop_research_notes` live advisory table; static egress guard (string-topic-only) as a structural battery case; fail-closed when the tool is absent. *New/Reuse:* [NEW]. *Touches:* new service + table; deferred-tool availability check. *Proof:* a call site passing a `$diff` fails the static guard; tool-absent → no research, no leak. **Model-bound-capped.**

**Slice 13 — Drift-restart re-enable (external-triggered, debounced, checkpoint-surviving).** *Delivers:* watchdog detects drift and triggers a debounced restart; projection checkpoints reload from `atlas_loop_pipeline_state`; watchdog verified outside `DISCOVERY_ROOTS`. *New/Reuse:* [REUSE] keepalive layer + Slice 0's receipt. *Touches:* `bin/atlas-loop-watchdog.sh`, `AtlasLoopCampaignSupervisor`. *Proof:* a self-merge triggers ≤1 restart per window; in-flight projection survives; an injected self-edit to the in-process restart logic cannot prevent the external trigger.

**Slice 14 — `AtlasLoopParkLedger` escalation + loop-health metric.** *Delivers:* re-attempt-with-escalation; "high-EV parking faster than delivered" regression flag. *New/Reuse:* [NEW]. *Touches:* park ledger, outcome ledger. *Proof:* a parked high-EV item is re-attempted with a stronger model / decomposition; the health metric fires when top-EV items accumulate unparked-but-undelivered.

**Slice 15 — `AtlasLoopTerritoryLadder`.** *Delivers:* per-territory-scope promotion (K leaps + battery-clean + zero-RED-main + TIME×LEVEL-up; box-checking excluded). *New/Reuse:* [NEW]; [REUSE] `AtlasChangeClassTrustLadder`. *Touches:* trust ladder, compounding ledger, `DISCOVERY_ROOTS`. *Proof:* promotion to rung 1 only after the rule's four conditions; finite-toggle wins do not promote; writes a Decision Receipt v2.

**Bootstrap self-improvement order (what the EV brain naturally selects on rung 0, = the binding axes):** Slices 1–4 (safety+topology) → 5–7 (selector inside Constitution + EV brain) → 8–10 (async projection + one-shot kill) → 11–13 (orchestration + research + drift) → 14–15 (park health + ladder). Each makes the loop more capable of safely building the next — loop-improves-loop, the exponential inflection.

---

## 6. Residual risk (honest, not hand-waved)

1. **The irreducible model-bound core.** Greenfield decomposition *origination* and *semantic-correctness judgment* live in gpt-5.5/GLM/MiniMax. The Constitution guarantees the cert never goes blinder and the projection loop raises *design* quality, but the ceiling of "how good a leap the loop can originate" is bounded by the frontier model behind Hermes. Atlas multiplies (N×M); it does not *originate* beyond the engine. Forcing this closed deterministically would be the forbidden Goodhart. *(Model-bound; slices 8/9/11/12.)*

2. **Closing the cycle on main — lock liveness vs latency.** The unified `LOCK_EX` blocking primitive removes the data-race, but a long-held supervisor lock plus deferred-retry can *slow* merge cadence under heavy concurrency. The robustness axis + concurrency test bound it, but P99 merge latency under sustained contention is an empirical number to measure in soak, not prove on paper. The battery memoized *before* the lock keeps the lock window sub-second, which is the mitigation.

3. **The battery is a lower bound, not total-safety proof.** It guarantees no known breakage reappears and no certified-good regresses. A *novel* bad class not yet in the battery could pass a weakened judge once — until caught in the wild and appended (append-only ratchet). Monotonically safer over time, not a static completeness proof. The `battery:retire` two-key is the one place a human stays in the loop, by design — and the one place a genuinely-better-but-stricter judge can block on operator latency (§3.7.3). This is the honest, irreducible tension between "never block better" and "never weaken the floor."

4. **Greenfield-novel design quality + cross-model independence.** GLM/MiniMax refuting gpt-5.5 defeats *correlated single-model* blind spots (the Factory-Droid "Codex catches the GLM bug" finding), but shared-training-corpus correlation is a residual the deterministic battery + mutation kill-vector backstop — which is *why* the cert decision stays deterministic and the models stay advisory-into-design. All three models share one Hermes transport (one failure domain); a multi-hour Hermes outage stalls all leap work while refactor-supply continues (§E). *(Model-bound; slices 9/11.)*

5. **Throughput vs infinite-time under a tight budget.** The spend ceiling time-slices *rate*, not *quality*; a very tight budget lowers leaps/day, not leap depth. One designer↔critic round is not mid-turn-checkpointable, so a single expensive round is uncapped. Operator-tunable, does not compromise the design.

These five are the genuine frontier. Everything else is buildable on the verified, mapped machinery.

---

## J. Verified ground truth (corrected map — supersedes the draft's errors)

| Claim | Verdict | Evidence |
|---|---|---|
| `AtlasLoopExpectedValueDecider` is an orphan | **TRUE** | only self+test references; sig `decide(candidates, context.axis_values/class_stats)` `:54-58` |
| `producer_feature_origination_enabled` default false, absent from `.env` | **TRUE** | `config/atlas.php:2064` |
| `AtlasLoopAutoMergeService` does real git ops with zero `flock` | **TRUE** | `:358-541`, no flock; watchdog drains every 60s `bin/atlas-loop-watchdog.sh:43` as a separate process |
| `judge_verdicts` slot read but never fed | **TRUE** | `Certifier:350` reads `options['judge_verdicts'] ?? []`; panel already synthesizes an in-process judge at `:354-356`; `:345` "absent those, the cert's own signals serve" |
| Constitution host = `FormalInvariantGateService` | **FALSE (draft error)** | only caller is `AtlasLoopFormalInvariantGateCommand`; all checks `str_contains` `:218-254` — CLI-only, not runtime-path; cannot express detection |
| `AtlasLoopCycleGitContract` is dead | **FALSE (draft error)** | live caller `AtlasLoopCampaignCommand.php:71` (`new` construct, staleness); `commitCycle`/`mergeToMain`/`acquireMergeLock` unused; lock is `LOCK_EX\|LOCK_NB` `:303` |
| `AtlasLoopObraAutoMergeService` is a clean orphan | **FALSE (draft error)** | bound `AppServiceProvider:209` + imported by `ObraDecompositionPlanner`; only `autoMerge()` uninvoked |
| "Remove cert-chain from FORBIDDEN" is a one-liner | **FALSE (draft error)** | `isForbiddenSelfTarget` `str_contains` called ~26× across 13 files; removal = silent unlock everywhere |
| Loop can edit its own code today | **FALSE** | `AutonomousEvolution/` prefix in FORBIDDEN `:70` → whole tree forbidden by substring; `meta_harness` flags only gate `HARNESS_PREFIXES`, checked *after* the forbidden check |
| `AtlasLoopUtilityGradeService` is freely extensible | **FALSE** | it is FORBIDDEN at `:49` — must be *consumed* by a NEW `SystemAxisService`, not edited in place |
| Key Discovery files at top-level dir | **FALSE (anchor error)** | `AtlasLoopObjectiveProducer`/`OriginationBuilder`/`AdversarialCritic`/`ExpectedValueDecider` live under `Discovery/`; line numbers correct |
| OOP verifier has a real clean-worktree subprocess harness | **TRUE** | `createWorktree()` → `git worktree add --detach HEAD` + Symfony Process `:309-312` — the Constitution's subprocess execution substrate |
| The merge race (concurrent unlocked drain) is real and #1 | **TRUE** | confirmed by all three lenses + this pass |