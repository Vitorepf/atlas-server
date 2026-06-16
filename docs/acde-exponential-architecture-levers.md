# ACDE — Master build list: exponentially-self-improving, MiniMax-only loop

Goal: a loop ARCHITECTURE so powerful that — using ONLY MiniMax-M3 — it does extremely huge & complex
refactors, implementations, and ORIGINATES huge proposals, at a per-delivery quality better than Opus-4.8 with
a dynamic workspace, AND evolves in the N×M direction: running 100% autonomously 24/7 so that each hour
capability(t) bends ever more sharply upward (super-linear, not linear).

Produced by a multi-agent design pass (8 capability families mapped against the real code + the Factory AI
plan; 31 candidate levers → 21 survived adversarial verification; 2–3 true curve-benders). Every lever is
default-OFF / byte-identical-OFF, MiniMax-only, PHP+phpunit, never re-arms the blocking obra-DAG blindly, and
never lets the engine grade its own bar (pétreo floor). Evaluation is PER DELIVERY (dossier) — Rivals/head-to-
head are dead (see acde-teto-closure.md).

## The one-sentence thesis

Only THREE things can make capability(t) super-linear: a **write-side** that turns certified deliveries into
reusable bar/context, a **read-back** the next delivery consumes, and a **merge-authority** that lands self-
improvements at machine speed. Everything else is FOUNDATION (a seam) or a LINEAR ceiling-raise. **The multiplier
is the closed write→read PAIR, never the half — build pairs, not halves.**

## A. The complete lever list (by family)

Class: `FOUNDATION` (seam, unblocks others) · `LINEAR` (one-time ceiling-raise) · **`MULTIPLIER`** (bends the curve).

### Family 1 — brain-context (the window the weak engine edits against)
- **B1-fast** `LINEAR` S — add the two existing flag-gated seams (code-graph signatures + dependency bodies) into `buildFixPrompt` directly (~10 lines). Today `buildFixPrompt` has ZERO brain — it evaporates on exactly the iterate-to-green retries where MiniMax is failing. Biggest first-order win, instant.
- **B1** `FOUNDATION` M — extract the driver's OWN `codeGraphContextLines`/`dependencyBodyLines` helpers into one `AtlasLoopBrainContextComposer` serving both `buildPrompt` + `buildFixPrompt`. (NOT a lift of `AtlasOpenBrainContextInjectionService` — that needs a full task/contextPack + does an audit write.)
- **B2** `LINEAR` M — global prompt budget + drop-priority ranking (ranked brain > scoped file bodies); fix the budget-source drift (`codeGraphContextLines` hardcodes DEFAULT_BUDGET; `currentFileContents` dumps 60k chars/file with no aggregate cap → swamps the small window on a many-file obra). Raises the obra-SIZE ceiling.
- **B3** `LINEAR→MULTIPLIER*` M — provider-safe operator-memory recall into the loop window (the M-side read-back). Multiplier ONLY once B4b emits provider-safe learnings at a useful rate.
- **B4** M — (a) `LINEAR` wire `AtlasLoopBlastRadiusAnalyzer` (fully orphan) via `CodeGraphAdjacencyIndex::incoming` (reverse reachability) → inject {blast_radius, consumers, risk}. (b) **`MULTIPLIER`** certified-delivery brain-feedback recorder: on certify, record proven {dependency-contract, consumer-set} as provider-safe memory candidates (needs a NEW `toMemoryCandidates` transform). (b) is the write-end B3 reads.

### Family 2 — huge-refactor (Path B, DAG-free, composed across waves)
- **R1** `FOUNDATION` M — durable in-lane extract-sequence STATE: wire `AtlasLoopExtractSequencePlanner::nextStep()` (dead outside tests) to drive step N+1 from the live re-measured per-method census. **MUST ship the bare-name↔FQ-name identity shim** (`objectiveText()` keys bare + doc-order; `nextStep()` keys FQ + lexicographic — they diverge on ties; without the shim, byte-identical-OFF is a lie).
- **R2** `FOUNDATION` M — in-lane decomposition-outcome corpus: express each completed sequence as `{nodes:[...]}` → `AtlasLoopDecompositionShapeFingerprinter` → append {fingerprint, certified, rounds} to the SAME corpus the DAG uses. Write-only until R2-read.
- **R2-read** **`MULTIPLIER`** M — the in-lane planner reads the prior (`history()`/`ShapePrior`) to pick worst-first ordering + tractable threshold for a recurring shape. **The cheapest real curve-bend in the whole list** (write-side nearly built; only the read is missing).
- **R3** `LINEAR` L — cross-file extract sequence (budgeted multi-step create-class, distinct `Support2/3` per step). ~90% already ships (commit 9c5c86e3); delta is merging two OFF branches + per-step naming. Blind spot: structural cert checks complexity+anti-relocation, NOT design cohesion.

