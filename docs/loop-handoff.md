# Atlas Loop — Engineering Handoff (make the loop perfect at its OWN scope)

> Handoff written 2026-06-21 by the previous Claude. You are taking over the **engineering of the Atlas Loop**.
> **You do NOT run the loop.** You **structure and improve** it so it becomes *perfect and fully autonomous*
> at evolving its own scope. Nothing here is about launching campaigns, engines, or babysitting a live run —
> all of that is deliberately omitted.

---

## 0. Your mission (read this twice)

Make the **Atlas Loop** (AutonomousEvolution engine) **fully autonomous at evolving its OWN code scope**:
`app/Services/Ai/AutonomousEvolution/` (244 PHP files — the loop's own machinery).

"Fully autonomous in its own scope" means the loop, by itself, can:
1. **Understand** the scope + its goal (what the loop is for, what would make it better).
2. **Identify** the highest-leverage evolution available right now (not the easiest — the most exponential).
3. **Implement** it — a new feature, an evolution, a *new level/patamar* of capability, or a robust refactor.
4. **Test + certify + measure** it honestly (real improvement proven, behavior preserved).
5. **Research the internet** when it hits the edge of its understanding — when it doesn't understand
   something, or needs information/state-of-the-art it doesn't have, it goes and finds it.
6. Do all of the above **in the least time**, **commit-by-piece**, **without any human or external (Claude
   Code/Codex) review**.

**Why this scope first:** the loop perfecting *itself* is the proving ground. The moment it demonstrably does
its own job well in its own scope, it earns the right to be pointed at other scopes (Atlas Dev, engineering,
trading, etc.). So: **make it excellent here, on itself.**

This is an **engineering** job. You are improving the loop *as a system*. Think: what is structurally missing
or weak that prevents the loop from autonomously finding-and-landing real evolutions of its own code?

---

## 1. Read these first (canonical truth — do not skip)

- `docs/loop-canonical-definition.md` — the canonical definition of what the Loop IS and is NOT.
- `docs/loop-os-architecture.md` — the Loop-OS build spec (the converged architecture).
- `atlas-server/CLAUDE.md` → the "🎯 Loop — DEFINIÇÃO CANÔNICA" block (anti-proxy framing).
- The Atlas auto-memory `loop-*` files (recall via the Atlas brain / MEMORY.md index): especially
  `loop-true-objective-canonical`, `loop-delivery-pipeline-design-dominant`, `loop-not-proxy-cleanup-feedback`,
  `loop-endgoal-sole-autonomous-self-engineer`, `loop-os-architecture-converged`.
- `docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md` — the knowledge-governance rules
  (what is canonical vs read-model) before trusting any context.

**The single most important framing** (from the canonical def): the loop is **NOT** micro-editing, cleanup,
proxy optimization (landing-rate / cyclomatic-count / test-count), or one-shot. A behavior-preserving refactor
with **no material proof of improvement = ZERO value**. The loop IS: understand → identify the most
exponential evolution → **frontier projection + cross-model critique in a loop (the phase that guarantees
quality)** → multi-agent orchestration → implement → test → wiring review. Continuous, never one-shot.

---

## 2. The Real-Value Contract (the anti-Goodhart spine — never violate)

**Real value IS:**
- A real bug fix with `red`/`revert_recheck`.
- Self-improvement of the loop itself with a metric/proof of *better capability*.
- A feature/infra that increases **autonomy, decision quality, work supply, certification, throughput, or
  honesty**.
- A material refactor with **real complexity/coupling reduction AND behavior preserved/proven**.
- A short campaign showing the loop **chooses substantial work, not just coverage**.

**Real value is NOT:**
- Planning/roadmap without live implementation.
- Behavior-preserving refactor without material proof.
- Characterization/coverage-only dominating.
- A scorecard saying "real work" when it's only verification.
- PatternRegistry/PatternDriver attaching a `[pattern-driver…]` seal **without a real veto**.
- A small commit that only improves internal aesthetics and doesn't improve the loop's capability.

If you ever find yourself optimizing a **proxy** (a number that *correlates* with value but isn't value),
**stop** — that's the exact trap an earlier AI fell into for days. Ask: *"does this make the loop genuinely
more capable at autonomously evolving its scope?"* If not, it's not the work.

---

## 3. Architecture — how one unit of work flows (so you can find leverage)

A single evolution moves through this pipeline. Files are under `app/Services/Ai/AutonomousEvolution/`:

```
DISCOVERY            Discovery/AtlasLoopObjectiveProducer.php   gather() → candidates, each STAMPED with the
  │                                                              honest value signal: proxy / cosmetic /
  │                                                              work_value_class / work_value_reasons / shape.
  │                  Pattern/AtlasLoopPatternDecisionDriver.php  vetoes proxy/cosmetic candidates, picks the
  │                                                              material runner-up (flag-gated, load-bearing).
  ▼
REFILL (mint work)   Discovery/AtlasLoopQueueRefiller.php       refill() runs the lanes that turn candidates
  │                                                              into queued tasks. Lanes inside it:
  │                                                                • objective producer (the "rédea": biggest
  │                                                                  cross-target leap; projection stage)
  │                                                                • per-target generateAndEnqueue (refactor /
  │                                                                  coverage / bug / feature)
  │                                                                • multi-file refactor (obra cluster lane)
  │                                                                • tryDecomposeMaterialSupply (NEW, mine —
  │                                                                  drains untapped material methods)
  │                                                              GATE: isProxyRefactorTask() drops proxy
  │                                                              refactors; completeTargetEnqueue() decides
  │                                                              'enqueued' vs 'deferred'.
  ▼
GRIND (execute)      atlas:loop:grind-task (command)            claims a task, the engine writes the failing-
  │                                                              test-first (RED) change, then certifies.
  ▼
CERTIFY              AtlasLoopSemanticImplementationCertifier    measureScopedComplexityDrop() = TOTAL decisions
  │                  AtlasLoopMutationAdequacyGateService        (total − methods) + a max-gate (anti-gaming).
  │                  AtlasLoopBehavioralEquivalenceGate          kill-ratio STRENGTH gate (fail-open;
  │                  Verify/AtlasLoopSignalAnalyzer              mutation_kill_ratio_floor default 0.0 = OFF).
  │                                                              SignalAnalyzer = nikic AST: fileComplexity()
  │                                                              returns a per_method census + cyclomaticScore.
  ▼
MEASURE              AtlasLoopRealWorkScorecardService           classify() the certified work: bug_fix /
                     (cmd atlas:loop:real-work-scorecard)        feature / verification / self_improvement /
                                                                 proxy / cosmetic + an honest claim policy.
```

**Key invariant the cert enforces (important):** the complexity cert measures the file's **total decision
count** (and forbids any method exceeding the baseline max), NOT one named method. So a refactor that reduces
**any** complex method certifies — but a diff that lowers the max while **adding net branches** is REJECTED
(`complexity_not_reduced`). There is a **per-method identity gate** (`complexity_method_identity_gate` →
`AtlasLoopSignalAnalyzer::structuralComplexityReduced`) but it is flag-gated and only for the create/extract-
class lane.

**Material bar:** a method is "material" at `cyclomatic >= config('atlas.loop.material_refactor_min_cyclomatic', 12)`.

---

## 4. What was built/changed this session (all committed on `main` = `b48cd20b1`, NOT pushed)

The branch `feat/loop-honest-value-signal` was fast-forwarded into local `main` (19 commits ahead of
`origin/main`). Highlights you inherit:

1. **Honest value signal** in `AtlasLoopObjectiveProducer::gather()` — stamps `proxy`/`cosmetic`/
   `work_value_class`/`work_value_reasons`/`shape` from raw signals (cyclomatic, callerCount, verifiable).
   Material = verifiable AND cyclomatic≥12 AND a caller; else proxy with reasons. **(Mission technical
   objective #1 — done, but see §5.1 to make it airtight.)**
2. **Material-supply gate** `AtlasLoopQueueRefiller::isProxyRefactorTask()` — a refactor task with no material
   proof (revert_recheck / red_required / the governed self-improvement triple) is dropped as proxy so it
   never grinds. Mirrors the honest scorecard's rule.
3. **Relative coverage cap** in the refiller — coverage/characterization can't exceed the substantive work of
   a refill (anti coverage-domination), per-refill AND campaign-cumulative.
4. **Honest scorecard** `AtlasLoopRealWorkScorecardService` + cmd — 100% verification ≠ a real-work claim.
5. **`AtlasLoopComplexTargetDecomposer`** (Discovery/) + **`tryDecomposeMaterialSupply` lane** in the refiller
   — the loop's scope has **131 untapped material methods** (cyclomatic≥12) across 29+ multi-method files; the
   single-objective discovery only surfaces ONE refactor per file and abandons the rest. This lane drains them
   as material refactor supply, through the IDENTICAL synthesizer+cert (not faked), conflict-free, gated
   `ATLAS_LOOP_DECOMPOSE_SUPPLY_ENABLED` (default-OFF). Tests: `AtlasLoopComplexTargetDecomposerTest` (4),
   `AtlasLoopDecomposeSupplyLaneTest` (3). **Proven to MINT material supply; NOT yet proven to grind→certify
   end-to-end (see §5.3).**
6. **P27 research foundation** — `AtlasLoopResearchOriginator` (Discovery/) + `AtlasLoopExternalResearchService`
   — research-topic → RED-gated, source-quarantined objective; fail-closed (no backend ⇒ no work), egress-
   protected (no repo path/secret/diff leaks out). Config-gated `ATLAS_LOOP_RESEARCH_BACKEND_ENABLED`
   (default-OFF). Seed of state-of-the-art findings: `docs/engineering-knowledge-base/archive/source-material/
   loop-research-seed-2026-06-21.md`. **This is the internet-research capability the operator wants — see
   §5.5.**
7. **C1 investigated → DEAD-END** (documented in the seed): equivalent-mutant handling in the kill-ratio gate
   is moot (the gate is off-by-default, floor 0.0) and the only valuable form is a Goodhart gaming hole. Do
   not re-pursue.

---

## 5. The open engineering problems (YOUR real work — prioritized)

### 5.1 Make the honest value signal AIRTIGHT (mission technical objective #1)
The known structural risk: `AtlasLoopPatternDecisionDriver::driverDescriptorFor()` only vetoes when the
candidate packet ALREADY carries `cosmetic`/`proxy`, but `gather()` historically almost never stamped those on
**real** packets → the driver becomes "governance veneer" (`[pattern-driver…]` seal with no real veto). I added
the stamping; your job is to **prove it bites real proxy on REAL packets generated by the real path** (not
synthetic arrays). Rule to enforce: a refactor candidate with no RED-anchor, no capability change, no value-
axis relief, and no material proof must tend to `proxy=true`; *absence of reliable impact ⇒ rejection, not
approval.*

### 5.2 Prove coverage can NEVER dominate when material supply runs dry
The loop's failure mode: it does the easy material work, then **degrades to coverage-only** (observed live:
recent grinds 96% coverage vs 68% substantive early — 0 proxy/cosmetic, but coverage ate the campaign). The
relative coverage cap + the decompose lane address it, but you must **prove material dominates** structurally —
the loop should always prefer real evolution over padding when real work exists (and 131 material methods
exist, so it does).

### 5.3 The decompose lane end-to-end + the test-anchor gap
The material-supply lane mints real refactor tasks, but a refactor needs a **behavior anchor (a sibling test)**
to certify — and the lane currently can mint for files **without** a sibling test, which cannot certify (the
grind fails at the acceptance baseline with 0 work done). Fix: **only mint decompose refactors for files that
have a sibling test** (17 of the multi-method complex files do; 12 don't), OR have the lane synthesize a
characterization anchor first. Then prove a decomposed material refactor goes choose→execute→**certify**→
measure.

### 5.4 The multi-file refactor lane bug (pre-existing)
`AtlasLoopMultiFileRefactorLaneTest` has 3 failures (`test_both_flags_on…` expects `'enqueued'`, gets
`'deferred'`; 2 vacuous "risky" tests). **Confirmed NOT a regression** from this session (it fails even with
the material-supply gate disabled) — the multi-file obra-cluster lane (`refactor_multi_file_via_obra`, Path B,
2026-06-15) either doesn't fire or the synthesized task isn't live-for-target. Investigate
`AtlasLoopQueueRefiller` ~line 996-1029 (the cluster lane) + `completeTargetEnqueue`'s `taskIsLiveForTarget`.
A working multi-file lane is real supply (coupled-cluster refactors = "redução real de acoplamento").

### 5.5 Internet research — COMPLETE it (the operator's explicit requirement)
> "O loop deve ser capaz de fazer pesquisas na internet quando for necessário… quando ele não tiver mais o que
> entender, o que ele não entende ou que ele precisa, ele pesquisa."

The foundation (§4.6) is built and fail-closed. **The engine the loop grinds on (Hermes) has NATIVE
`browser` / `web` / `web_extract` / `session_search` toolsets** — so the loop CAN research the web autonomously
during a grind, no human, no extra API key. Your job: wire the live path so that **when the loop lacks the
understanding/info to land an evolution, it researches** — (a) a trigger ("I don't understand X / I need the
state-of-the-art for Y"), (b) `AtlasLoopResearchOriginator` mints a RED-gated research objective, (c) the grind
researches via Hermes's browser, (d) the result must still earn its own failing-test-first proof (the research
note is advisory, never proof). Keep the egress filter + source-quarantine intact (sovereignty: nothing about
the repo leaks out).

### 5.6 The real end-goal: full autonomy + "understanding what to do"
The hardest, highest-leverage gap: the loop must **originate** the right evolution greenfield — understand its
own scope deeply enough to decide *what new feature / evolution / new level* would make it most capable, not
just refactor what's already complex. This is the frontier (per the canonical pipeline: "frontier projection +
cross-model critique in a loop"). The decompose/coverage/refactor lanes feed *known* work; the leap is the loop
**deciding** the work. Push here: better objective origination, the projection/critique loop, multi-agent
decomposition of a big evolution into provable pieces.

---

## 6. Rails — constraints you must never break

- **Anti-Goodhart above all:** never lower the bar, never let coverage/proxy dominate, never game the
  cert/scorecard. A refactor that preserves behavior with no material proof is worth nothing.
- **propose-only / no-auto-merge** while testing, unless the operator explicitly says otherwise.
- Use `/opt/homebrew/bin/php` (PHP 8.5) and `./vendor/bin/phpunit -d memory_limit=4096M`.
- Loop tests need a focused-migration `setUp` (require `database/migrations/2026_06_02_000100` + `000200`
  `->up()`); tasks need `dedupe_key` NOT NULL.
- Do NOT `git reset --hard`. Do NOT delete human work. No repair migrations. Never hand-stamp `migrations`.
- The scope under test is **only** `app/Services/Ai/AutonomousEvolution/` — do not widen it (the operator
  explicitly rejected "increase the scope" as a cop-out; the depth is *here*).
- Before architectural work, consult the Atlas brain (MCP `atlas-open-brain` / context pack) — it is the
  canonical context, not a blind `grep`.

---

## 7. Where to start

1. Read §1's canonical docs + the `loop-*` memories until the **purpose** is unambiguous in your head.
2. Walk the §3 pipeline in the actual code (open the 8 key files) until you can trace one evolution end-to-end.
3. Run the loop's own unit/feature tests for the Discovery + Verify dirs to see green vs the known reds (§5.4).
4. Pick the highest-leverage gap from §5 — **§5.5 (internet research) and §5.6 (origination/autonomy) are the
   ones that most move the loop toward "fully autonomous in its own scope."** §5.1/5.2/5.3 harden what exists.
5. Work the canonical way: understand → project the evolution + critique it cross-model → implement → test →
   verify the wiring. Commit-by-piece. Prove each piece is real value, not proxy.

**Definition of done (the operator's bar):** live evidence that the loop can autonomously **choose, execute,
certify and measure real-value work in its own scope, without falling into proxy/cosmetic/coverage-only
domination** — and research the internet when it needs to. Not "a base is built." Not "tests pass." *The loop
demonstrably evolving itself, well.*
