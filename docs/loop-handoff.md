# Atlas Loop — Engineering Handoff (make the loop perfect at its OWN scope)

> Handoff written 2026-06-21 by the previous Claude. You are taking over the **engineering of the Atlas Loop**.
> **You do NOT run the loop.** You **structure and improve** it so it becomes *perfect and fully autonomous*
> at evolving its own scope. Nothing here is about launching campaigns, engines, or babysitting a live run —
> all of that is deliberately omitted.

---

## 0. The Loop — zero-ambiguity definition (read until there is NO doubt)

This section exists so there is **no possibility of confusion**. Four questions, answered flatly. If anything
below ever seems to conflict with a habit or a shortcut, **this wins**.

### 0.1 — What the Loop IS
The **Atlas Loop** (a.k.a. **AutonomousEvolution / ACDE**) is **Atlas's single, sole, autonomous software
engineer**. It takes a SCOPE of code and **evolves it to the maximum possible level, exponentially, by itself,
running 24/7** — with **NO human and NO external tool (Claude Code / Codex / Cursor / Factory / Gemini)
reviewing, driving, or correcting it**.

It is meant to be **THE ONLY THING that engineers Atlas.** Not a helper, not a linter, not a one-off batch job,
not a "Claude with extra steps". The end-state: *the Loop is the one entity that builds, fixes, refactors,
evolves, and documents Atlas — alone, forever, around the clock.*

**THE AMBITION FACULTY (this DEFINES the Loop — never err on it; the operator insisted on it many times):** a
scope given to the Loop has **NO CEILING**. Improving the Loop means improving an *intelligence* — recursive
and OPEN-ENDED (you can always make a mind smarter, a defense more resilient, an architecture more elegant). A
dead/static app has a ceiling; **the Loop's own evolving mind does NOT.** A Loop with a defined scope **LIVES
that scope, forever raising its level** — it exists *for the scope to evolve ALWAYS*. A DUMB loop stops when the
obvious *reactive* work runs out ("I finished my list"); an INTELLIGENT loop reaches its limit and **SURPASSES
it** — when reactive work dries, it asks *"how could I be fundamentally more powerful / intelligent / resilient
/ autonomous than I am now?"* and **ORIGINATES the next leap of greatness** (real, proven work — running out of
trivial reactive work is the TRIGGER to raise the horizon, NEVER a license to fake). Canonical: the
`loop-ambition-faculty` memory + `docs/loop-canonical-definition.md`.

It is **NOT** (zero tolerance — an earlier AI drifted into this for days): micro-editing, whitespace/cleanup,
proxy optimization (landing-rate / cyclomatic-count / test-count / coverage %), coverage-padding,
planning-without-building, or one-shot attempts. **A behavior-preserving refactor with no PROVEN material
improvement is worth ZERO.**

### 0.2 — What the Loop SHOULD DO (its job, concretely and completely)
Given a scope, the Loop does ALL of this, continuously, on its own, **24/7**:
- **Understands** the scope and what would genuinely make it better.
- **Finds what to improve** — and when it does **not** know, does not understand something, or lacks the
  information/knowledge it needs, it **RESEARCHES THE INTERNET**: patterns, **public repositories**, papers,
  state-of-the-art **implementations**, libraries, prior art — **anything that helps it become better**.
  Internal discovery alone is NOT enough; the Loop reaches OUT for knowledge whenever it hits the edge of what
  it knows.
- **Implements everything**, within scope: new **features**, **evolutions**, **new levels/patamares** of
  capability, robust **refactors**, real **bug fixes**, and **any fix** — *tudo do Loop*.
- **Updates the documentation** to match every change it lands (docs are part of the deliverable, never an
  afterthought).
- **Tests, certifies, measures, and commits** each piece honestly — commit-by-piece, never one big dump.
- **Never stops** at "looks better", "tests pass", or "a base is built" — it keeps going, around the clock.

### 0.3 — How the Loop SHOULD BE
- **Fully autonomous** — zero human/external review in the steady state. It governs itself.
- **Exponential** — it doesn't only do work; it makes **itself better at doing work** (it evolves its own
  ability to evolve), so the curve bends UP over time. Flat ≠ acceptable.
- **Honest / anti-Goodhart** — its gates **actually reject** proxy / cosmetic / behavior-breaking work; it
  never games its own cert or scorecard. Real value or nothing.