### Family 3 — huge-feature (new behavior, DAG-free)
- **F1** `LINEAR` L — in-lane sequenced-feature planner: one huge feature → ordered chain of small `feature` steps, each with its OWN **human-frozen** sub-acceptance (an `IntentVerifierFactory` atom-subset). CEILING: a feature's per-step bar has no code-intrinsic re-measure anchor — it MUST be human-frozen, never loop-authored.
- **F2** `LINEAR` M+ — feature completeness checklist resolver (one falsifiable criterion per verification atom); needs the atom-monolith split into N single-atom test files. Lifts dossier honesty (the single command already pass/fails on all atoms).
- **F3** `FOUNDATION→MULTIPLIER*` M — per-feature HMAC-signed delivery dossier + feature-outcome ledger. Substrate; compounds when origination reads it back. (Use D2, NOT the dead Rivals command's private resolvers.)

### Family 4 — origination (the loop proposes the WHAT)
- **O1** `FOUNDATION` M — in-lane origination producer (spec-only): target-select → author falsifiable spec → readiness-gated DAG → propose-only dossier in a branch, NEVER `executeAndProve`. Spec authors STRUCTURE only (criteria seed `decomposition_hint`, never the frozen `payload.acceptance`).
- **O2** **`MULTIPLIER`** M — origination-outcome ledger: operator ACCEPT/REJECT → family-token-keyed deterministic prior that sharpens the next authored spec, fed into `PlanReadinessGate` as an advisory REPLAN band. **The M-side human signal — the only ground truth for origination quality** (rate-bounded by review cadence).
- **O3** `LINEAR` (DAG-gated) L — certified-delivery → candidate-archetype harvester + human-only `freeze:promote`. CAVEAT: rich multi-node archetypes only exist when the DISABLED DAG runs; live Path B yields trivial 1-node archetypes. Floor-safe foundation, not an autonomous multiplier.

### Family 5 — dossier / proof (per-delivery, replaces dead Rivals)
- **D1** `FOUNDATION→MULTIPLIER*` M — per-delivery dossier outcome ledger + capability-trend read-model (time-indexed clean-delivery-rate + mean-cert-dim SLOPE over rolling 24h buckets, surfaced in the morning digest). **The ONLY instrument that answers "is capability(t) actually bending upward."** Reuses Wilson-LB as a SELF-trend, never engine-vs-engine.
- **D2** `LINEAR` S — shared Rivals-free dossier dimension resolver (extract `score()`-half into a clean service; one machine-resolved definition for every dossier/extractor). Replaces the refuted "reuse DqsExtractAce private resolvers."
- **D3** `LINEAR` (hygiene) S — retire/quarantine the dead Rivals head-to-head surface. Sequence LAST (after D1/F3 exist) so no no-proof window opens.

### Family 6 — compounding self-improvement (the loop builds the loop)
- **S1** `LINEAR` M — corpus-conditioned authoring bridge (LossObserver bottleneck → shaped self-improvement spec). Only fires after R2-read gives single-file outcomes a non-DAG key (else `ShapePrior` returns UNKNOWN ~always). `AtlasLoopSelfImprovementObjectiveBuilder` has ZERO callers today.
- **S2** **`MULTIPLIER`** (rate, highest-risk) L — plug the EarnedAutonomy R0..R4 door into the live self-improvement merge path: `is_self_improvement` → `RsiInvariantGuardService` (screen first, fail-closed) → `EarnedAutonomyGateService.decide` (R1 hard-caps any gate/invariant touch from EVER auto-applying; kill-armed; drift-clean; red-team-survived). **Removes the operator-bound RATE ceiling that clamps compounding to human review cadence.** Ship LAST, with a frozen test proving a pétreo/gate-touching diff can NEVER reach auto_apply.

### Family 7 — frozen-ammunition (the bar grows from the loop's own wins)
- **A1** `LINEAR` (medium curve) M — certified-delivery → candidate-archetype/contract harvester (per-file interface contracts AND typed archetypes → `frozen/_candidates/`). All three magazines are empty today; every consumer fail-opens; the moat raises nothing. But the single-file merge seam can only honestly produce FLAT goalHash contracts; by-class archetypes need a multi-node source (the DAG).
- **A2** `FOUNDATION` (DAG-gated) S — human-gated `freeze:promote` CLI (model proposes, human signs). Inert until the obra-DAG is re-armed.

### Family 8 — 24/7 autonomy throughput (convert weak engine → width)
- **T1** `LINEAR` S — arm scenario-level best-of-N fan-out (`ScenarioWaveDispatcher`, width 4): N overlapped tries hide the ~14min/attempt latency behind width. Already wired+tested. Build work: the no-double-claim flip + close the within-wave workspace-cap gap (`admitScenario` reads a stale glob count per spawn) + measure that flag-ON quality ≥ serial.
- **T2** `LINEAR` S — supply-rate coupling: widen the discovery candidate caps in lockstep so width has work to chew. The one shippable half of the refuted "couple discovery to fan-out."
- **T3** `LINEAR` (NOT a multiplier) M — closed-loop self-tuning width controller. Width SATURATES at supply depth — supply, not width, is the bottleneck; past the frontier it yields ZERO extra certified deliveries. Linear.

## B. The exponential flywheel — exactly THREE closed write→read loops

The curve bends ONLY where a certified delivery's own output flows BACK to make the next delivery/lever better.

1. **Refactor loop:** R1 → R2 (write shape→cert-rate) → **R2-read** (consume to order the next sequence) → more certified rows → better prior. *The cheapest, safest curve-bend — its bar is code-intrinsic (cyclomatic), so it self-corrects for free.*
2. **Origination loop:** O1 → F3 (write feature-shape→outcome) → **O2** (operator accept/reject sharpens the next spec) → more accepts → more rows. *Human judgement is the training signal; rate-bounded.*
3. **Self-construction RATE:** R2-read + B3 feed a certified self-improvement stream → **S2** (EarnedAutonomy door) lands them at machine speed → better loop → higher cert-rate → … *Not a per-delivery multiplier; it removes the human-review RATE CEILING so loops 1+2 compound in wall-clock.*

Brain pair (B1→B4b write, B3 read) is a fourth, weaker loop (provider-safe learned contracts → richer window).

**The TRUE M× multipliers: R2-read, O2, S2.** Everything pitched as "multiplier" that is only a write-side or a
read-side is a FOUNDATION/LINEAR until its partner ships. Linear enablers: B1-fast, B2, R3, F1, F2, D2, T1, T2,
T3, S1. Foundations: B1, R1, R2, O1, F3, D1, A2.

## C. Build sequence

- **Wave 0 — cheap, zero-coupling (today):** B1-fast (10-line buildFixPrompt grounding) · T1+T2 (arm fanout + widen supply) · D2 (extract Rivals-free dim resolver).
- **Wave 1 — close the FIRST flywheel (refactor, the family that already delivers):** B1 → B2 · R1 (with the identity shim) → R2 → **R2-read**. Checkpoint with D1: do same-shape refactors land faster wave-over-wave? **If the curve doesn't bend on the easiest family, stop — the thesis is wrong.**
- **Wave 2 — brain read-back closes:** B3 + B4b + B4a.
- **Wave 3 — origination + proof:** O1 → F1/F2/F3 → **O2** · D1 (the trend instrument).
- **Wave 4 — self-construction RATE (last, highest-risk):** S1 (only after R2-read) · **S2** (with the frozen never-auto-apply test).
- **Deferred / DAG-gated (do NOT build until the obra-DAG block is diagnosed):** O3, A1, A2, T3 — they harvest/gate multi-node structure that only exists when `planning_enabled` is ON.

## D. The brutally-honest ceiling (irreducibly model-bounded)

Architecture multiplies what the engine can already do and compounds it across deliveries. It CANNOT manufacture
three things; claiming otherwise is Goodhart theater:

1. **Greenfield origination CORRECTNESS.** O1 authors a falsifiable spec; O2 learns which SHAPES the operator
   accepts. Whether a NOVEL decomposition is the RIGHT one rides the model. The readiness gate degrades to
   structural-only with no frozen artifact, so it cannot false-reject a bad-but-well-formed spec. The only
   honest verifier is operator ACCEPT/REJECT + eventual execution against a frozen bar — both rate-limited.
2. **Semantic correctness on greenfield behavior + DESIGN cohesion.** The pétreo cert proves behavior-
   preservation and red→green against a frozen bar; it does NOT prove a NEW behavior is what was wanted, nor
   that a decomposition is well-DESIGNED (a god-class shredded into 5 anemic classes certifies). Turning the
   floor into a design oracle needs an LLM judge — forbidden.
3. **The frozen bar itself for novel features.** If the loop originates a feature AND writes its own
   verification atoms, the bar is loop-authored — the floor is breached. The human-frozen bar is the
   irreducible input for features; the refactor lane is luckier (its bar is code-intrinsic, self-correcting).

The super-linear curve is REAL for delivery quality, grounding, throughput, and self-improvement RATE — the N×
capture and the M× compounding of KNOWN-SHAPED work. It is BOUNDED at originating correct novel intent, which
rides the model. Build the three flywheels, prove the bend on the refactor family first, and never let the loop
sign its own bar.

## E. obra-DAG block — DIAGNOSIS (the deferred-levers prerequisite)

The 4 deferred levers (O3, A1, A2, T3) were gated on "diagnose WHY planning_enabled blocked the loop." Done —
read against `AtlasLoopTaskGrinder::maybeRouteMultiFileRefactorToObra` (lines 249-337) + the decompose tier
(line 829) + the empty `frozen/obra-*` magazines. The block is **ARCHITECTURAL, not a bug**:

1. **The multi-file hard-route is TERMINAL-but-NEVER-AUTO-MERGES.** A multi-file `refactor_*` (≥2 files) routed
   to the obra bridge ends at `ready_for_operator_review` → `completeTask(...,true)` with "operator review
   required before any merge" (grinder:321-327). In autonomous 24/7 mode big refactors therefore PILE UP in
   the operator-review queue and never land — the loop cannot self-deliver big work. **That is the "block."**
   Path B (`multi_file_refactor_via_normal_lane`, which short-circuits this route at grinder:257-258) was the
   fix precisely because the normal grind lane auto-merges certified work.
2. **`planning_enabled`'s `executeAndProve` needs ammunition that does not exist.** The decompose tier's DAG
   path (grinder:829-843) runs the heavy `AtlasLoopObraExecutionAdapter::executeAndProve` (worktree-per-node,
   readiness-gated) — but all three `frozen/obra-*` magazines are EMPTY, so the readiness/boundary/archetype
   gates fail-open and the "decomposition" degrades to structural-only while paying the heavy DAG cost.

**Implication for the deferred levers (HONEST):**
- **O3 / A1 (multi-node archetype harvesters):** rich multi-node archetypes EXIST only when `executeAndProve`
  runs the DAG — and the DAG parks to never-auto-merge operator review (#1). So harvesting multi-node structure
  autonomously is **blocked by governance, not effort**. The in-lane Path B (R1/R2) yields only FLAT,
  single-file, goalHash-keyed contracts — honest, but not by-class archetypes. **Verdict: O3/A1 should derive
  FLAT per-file contracts from the in-lane sequence and STOP there; the by-class archetype is deferred-
  indefinitely unless the operator makes a policy decision to give the obra-DAG a *governed auto-merge* path
  (which contradicts today's never-merge invariant) — that is an operator call, not a code lever.**
- **A2 (freeze:promote CLI):** safe to build as the human-signature chokepoint, but it only has multi-node
  candidates to promote once #1 is resolved; until then it promotes the FLAT in-lane contracts (A1).
- **T3 (width controller):** independent of the DAG; deferred only because width saturates at supply (it is a
  linear ceiling-raise, not a multiplier) — build it last, after the supply frontier is actually hit.

**Net:** the exponential program does NOT require re-arming the blocking obra-DAG. The refactor flywheel
(R1→R2→R2-read) and the origination flywheel (O1→F3→O2) run entirely IN-LANE. The only thing the DAG would add
is by-class multi-node archetypes — and that is gated on an operator GOVERNANCE decision (auto-merge vs never-
merge for big obras), not on more engineering. Recommend: build everything in-lane; leave O3/A1's by-class
archetype + A2's multi-node promote for an explicit operator policy call on obra auto-merge.