- **Sovereign + local-first** — it reaches OUT for knowledge (research), but **nothing about the repo leaks
  out** (egress-protected). Atlas runs on the operator's machine and owns its own brain.
- **Fast** — the best evolution in the **least time**.

### 0.4 — The Loop's OBJECTIVE in Atlas
Atlas is the operator's **sovereign personal AI** (local, on his Mac). Providers (Claude Code, Codex, etc.) are
**rented muscle**; Atlas is the **brain**. **The Loop is HOW Atlas engineers itself.** The objective:
**the Loop becomes the ONLY thing that evolves Atlas** — it programs, fixes, refactors, evolves, levels-up, and
documents the entire system, **24/7, alone**, with no human and no external tool in the loop. Scope is unlocked
**one block at a time, starting with the Loop's OWN code**, and expands **only as the Loop earns proven
confidence** (see §9). When the Loop can fully and honestly do its whole job in its own scope, it has proven it
can be trusted to do it anywhere.

---

## 0.5 Your mission (the next Claude — the engineer of the Loop)

You are NOT running the Loop. You are **building/structuring/perfecting the Loop as a system** so it becomes
everything in §0 — starting with **Block 1: its own code scope** `app/Services/Ai/AutonomousEvolution/`
(244 PHP files — the Loop's own machinery). Concretely, make the Loop able to, by itself: understand its scope →
pick the highest-leverage evolution (not the easiest) → research the internet when it lacks knowledge →
implement (feature / evolution / new level / robust refactor / bug-fix) → update docs → test + certify + measure
→ commit — in the least time, commit-by-piece, with zero human/external review, **24/7**, getting better as it
goes. Ask yourself constantly: *what is structurally missing or weak that stops the Loop from autonomously
finding-and-landing REAL evolutions (not proxy) of its own code?* — and build that.

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

## 3.5 Concrete examples (make it tangible — GOOD vs BAD, end-to-end)

Illustrative, not exhaustive. They draw the exact line so there is no doubt what "real value" means.

### Example A — ONE complete real evolution, end-to-end (the ideal single iteration)
*This is what one good Loop cycle looks like; the Loop does a continuous stream of these, 24/7.*
1. **Understand** — the Loop measures its own scope and sees `AtlasLoopSemanticImplementationCertifier` has a
   method at **cyclomatic ~66** (a god-method): high coupling, the kind of thing that makes every future
   evolution riskier.
2. **Identify (highest leverage)** — simplifying that method beats padding a test somewhere: it's the cert's
   core, so cleaning it makes everything downstream safer to evolve. (Pick the most exponential, not the
   easiest.)
3. **Research (only if it doesn't already know how)** — if unsure of the cleanest pattern, it **researches the
   internet**: "replace conditional dispatch with a lookup/strategy table in PHP", and how mature OSS
   certifiers/validators structure similar logic (**public repositories**). The note is advisory — never proof.
4. **Implement** — extract the branch dispatch into a lookup table + branch-free helpers, dropping the worst
   method below 12 **while keeping the file's TOTAL decision count flat**. (Hiding branches in
   `in_array(true, [...])` arrays = laundering = **rejected**, see §2.)
5. **Test (RED-first)** — a failing-test-first anchor pins the exact prior behavior, so the refactor MUST
   preserve it.
6. **Certify** — the cert re-measures the AST complexity drop + the mutation gate proves the test isn't empty
   and behavior is preserved.
7. **Measure** — the scorecard classes it `self_improvement` (material); `proxy=0`, `cosmetic=0`.
8. **Document + commit** — update the method's docblock / any design doc describing the old shape; one focused
   commit. → repeat, forever.

### Example B — the internet-research reflex (the Loop reaching OUT for knowledge)
The Loop wants a capability it doesn't fully know how to build — say a new **mutation operator** that catches a
bug class its current operators miss. It does NOT guess: it **researches** — "mutation testing operators
state-of-the-art", how **Infection (PHP)** and **Stryker** implement them (their public repos), recent papers —
brings back an advisory, source-quarantined note, then **implements the operator + a RED test proving it kills
a mutant the old set missed**, and certifies. *That* is "pesquisar na internet quando precisa" — patterns,
repositories, anything that makes it better.

### Example C — REAL value vs PROXY (the exact line — memorize it)
| ✅ REAL value (the Loop SHOULD do) | ❌ PROXY / not-value (the Loop MUST reject) |
|---|---|
| Extract a cx40 god-method into a dispatch table; total decisions flat; behavior pinned by a RED test; cert proves the drop → `self_improvement` | Rename vars / reformat / "reduce cyclomatic" by hiding guards in `in_array(true, [...])` (laundering) |
| Fix a real bug (e.g. a wrong null-guard) with a RED test that fails before & passes after → `bug_fix` | Add a characterization test on a trivial file to bump coverage % **while real work exists** → coverage-padding |
| Add a NEW capability (e.g. a research-backed mutation operator) that catches bugs it couldn't before → `feature` | Write a roadmap/plan doc without implementing anything |
| A coupled ≥2-file cluster refactor that cuts real cross-file coupling, behavior proven | A behavior-preserving refactor with **no proof** the code got materially better |

### Example D — a "new level / patamar" (the exponential leap — not just a refactor)
The biggest moves are **capabilities that make the Loop better at its own job**, not refactors of existing
code. E.g.: wiring the **internet-research lane** so the Loop originates research-backed evolutions (§5.5); or a
stronger **origination engine** so the Loop *decides* higher-leverage work instead of only refactoring what's
already complex (§5.6). These bend the curve UP — the Loop evolving its own ability to evolve. This is where the
"exponential" in §0.3 actually comes from.

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

---

## 8. Definition of COMPLETE — exactly when the loop is "done" in its own scope

The loop is **complete/finished within its own scope** (and *only then* earns the next block) when ALL of A–E
hold under a **skeptical audit of a sustained live campaign** on `app/Services/Ai/AutonomousEvolution/`. This
is the bar. "A base is built", "tests pass", "it ran once" are explicitly NOT complete.

- **A — Full cycle, autonomous, repeated.** The loop runs a campaign with **no human and no external (Claude
  Code/Codex) review** and produces a **continuous stream of CERTIFIED real-value evolutions of its own code**
  (choose → execute → certify → measure → commit → repeat). A *stream*, sustained — not a single landing.
- **B — Real value DOMINATES; proxy/cosmetic/coverage never take over.** Over the campaign, measurably:
  `proxy = 0`, `cosmetic = 0`, and **substantive (bug-fix / material-refactor / self-improvement / feature) ≥
  verification (coverage/characterization)**. The scorecard's "real work" claim survives an adversarial audit
  — no laundered proxy, no coverage masquerading as evolution.
- **C — Never stuck, never busywork.** When the obvious work runs out, the loop does NOT idle or coverage-pad —
  it **researches the internet** for what it lacks and **originates** the next real evolution. Supply never
  decays into proxy.
- **D — It gets BETTER over the run (exponential, not flat).** The loop's own capability **improves measurably**
  across the campaign (higher real-work throughput, better origination, fewer wasted grinds). It is evolving
  *itself* into a better evolver. A campaign with the same capability start-to-end is NOT complete.
- **E — Self-governed honesty holds under attack.** Every gate (frozen judge, mutation cert, scorecard,
  pattern-driver veto) **actually bites**: a skeptic trying to slip proxy / cosmetic / behavior-breaking work
  through is **rejected**. No gate is governance veneer.

If a campaign passes A–E under a skeptical audit → **the loop is complete in its own scope.** That is the only
"finished".

---

## 9. The strategy: confidence-gated BLOCK expansion (the meta-plan — do not skip blocks)

> Operator's directive: *"começar com o próprio loop e ele garantir que consegue tudo o que ele deve fazer
> dentro do próprio loop, e ir aumentando os blocos aos poucos enquanto ele garante confiança."*

1. **Block 1 = the loop's OWN scope** (`app/Services/Ai/AutonomousEvolution/`). Make the loop COMPLETE here
   (§8 A–E), proven by an **audited live campaign**. This is the entire job right now — nothing else.
2. **Only when Block 1 is proven-with-confidence** — audited live evidence, never "it worked once" — do you
   widen to **Block 2** (a small adjacent scope). Each block is **EARNED by proof**, not assumed.
3. **Confidence = audited live evidence, never optimism.** A loop that games its own gates or coverage-pads has
   *not* earned the next block — it has proven the opposite, and that's the signal to harden, not expand.

**Why this exact order:** a loop that cannot reliably and *honestly* evolve the very code it is built from
cannot be trusted with anything larger. Proving the smallest, most-instrumented scope first turns every later
block into a clean multiplier on a **trusted base** instead of a gamble. Expand only on earned trust.
