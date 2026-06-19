# THE ATLAS LOOP AS A LIVING OS — Architecture & Build Spec (canonical)

> **Status:** CONVERGED at the architecture level after a 4-round adversarial projection (draft → 3 hardening rounds, each independently code-verified). This is the build spec for **v1 = the loop bootstrapped on its OWN scope** (`app/Services/Ai/AutonomousEvolution/`), the first rung of the territory ladder.
>
> **Canonical sources this consolidates** (kept as the audit trail; this doc supersedes them): `docs/loop-os-architecture.draft.md` (R1 projection), `docs/loop-os-architecture.r2-findings.md`, `docs/loop-os-architecture.r3-findings.md`. Loop definition is governed by [`docs/loop-canonical-definition.md`](loop-canonical-definition.md) + memories `loop-*` + `loop-os-architecture-decisions`.
>
> **Code root:** `app/Services/Ai/AutonomousEvolution/` — PHP 8.5, `./vendor/bin/phpunit`, `/opt/homebrew/bin/php`.
> **Tags:** **[REUSE]** unchanged · **[REUSE+EXTEND]** existing class, additive change · **[REUSE+WIRE]** existing orphan, wire it in · **[NEW]** new class. Every load-bearing fact below was verified against live code this session.

---

> **⚠️ BUILD STATUS — this is the DESIGN, not the artifact.** 0 of the slices are implemented; the `Constitution/` subtree does **not** exist yet; the live loop today auto-merges to `main` with `flock=0` and no post-merge revert. "DESIGNED" below means buildable-from-this-spec, **not** built. A capability-completeness audit (§11) found 6 real capability gaps (chiefly **performance**, end-to-end undesigned) now folded in. The phased roadmap to full rung-0 autonomy is **§12**.

## 0. The honest convergence boundary (read first)

A self-modifying engineer that can rewrite its own judge sits next to the Gödel/halting boundary: **no paper proof can guarantee "the loop never goes blinder."** Any doc claiming otherwise is lying. What CAN be guaranteed, and what this design delivers, is a **mechanism** with a **build-time discharge**:

- The **mechanism** (the Constitution) is specified airtight here: a frozen, FORBIDDEN trusted base runs the *candidate's actual bytes* against a frozen battery and scores only *externally-observable* effects; any candidate that lets a known-bad through, or that goes blinder than the frozen battery, is REJECTED — while a strictly-better judge PASSES.
- The **proof** that a given build actually holds is **discharged at build time** by a frozen, FORBIDDEN **sentinel meta-test suite** (§3.6) that must be green before the self-edit door is ever re-opened. "Airtight" = *that suite is green over the complete, reflection-derived cert-chain closure.*

So: the architecture is converged (the mechanism is correct and every hole found across 4 rounds is closed in the mechanism). The residual is an **implementation obligation**, not a design furo: build the trusted base and its sentinel suite, prove it green, then re-open the door. This boundary is the correct, honest end-state — not a weakness.

**What rounds R2–R4 of adversarial verification caught (R1 = the draft projection; all real, all code-confirmed) — recorded so no future AI re-opens them:**
1. **R2:** the premise was *inverted* — the loop's tree is NOT forbidden; it is already auto-merging self-edits every 60s. The Constitution must **close a live door**, not open a shut one.
2. **R3:** the naive trusted base was **vacuously green** — a symlinked `vendor` makes PHP autoload the *live* judge, never the candidate's bytes (reproduced by execution). Fix: isolated clone-local autoload (verified working).
3. **R4:** protecting **one file** ≠ protecting the **judge** — the certifier delegates its verdict to a chain of non-forbidden collaborators; and the safety flags span **two disjoint config namespaces**. Fix: protect the **transitive closure** + **both namespaces** (enumerated below).

---

## 1. Purpose & the 4 locked decisions

The Loop becomes the **sole, autonomous, exponential self-engineer of Atlas** — it understands a scope's true objective, projects the most-leveraged evolution under a persistent cross-model critique loop, implements it multi-agent, certifies it deterministically, and merges to real `main` 24/7 with **no human and no external platform reviewing**. v1 proves this on the loop's own code.

1. **Recursive-safety = a minimal IMMUTABLE CONSTITUTION, not a fence.** The loop may rewrite *everything* incl. its own judge/cert/selector/pipeline. The only immutable thing is a property checker: any new quality-machinery version must still REFUTE a frozen battery of known-bad changes (incl. robustness breakages) AND still CERTIFY a frozen battery of known-good changes — else *that* change is REJECTED. It checks a **property** (detection power), never an implementation. A strictly-better judge PASSES; only a blinder/weaker one is REJECTED. *(Operator's hard constraint: "regras de regressão, mas o loop não pode quebrar do jeito que está nem ser impedido de melhorar se for realmente melhor.")*
2. **Design/projection engine = gpt-5.5 (Codex) via Hermes**, cross-model critique with GLM-5.2 / MiniMax-M3. **NEVER pin the model** — use the `hermes_cli_default` sentinel so Hermes's native fallback chain stays intact. *(The architecture-of-today was authored with Claude Code multi-agent; the BUILT loop's runtime projection engine is gpt-5.5-via-Hermes.)*
3. **External research + external loop catalogs = read-only source material.** Web/GitHub/papers/skill catalogs can inform `LoopPatternRegistry`, but ZERO Atlas code or secrets leave the machine. Local-first sovereignty; fail-**closed** on any egress doubt.
4. **Autonomy = maximum.** The loop depends on no one but itself. No abstain-that-BLOCKS on the human: what it cannot crack it **PARKS** (full analysis recorded) and CONTINUES. Live, no shadow. The single, narrow, non-blocking human touch is the `battery:retire` two-key (weakening the safety floor is the only non-autonomous act).

---

## 2. Verified ground truth (the corrected map)

| Fact | Verdict | Evidence |
|---|---|---|
| The loop can **already** edit + auto-merge its own non-pétreo tree TODAY | **TRUE** | `AtlasLoopHarnessGuard`: `FORBIDDEN_SELF_TARGETS` (:33-66) = ~20 enumerated files; bare prefix `app/Services/Ai/AutonomousEvolution/` is in `HARNESS_PREFIXES` (:69-71); `admit()` (:113-123) → `admissible` when meta flag ON; `.env` flags ON; `AutoMergeService:271-276` skips the park gate; watchdog drains every 60s. **86 distinct self-merged targets verified, latest today.** |
| The cert-chain is a **delegation tree**, not one file | **TRUE** | `AtlasLoopSemanticImplementationCertifier:61` delegates the deterministic verdict to `honestyGate->evaluateImplementation()`; transitive closure = **17 loop-local classes** (§3.3) |
| A symlinked `vendor` autoloads the **live** judge, not the candidate's | **TRUE (reproduced by execution)** | `materializeFull:125-129` symlinks vendor; composer `$baseDir=dirname(dirname(__DIR__))` resolves through the symlink to LIVE/app. Clone-local `vendor/composer` loads CANDIDATE bytes ✓ |
| `AtlasLoopAutoMergeService` does real git commits with **ZERO flock** | **TRUE** | `grep -c flock = 0`; watchdog `automerge --limit=10` every 60s as a separate process = the #1 main-corruption race |
| `config/atlas.php` is **admissible** (loop can flip its own gates) | **TRUE** | not in FORBIDDEN; safety flags span **two namespaces** `atlas.ai.loop.*` (merge-floor :834-870) + `atlas.loop.*` (gate-enables :2100-2700) |
| No **post-merge** RED-main detection/revert | **TRUE** | after `git commit` only DB attribution runs (`~:545`); pre-commit canary is default-narrow (`canary_full_coverage` OFF :848, `broader_regression_gate_live` OFF :859) |
| `judge_verdicts` slot read but never written | **TRUE** | `Certifier:350` reads `options['judge_verdicts'] ?? []`; no writer in `app/` |
| `AtlasLoopExpectedValueDecider` orphan; `touches_axes` has **no producer** | **TRUE** | `decide(candidates, context)` :54; `touches_axes` only referenced inside the decider (:48/:79) |
| `AtlasLoopUtilityGradeService`, `AtlasLoopCycleGitContract`(live), `AtlasLoopObraAutoMergeService`(own lock :339) | as mapped | UtilityGrade FORBIDDEN :49; CycleGitContract live caller `CampaignCommand:71`, lock `LOCK_EX\|LOCK_NB` :303; Obra own lock |
| The loop is **two OS processes** (propose-only supervisor + separate drainer); `produce()` has exactly one caller | **TRUE** | `AtlasLoopCampaignSupervisor` "NEVER merges" (:28-39); `AtlasLoopObjectiveProducer::produce()` called only in `AtlasLoopQueueRefiller::refill()` (:299); drainer = `automerge` cron/watchdog. See §4.0. *(All `:NNN` are frozen-snapshot offsets; re-resolve at build.)* |

---

## 3. THE IMMUTABLE CONSTITUTION — airtight mechanism

### 3.1 What is FROZEN vs MUTABLE

| Layer | Mutability | Mechanism |
|---|---|---|
| Constitution gate + battery format + accept/reject rule + the **BatteryRunner** + the **probe command** | **FROZEN / FORBIDDEN** | added to `FORBIDDEN_SELF_TARGETS` + `frozen_globs` |
| **Pétreo merge actuator** (`Constitution/AtlasLoopMergeActuator`: flock + gate-token + commit-only-if-passed) | **FROZEN / FORBIDDEN** | the gate-invocation *site*, not just the gate |
| **Full transitive cert-chain closure** (the 17 classes, §3.3) | **property_gated** (evolvable only through the battery) | the no-blinder property; NOT frozen-forever |
| Battery CONTENTS | **APPEND-ONLY** (grow, never shrink) | `AtlasLoopFrozenBattery`, hash-chained, on disk |
| `AtlasLoopMutationOperators` + a FROZEN snapshot `Constitution/frozen/AtlasLoopFrozenMutationOperators.php` | live map = property_gated; **snapshot = FROZEN** | the moat generates neighbors from the *frozen* snapshot, never the config-mutable live map |
| Selector / objective (`LeverageScorer`, `SystemAxisService`, `ExpectedValueDecider`, `StateOfAtlasReader`) | **property_gated by the OUTCOME battery** (§3.8) | brings the selector inside the Constitution |
| `config/atlas.php` | **property_gated** | the all-defaults-monotonic gate-effectiveness check (§3.7) |
| Watchdog respawn trigger (`bin/atlas-loop-watchdog.sh`) + `AtlasLoopMainHealthSentinel` | **FROZEN** (outside `DISCOVERY_ROOTS`) | structural battery case |
| Sovereignty kernel (`AtlasConstitutionalKernelService::INVARIANTS`, the 9) | **FROZEN** (orthogonal) | as-is |

### 3.2 The verdict mechanism — `property_gated` (a NEW third `admit()` verdict)

`AtlasLoopHarnessGuard::admit()` gains a **third** verdict between the forbidden check and the harness check. `property_gated` files (the cert-chain closure, mutation operators, selector, `config/atlas.php`) route to `AtlasLoopConstitutionGateService::admit()` instead of silent unlock. The safe transition is **`forbidden → property_gated`, NEVER `admissible → property_gated`** (door closing further, then re-opening under a gate). Per R2-11, this transition is **atomic** with wiring the gate into both live call sites + the interlock test + the **enumerated call-site census** across the three read patterns (`admit()` string-compare, raw `isForbiddenSelfTarget`, raw `isHarnessTarget`). Verified count: **17 consumer files** (raw occurrences `isForbiddenSelfTarget`=20, `isHarnessTarget`=6, `->admit(`=4) — every one migrated in the SAME atomic commit so no property_gated file silently keeps two-verdict semantics: `AtlasLoopHarnessGuard` (self), `AtlasLoopAutoMergeService`, `AtlasLoopFormalInvariantGateService`, `AtlasLoopSelfImprovementObjectiveBuilder` (must route a property_gated file through the gate, NOT the raw `isHarnessTarget` branch), `AtlasLoopMetaHarnessIntentSource`, `AtlasLoopTargetDiscoveryService`, `AtlasLoopQueueRefiller`, `AtlasLoopObraClusterDetectorService`, `AtlasLoopObraPlanValidator`, `AtlasLoopMultiFileRefactorSynthesizer`, `AtlasLoopOriginationProducer`, `AtlasLoopJudgeSelfCalibrationService`, `AtlasLoopLossObserverService`, `AtlasLoopMetaHarnessAbLiftService`, `AtlasLoopObraExecutionAdapter`, `AtlasLoopOperatorReviewQueueService`, `AtlasLoopAutoArchitectureProposalService`. `FormalInvariantGateService:148/285` updates to the three-verdict model in the same slice.

### 3.3 The protected closure — REFLECTION-DERIVED, not a hand-list (the R4 keystone fix)

Protecting the top certifier file alone is the R4 blinder hole: the verdict is **delegated**. The protected set is the **transitive cert-chain closure**, enumerated this session by walking `AtlasLoopSemanticImplementationCertifier`'s constructor + inline `new` recursively. **17 line-items / ~19 classes** — 16 loop-local **plus `AdversarialProofPanelService`** (in `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/`, *outside* the loop subtree, reached by delegation; already FORBIDDEN:44) — so the FROZEN reflection walker is **repo-wide, NOT subtree-scoped to `AutonomousEvolution/`**, or it silently drops a verdict-bearing collaborator = a blinder hole. (`AtlasDeadCodeAnalyzerSupport` and `AtlasLoopMetricHarness` are bundled members.):

```
Verify/AtlasEngineeringHonestyGate              (the deterministic gate; → DeadCodeAnalyzer, FrozenJudge)
Verify/AtlasDeadCodeAnalyzer (+ …Support)
Verify/AtlasLoopSignalAnalyzer
AtlasEvolutionFrozenJudge                        (already FORBIDDEN:34; → SignalAnalyzer)
AtlasLoopMutationAdequacyGateService
AtlasLoopCrossFileConsumerGateService
AtlasLoopBehavioralEquivalenceGate
AtlasLoopChangedSymbolCoverageCensus             (→ Discovery/AtlasLoopNodeInterfaceExtractor)
AtlasLoopQualityGrader
AtlasLoopDeliveryConfidenceModel
AtlasLoopCompletenessGate
AtlasLoopJudgeConsensusGate
AtlasLoopHeldOutDeltaCertifier (+ AtlasLoopMetricHarness)
Discovery/AtlasLoopCompletenessCriteriaResolver
Discovery/AtlasLoopNodeInterfaceExtractor
+ AtlasLoopSemanticImplementationCertifier       (already FORBIDDEN:43)
+ AdversarialProofPanelService                   (already FORBIDDEN:44)
```

**Design rule (so it can't rot as the chain grows):** the closure is **reflection-derived at gate time** by a FROZEN walker over the certifier's constructor + declared `new` delegation sites — not a hand-maintained list. Any verdict-bearing code reachable only by a *non-walkable* path (dynamic container `make()`, `config()`-string-built class names) is itself a **structural-battery-REJECTED pattern** — gate code must declare its dependencies statically so the closure is computable. The Merkle sentinel (§3.6) hashes the *entire* reflected closure.

### 3.4 The execution substrate — runs the CANDIDATE's bytes (the R3 keystone fix)

`AtlasLoopConstitutionGateService::admit($candidateDiff)` runs the candidate's *actual* certifier as a **subprocess in a clean worktree**, scoring only OS-level exit codes (the already-trusted `FrozenJudge:461` `passed = $exit === 0` pattern; exit-code surface in §10.5), never a candidate self-report:

```
1. Materialize the candidate worktree via Framework/AtlasLoopFrameworkMaterializer
   (real-copy vendor/composer + autoload.php + bin; in-worktree `composer dump-autoload -d $base`;
   ReflectionClass resolve-assertion fail-closes if App\ resolves OUTSIDE the worktree)
   — NEVER AtlasLoopProposalMaterializer::materializeFull() (symlinks vendor → loads the LIVE judge).
   [Reproduced by execution this session: symlink→LIVE bytes; clone-local vendor/composer→CANDIDATE bytes.]
2. A FROZEN, FORBIDDEN probe command (Constitution/probe/atlas-loop-cert-probe.php) drives the
   candidate certifier as a LIBRARY under a frozen entrypoint (inner Variant-C class_exists(...,false)
   require = redeclare-safe vs the exit-255 autoloader-redeclare bug).
3. PASS iff  ∀ bad ∈ battery.bad:        candidate REFUTES (externally observed: 0 proposal rows / non-empty $reasons / nonzero exit)
        AND  ∀ good ∈ battery.good:       candidate CERTIFIES (goods are MINIMUM obligations, not exact cases — a stricter judge is never rejected)
        AND  ∀ robust ∈ battery.robust:   AtlasLoopRobustnessProbe HOLDS (behavioral, not grep)
4. FAIL-CLOSED on probe_crashed / probe_timeout / probe_unknown_exit / candidate_bytes_not_proven / null.
   (Stricter than FrozenJudge's `?? 1`: null exit ⇒ REJECT.)
```

### 3.5 Wiring the gate into the LIVE paths (call sites created in Slice 4)
- **Grind-time:** `AtlasLoopSemanticImplementationCertifier::certify()` calls `ConstitutionGate::admit()` as a mandatory conjunct before `$reasons === []`, **when the candidate diff touches any property_gated file**.
- **Merge-time:** the **pétreo `AtlasLoopMergeActuator`** (§5) verifies the gate **PASS-token** before commit. Token = `H(candidateCommitSha ‖ batteryRootHash ‖ gateVerdict ‖ nonce)` emitted pre-lock on the proposal row; the actuator under lock re-verifies against `H(post-apply-tree-sha ‖ current batteryRootHash ‖ PASS ‖ nonce)` — binding to the post-apply tree so any gate→commit divergence invalidates the token ⇒ no commit.

### 3.6 The sentinel meta-test suite — the build-time discharge (FROZEN, FORBIDDEN)

`tests/Feature/Loop/Constitution/AtlasLoopBatteryRunnerSentinelTest.php` is the **admission gate**: no property_gated self-edit is admitted until it is green. It must assert:
- **(i) candidate-bytes-proven:** a unique sentinel only the candidate's *edited* bytes can emit — over the **full Merkle closure** (§3.3), not the top file. Proves the probe ran the candidate, not the live class.
- **(ii) per-collaborator blinder-REJECTED:** inject `certify-true` into **each class in the reflection-derived closure** in turn (esp. `HonestyGate::evaluateImplementation`) → each must be REJECTED with a `surviving_bad` receipt. A single edit to the top certifier proves nothing about the delegated gates.
- **(iii) fail-closed family:** probe_crashed / timeout / unknown-exit / bytes-not-proven each → REJECT, never PASS.

### 3.7 `config/atlas.php` — all-defaults-monotonic, both namespaces (the R4 SEV-2 fix)

A frozen `atlas:loop:constitution:resolve-flags` reads the candidate config by **literal** `require '<clone-abs>/config/atlas.php'` (NO `config()`/`base_path()`/bootstrap — those read the LIVE config; `.env` excluded from the clone so `env()` resolves DEFAULTS deterministically), then over `Arr::dot($cfg)` across **both** `atlas.ai.loop.*` and `atlas.loop.*`:

- **Monotonicity (data comparison, ungameable):** REJECT any candidate that flips a `true`-default safety gate → `false`, OR raises a threshold so a gate stops firing (e.g. `CalibratedConfidenceGate` `min_samples`→huge ⇒ `resolveThreshold` null ⇒ abstain=false), OR adds a `config()` default that disables a gate. **Prefer all-gate-shaped-defaults-protected over a hand-maintained allow-list** so a newly-added gate can't slip through unprotected.
- **Behavioral firing leg** (for gate-CODE edits) runs through the corrected §3.4 substrate.
- **Closure guard:** a frozen static-key lint REJECTS dynamic `config('atlas.loop.'.$var)` construction in gate files (verified every current gate read uses a string literal), so the monotonic census covers the entire static-key surface.
- **Named out-of-scope boundary:** an `env()`-VALUE override via the operator's live `.env` is not caught (`.env` is operator-owned, outside the loop's edit surface). `env()`-DEFAULT disables in `config/atlas.php` ARE caught.

Merge-floor flags to protect (both namespaces, verified): `atlas.ai.loop.{boot_smoke_guard, precommit_canary_gate, canary_full_coverage, canary_require_coverage, broader_regression_gate_live, value_gate_enabled}` + `atlas.loop.{self_improvement_auto_merge_enabled, meta_harness_targets, meta_harness_self_improve, completeness_gate_enabled, judge_consensus_gate_enabled, calibrated_confidence_gate_enabled, confidence_gate_enabled, …_gate_enabled}`.

### 3.8 Battery evolution without silent weakening
- **Append-only, hash-chained:** `battery_root_hash = H(prev ‖ new_case)`; removing a case breaks the chain → REJECT.
- **Known-BAD append** strictly strengthens → autonomous (a normal certified delivery).
- **Known-GOOD append** can dilute the floor → **two-key by default** (`atlas:loop:battery:retire`-class, Decision Receipt v2). A good is appendable only if a fresh radius-1 mutation neighborhood (from the FROZEN snapshot, §3.1) still forces REFUTE on every mutant — no certify-license over a must-catch region.
- **Genesis attestation:** the first `battery_root_hash` is co-signed by a Decision Receipt + a one-time cross-model audit (gpt-5.5 authors, GLM+MiniMax adversarially review each seed's `expected_verdict`).
- **The OUTCOME battery** (`AtlasLoopFrozenOutcomeBattery`): frozen `(objective_pair, correct-ranking)` cases; any selector version that ranks a behavior-preserving refactor above a genuine leap is REJECTED — enforcing "behavior-preserving refactor = ZERO improvement" as an immutable property.
- **Judge co-evolution (R3 CLOSER-4, proven):** every merge is exclusive-locked through the single actuator and a bad-append is always gated by the *post-rewrite* judge over its own fresh neighborhood ⇒ no jointly-admissible-but-individually-rejected pair. The ≥2-mutation-distant novel region is the pre-existing single-judge residual caught by the append-only ratchet in the wild — sharpened, not opened.

---

## 4. The DECISION BRAIN, PIPELINE, AUTONOMY (the value engine)

### 4.0 End-to-end runtime flow (the whole flow, drawn)

**The single most load-bearing runtime fact: the loop is TWO cooperating OS processes that communicate ONLY through the `atlas_loop_proposals` row** (no in-memory handoff). The supervisor *proposes and never merges*; a separate watchdog/cron process *drains* certified proposals to `main`. Every safety property in §5 depends on this split (it is *why* "the watchdog drains every 60s as a separate process" is the #1 corruption race).

**LANE A — Supervisor (`AtlasLoopCampaignSupervisor::run()`, PROPOSE-ONLY, "NEVER merges", doc-block :28-39):**
1. wake / acquire supervisor lock-lease.
2. **REFILL** (`AtlasLoopQueueRefiller::refill()` @ supervisor:265) = **UNDERSTAND** (`StateOfAtlasReader` + frontier comprehension) + **IDENTIFY** (EV argmax) + **PATTERN** (`LoopPatternRegistry` chooses the governed execution structure). Inside: `AtlasLoopObjectiveProducer::produce()` (@ `QueueRefiller:299`, gated `atlas.loop.objective_producer_enabled`) → `scorer->rank` → ambition floor → EV pick → pattern selection → `origination()->build()`. *(`produce()` has NO other caller — verified.)*
3. **[Slice 8]** severed async **PROJECTION** stage (`AtlasLoopProjectionEngine`, designer↔critic content-fixpoint) with its OWN claim/lease on `atlas_loop_pipeline_state`, so the zombie-detector never reclaims a "0-grinds" worker.
4. **CLAIM** a task (`store->claimNextTask` @ supervisor:298/:345).
5. **ORCHESTRATION + IMPLEMENT** (`AtlasLoopTaskGrinder`, N decorrelated attempts).
6. **TEST + CERT** (`AtlasLoopSemanticImplementationCertifier::certify` @ :61 → delegates to `honestyGate->evaluateImplementation`; reads cross-model `judge_verdicts` @ :350) **with the §3.5 ConstitutionGate conjunct when the diff touches a property_gated file**; emits the PASS-token onto the proposal row.
7. **PERSIST** (`AtlasLoopRunPersister::persist`) — writes `merged_to_main=false`, `reviewed_at=NULL`, the new gate-token columns (§10).
8. loop-back to step 2.

**↕ PROCESS BOUNDARY — the only handoff is the `atlas_loop_proposals` row.**

**LANE B — Drainer (`atlas:loop:automerge --limit=10`, cron `routes/console.php:139` + watchdog `bin/atlas-loop-watchdog.sh:43`, every 60s, SEPARATE OS process):**
9. `AtlasLoopAutoMergeService::drain()` (@ :71) picks `merged_to_main=false ∧ reviewed_at=NULL`, re-proves via `mergeOne()` (@ :207) through the forbidden/self-improvement/confidence/change-class park gates.
10. **`Constitution/AtlasLoopMergeActuator`** — acquire the single `.git/atlas-main-merge.lock` (`LOCK_EX`, 8s); re-verify the PASS-token re-bound to the post-apply tree; cheap floors (`php -l` + ≤5s boot-smoke); **commit-only-if-passed**.
11. **POST-MERGE `Constitution/AtlasLoopMainHealthSentinel`** (watchdog-invoked, outside `DISCOVERY_ROOTS`) — impacted-suite vs `main`; on RED `git revert <sha>`, park the proposal, feed the trust ladder a regression.
12. compounding / attribution → next cycle's EV inputs.

```
        LANE A · SUPERVISOR (propose-only, never merges)              LANE B · WATCHDOG/CRON DRAINER (every 60s, separate process)
   ┌─────────────────────────────────────────────────────┐      ┌──────────────────────────────────────────────────────────┐
   │ 1 wake/lease                                          │      │ 9  drain() picks merged_to_main=false ∧ reviewed_at=NULL   │
   │ 2 REFILL = UNDERSTAND + IDENTIFY (EV argmax)          │      │ 10 MergeActuator: .git/atlas-main-merge.lock (LOCK_EX 8s)  │
   │ 3 PROJECTION (async, own lease on pipeline_state)     │      │      → re-verify PASS-token bound to post-apply tree        │
   │ 4 CLAIM task                                          │      │      → php -l + ≤5s boot-smoke → COMMIT-only-if-passed      │
   │ 5 ORCHESTRATE + IMPLEMENT (grinder, N attempts)       │      │ 11 MainHealthSentinel: impacted-suite vs main              │
   │ 6 TEST + CERT (+ ConstitutionGate if property_gated)  │      │      → RED ⇒ git revert <sha> + park + ladder regression   │
   │ 7 PERSIST proposal row (+ gate-token cols)            │      │ 12 compounding / attribution                               │
   │ 8 loop-back ──────────────────────────────┐          │      └───────────────────────────┬──────────────────────────────┘
   └────────────────────────────────────────────┼─────────┘                                  │
                                                 ▼                                            ▼
                       ╔═══════════════════ atlas_loop_proposals ROW (the ONLY cross-process handoff) ═══════════════════╗
                       ║ {merged_to_main, reviewed_at, constitution_gate_token, _nonce, _verdict, battery_root_hash}     ║
                       ╚════════════════════════════════════════════════════════════════════════════════════════════════╝
```

**Branch flows & return edges** (trigger stage → store → re-consuming stage):
- **PARK** → `AtlasLoopParkLedger` → re-attempt-escalation re-enters via the refiller's candidate scoring on the next refill (step 2).
- **RESEARCH / PATTERN-SOURCE** → `atlas_loop_research_notes` + quarantined pattern source material → consumed by `LoopPatternRegistry` and `AtlasLoopProjectionEngine` as advisory input; never gates a cert by itself.
- **WANT-INTAKE** → `atlas:loop:want` writes manifest rows → read by `TargetDiscoveryService:100` on the next refill.
- **CODE-DRIFT restart** → checkpoint in `atlas_loop_pipeline_state` → resumed by the supervisor on watchdog-triggered respawn (debounced ≤1/window).

**State carriers** are specified as DDL in **§10** (the new `atlas_loop_pipeline_state` table + the new `atlas_loop_proposals` gate-token columns — neither exists today, both are Slice deliverables).

### 4.1 Decision brain — pick the most-exponential next evolution
- **[REUSE+WIRE] `AtlasLoopExpectedValueDecider`** wired into `AtlasLoopObjectiveProducer::produce()` after `scorer->rank` + ambition floor, before `origination()->build()`. Objective: `argmax  P_success(class) · value · bottleneck_relief − cost`.
- **[NEW] `LoopPatternRegistry`** sits after EV selection and before origination. It maps a leap to a governed execution pattern (`docs_sweep`, `ticket_to_pr_ready`, `loop_harness_verification`, `self_improving_champion`, `devils_advocate`, `fresh_clone`, etc.) and compiles that pattern into an `ExecutionContract`. External skills/catalogs/agent workflow OSs (Loop Library, MachinaOS, papers, repos) are source material until Atlas evidence promotes them.
- **[NEW] `PatternSpec` schema** is backend-owned and projection-only to surfaces: `params_schema`, `output_schema`, `durability_mode`, `sandbox_profile`, `agent_lane_policy`, `success_gate`, `terminal_states`, `budget`, `rollback`, `memory_writeback`, `source_snapshot`. This intentionally absorbs the useful MachinaOS shape (schema-driven plugins + durable modes + CLI-agent worktrees + sandbox profiles) without importing its runtime authority.
- **[NEW] `AtlasLoopSystemAxisService`** (NOT an edit of the FORBIDDEN `UtilityGradeService` — it *consumes* its re-resolution discipline) emits the per-cycle **system-axis vector** (re-resolved from git/graph every cycle ⇒ a *moving* objective: relieving the binding axis auto-pivots to the next — the "made code-gen fast → review is now the bottleneck" emergent, not hand-coded).
- **[NEW, Slice 7.5] `touches_axes` deterministic producer** — derives which axis an *unbuilt* candidate moves from target-path + change-class via the same taxonomy (`WiredCallerService`), so `bottleneck_relief` is machine-computable (verified today it has NO producer). Any axis needing model prediction is labeled advisory/model-bound and excluded from the machine-checkable claim.
- **Robustness axis is continuous & re-violatable:** `1 − incidents_per_window` from the outcome ledger — a failure class can regress and must be *continuously* defended; box-checking finite toggles never earns ladder promotion.
- **Branches:** IMPLEMENT (`OriginationBuilder`, RED-verified) / REFACTOR / RESEARCH (§4.3) / **PARK** (`AtlasLoopParkLedger` with re-attempt-escalation + a "high-EV parking faster than delivered" loop-health regression flag — park-everything is autonomy theater).
- **Rejected sources:** prompt-only autonomy, unknown-node success fallbacks, unsafe code execution as a security boundary, unpinned skills, unverified manual status counts and external memory authority all compile to `blocked`, not to a runnable pattern.

### 4.2 The pattern-guided 7-stage pipeline as infinite-time runtime (one-shot retired)
**[NEW] `AtlasLoopDeliveryPipeline`** — a persistent, resumable per-objective state machine (`atlas_loop_pipeline_state`). **Critical topology fix:** `produce()` becomes a **dispatcher** that enqueues a projection job and returns in microseconds — the unbounded designer↔critic Hermes loop runs in its **own async stage** with its own claim/lease, NEVER on the synchronous refiller hot-loop (else the zombie-detector reclaims a "0-grinds" worker, reintroducing the stall).

| Stage | Engine | One-shot mechanism RETIRED |
|---|---|---|
| 1 UNDERSTAND | `StateOfAtlasReader` + frontier comprehension (advisory, fail-open) | `strategicWeightFor` keyword proxy |
| 2 IDENTIFY | EV argmax over moving axes + `LoopPatternRegistry` pattern selection | — |
| 3 **PROJECTION** | **[NEW] `AtlasLoopProjectionEngine`**: gpt-5.5 designs → GLM/MiniMax critiques (writer≠judge) → revise → loop until **content-fixpoint** | `max_scenarios=18`, `search_patience=4`, single-shot `challenge()`; **smallest-diff tie-break INVERTED** to higher-leverage |
| 4 ORCHESTRATION | **[NEW] `AtlasLoopOrchestrator`** model-role sequencer; Hermes-down → PARK (never ship un-critiqued) | — |
| 5 IMPLEMENT | `AtlasLoopTaskGrinder` / ADEP, N decorrelated attempts; explorer demoted to resource-hinted impl engine | explorer's finite *decision* role |
| 6 TEST | deterministic cert + cert-battery + outcome-battery; **real cross-model `judge_verdicts` fed** to `Certifier:350`; `final = deterministic AND (panel advisory)` | — |
| 7 WIRING | `WiredCallerService` + WIRED axis + orphan scan | — |

**Content-fixpoint convergence (machine-checkable, not panel mood):** the DESIGN step emits each obligation as a typed tuple `{kind∈enum, target_symbol, assertion_ref}` where `assertion_ref` names a concrete check the cert chain can run (a mutation-operator id, a characterization-test target, a consumer-gate). "New obligation" = set-difference on normalized keys — ungameable by rephrasing. CONVERGED iff the obligation set stops growing AND ≥1 obligation maps to the binding axis AND the critic raised-then-resolved ≥1 material obligation (a contract that never grew is suspect, not converged). Oscillation (count won't shrink for N rounds) → **PARK** (liveness floor, no livelock). Spend ceiling = the EXISTING `ATLAS_LOOP_COST_GOVERNOR_*` (§K), time-slicing *rate* not *depth*; checkpointed projections pin at accrued-EV priority and resume ahead of fresh objectives (resumption guarantee, not a renamed cap).

### 4.3 Self-research intake (read-only, zero-out)
**[NEW] `AtlasLoopExternalResearchService`** — pull-only `string $topic` (never a diff/payload). **Behavioral egress filter** on the realized topic at the WebSearch/WebFetch boundary: REJECT if it contains repo-path fragments, secret patterns, or ≥N consecutive tokens matching any indexed source line (reuse the code-graph index). Fail-**closed** when the tool is absent. Output → live advisory `atlas_loop_research_notes` (informs the projection designer; **never gates a cert**). Bounded cadence, never a constant crawl.

### 4.4 Maximum autonomy
Live, no shadow; PARK-and-continue (never blocks on the human). Self-heal reuses the proven layer (atomic claim + attempt-decrement-on-reclaim + keepalive multi-layer respawn + supervisor lock-lease + transient-DB park). **Code-drift restart** is **externally triggered** by the watchdog (a process must not edit its own respawn logic and die), **debounced** (≤1 restart per window / per N self-merges), checkpoint-surviving.

---

## 5. CLOSING THE CYCLE ON MAIN — the actuator + the post-merge net

**Unify ALL git commit/apply sites onto ONE locked primitive.** Today `AutoMergeService` commits with **zero flock** while the watchdog drains every 60s as a separate process (the #1 corruption race), and `ObraAutoMergeService` has its own separate lock.

- **[NEW, FROZEN, FORBIDDEN] `Constitution/AtlasLoopMergeActuator`** holds the single path-stable `$repoRoot/.git/atlas-main-merge.lock` + the irreversible `apply → cheap-floor → commit` spine. Collapse the 3 lock files into one. **`LOCK_EX` blocking + bounded poll (`LOCK_TIMEOUT_SECONDS=8.0`) + deferred-retry** (`merged_to_main=false ∧ reviewed_at=NULL` ⇒ re-drainable; `_lock_deferrals` counter; K=5 ⇒ `lock_contention_starvation` park) — no silent `LOCK_NB` drop, no in-process deadlock, no indefinite starvation.
- **Gate partition (SEV-3a, honest):** under the 8s lock the actuator re-runs ONLY cheap deterministic floors — `php -l` + a **boot-smoke pinned ≤5s** (the live 60s timeout exceeds the lock budget; fail-closed reject+release+deferred-retry on timeout) + the **ConstitutionGate PASS-token** + **full-changed-set routing**. The actuator's hard guarantee is **"syntactically valid + boots + constitution-passed"**, explicitly **NOT** "behavior-regression-free."
- **Routing (SEV-3b):** route on the actuator's OWN `git diff` over the **exact post-apply pre-commit tree inside the actuator** — it CANNOT call `FrozenJudge::changedFiles` (private :396) nor `AutoMergeService::bootSmokeOk` (private :1288), both on FORBIDDEN files; Slice 1 extracts these two helpers into the FROZEN `Constitution/` tree (or the actuator re-implements them). The diff is computed — NOT the declared `target_path`, NOT a separate probe clone, NOT `scopeToPatch` (which intersects to declared files = the hide-the-sibling hole). Rationale: `applyWithLadder:268-312` `--reject` partial-apply salvage drops hunks and diverges a probe clone from the commit. `frozen_globs ⊇ all FORBIDDEN` ⇒ a same-diff edit to any cert-chain/runner/judge file trips `FrozenJudge:83 frozen_path_tampered`.
- **The behavior-regression floor lives POST-merge:** **[NEW, FROZEN] `Constitution/AtlasLoopMainHealthSentinel`**, invoked by `bin/atlas-loop-watchdog.sh` (external, outside `DISCOVERY_ROOTS`) after each drain — runs an impacted-suite (`BroaderRegressionGate::selectTestPaths`) against `main`; on RED, autonomously **`git revert <sha>`** the last loop commit (revert, NEVER `reset --hard` — the operator's revert-not-reset rule, implemented fresh in the sentinel; cf. the advisory policy note at `AutoArchitectureProposalService:415`, not a reusable primitive), parks the reverted proposal, feeds the trust ladder a regression. "A landed loop commit is health-verified or reverted within one watchdog window" is a robustness battery case. The canary/broader-regression become **pre-lock advisory / mutable-caller gates**; the sentinel is the **real net** for behavior-regression escapes.

---

## 6. TERRITORY LADDER + observability (§K)

**[NEW] `AtlasLoopTerritoryLadder`** — scope released gradually, gated on proven quality (rung 0 = `AutonomousEvolution/`; rung 1 = broader `app/Services/Ai/`; rung 2 = memory; …). **Promotion is ATOMIC over three sets in one Decision Receipt:** (a) widen `DISCOVERY_ROOTS`, (b) freeze the new territory's FORBIDDEN set via a FROZEN deterministic `AtlasLoopTerritorySafetyClassifier` (loop-uneditable, only ADDS to FORBIDDEN; the loop is only the proposer — non-circular), (c) author the territory's robustness cases. Invariant test: `DISCOVERY_ROOTS ⊇ territory ⟹ territory's safety files ∈ FORBIDDEN ∧ ≥1 robustness case exists`. **Promotion rule:** K certified *originated leaps* (not finite box-checks) + battery-clean + zero RED-main over a window + compounding ledger trending bigger (TIME×LEVEL up).

**§K Observability + cost (reuse, don't reinvent):** extend the EXISTING `AtlasLoopMorningDigestService::digest()` (scheduled `routes/console.php:270`) to read `atlas_loop_pipeline_state` (stage funnel, parked-high-EV count, MainHealthSentinel reverts); charge the async ProjectionEngine's Hermes turns into the EXISTING `cost_governor` accounting (wired into `AtlasLoopCampaignSupervisor`, throttle-at-80%) so the projection stage isn't cost-blind.

**Operator's WRITE port** — **[NEW, Slice 14.5] `AtlasLoopIntentIntakeService` + `atlas:loop:want "<NL>"`**: the read side is live (`BacklogIntentSource` → `TargetDiscoveryService:100`) but no NL write side exists; this translates a free-text want into validated `{path, objective, priority}` manifest rows (path-existence checked), append-safe, with a Decision Receipt — the literal "descreve um pedido → entra numa LISTA."

---

## 7. END-TO-END ZERO-WINDOW ORDERING (proven airtight, R3)

The bootstrap must compose to **zero windows where a property_gated/safety file is editable with no live gate.** The proof (full trace in `r3-findings.md`):

- **Slice -1 (operator/Claude, FIRST):** ONE atomic Decision Receipt — (1) `ATLAS_LOOP_SELF_IMPROVEMENT_AUTO_MERGE_ENABLED=false` (freeze the already-open door); (2) append to `FORBIDDEN_SELF_TARGETS` the entire `Constitution/` subtree + every Slice 1-4.5 target file + the full cert-chain closure (§3.3) + `config/atlas.php` + `AtlasLoopMutationOperators.php` + the frozen snapshot. The loop physically cannot touch what is about to be built.
- **Slice 0:** the empirical provenance audit (two-lens: git-subject `'atlas loop auto-merge: <target> [<hash>]'` + DB `quality->>_is_self_improvement`) diffed against the **EXACT** forbidden paths (NOT loose basenames — verified `PromotionGate` substring false-positives) — **executed this session: intersection EMPTY ⇒ clean baseline**, co-signed with the flag-off in the same action (no audit→freeze gap). Record the parked config reversals.
- **Slices 1 … 5 (operator/Claude-built, loop frozen out the whole time):** each foundation file is born INTO FORBIDDEN. **Slice 4 is the only transition: `forbidden → property_gated`**, atomic with both live call-site wires + the interlock test (behavioral: rigged-REJECT ⇒ zero new commit object). Never `admissible → property_gated`, so there is never an instant where a cert-chain file is admissible-and-ungated.
- **GATE:** no property_gated self-edit admitted until **Slices 4 AND 4.5 green AND the §3.6 sentinel suite green AND every Constitution/safety file FORBIDDEN.**
- **Flag re-enable = the FINAL parked operator step,** after the gate is proven live.

**Conclusion:** pre-Slice-4 every such file is FORBIDDEN (flag-off belt + forbidden-list suspenders); at Slice 4 the only transition is forbidden→property_gated atomic with the live wires; post-Slice-4 every edit is gated and the gate runs candidate bytes. The single dependency (not a window): the §3.4 substrate fix + §3.6 sentinel land in 4.5 before re-enable — encoded in the GATE wording. **`convergedZeroWindow = true`.**

---

## 8. ORDERED IMPLEMENTATION SLICE PLAN

Owner column: **O** = operator/Claude-built (bootstrap foundation, loop frozen out); **L** = loop-eligible after the gate is live; **O/L** = O-built, L-eligible thereafter; **O→L** = O builds the port, L consumes it. ⚑ = model-bound-capped.

| # | Slice | Owner | Delivers / Proof |
|---|---|---|---|
| **-1** | **Freeze the door** | O | flag OFF + foundation+closure → FORBIDDEN. *Proof:* injected self-improvement proposal PARKS; `Constitution/` returns `forbidden`. |
| **0** | **Provenance audit + parked receipts** | O | two-lens audit vs exact forbidden paths (done: empty); config reversals parked. |
| **1** | **`AtlasLoopMergeActuator` + lock unification** | O | one flock, `LOCK_EX`+8s timeout+deferred-retry, 3 locks collapsed. *Proof:* two real PIDs race → exactly one commit object (`rev-list`/`fsck`), loser re-queues. |
| **1.5** | **`AtlasLoopMainHealthSentinel`** | O | external post-merge RED-main detect + `git revert`. *Proof:* a green-in-isolation/RED-in-combination commit is reverted within one window. |
| **2** | **`AtlasLoopRobustnessProbe` + cases** | O | behavioral lock-drop / `LOCK_SH`-substitution / attempt-decrement / boot-smoke / watchdog-outside-roots / gate-effectiveness. *Proof:* each REJECTs its breakage incl. a `LOCK_SH` rewrite (not a grep). |
| **3** | **`AtlasLoopFrozenBattery` + frozen mutation snapshot** | O | on-disk hash-chained cases (format + canonical hash §10.3; genesis seed contents §10.4) + `frozen/AtlasLoopFrozenMutationOperators` + literal-require resolve-flags + genesis cross-model attestation. *Proof:* removing a case breaks the chain; permissive known-good meta-rejected. |
| **4** | **`AtlasLoopConstitutionGateService` + `property_gated` verdict** | O | the atomic transition (§3.2): third verdict + insert branch + gate conjunct in `certify()` + actuator token + the enumerated 17-file census (§3.2) + gate-token migration (§10.2) + `FormalInvariantGateService` update + `config/atlas.php`→property_gated. *Proof:* blinder cert → REJECT(surviving-bad); better cert → PASS; interlock test behavioral. **GATE.** |
| **4.5** | **`AtlasLoopBatteryRunner` + §3.6 sentinel suite** | O | isolated clone-local autoload (candidate bytes) + Merkle over the full closure + per-collaborator blinder-rejected + fail-closed family. *Proof:* §3.6 suite green. **GATE.** |
| **5** | **`AtlasLoopFrozenOutcomeBattery`** | O | selector inside the Constitution. *Proof:* a selector ranking refactor>leap → REJECT. |
| **6** | **`AtlasLoopSystemAxisService`** | O/L | per-cycle re-resolved axis vector. *Proof:* recomputes from a fresh commit, never a stored field. |
| **7** | **Wire `AtlasLoopExpectedValueDecider`** | L | EV pick in `produce()`. *Proof:* re-pivots to next-binding axis after relief. |
| **7.5** | **`touches_axes` producer** | L | makes `bottleneck_relief` machine-computable. *Proof:* recomputes with no provider call; EV-plateau becomes meaningful. |
| **7.6** | **`LoopPatternRegistry`** | L | seed read-only patterns, quarantine external catalogs, select pattern after EV pick, compile `ExecutionContract`. *Proof:* same objective chooses different contracts for docs/bug/refactor/self-improvement; external pattern cannot become default without fresh eval. |
| **8** ⚑ | **`AtlasLoopDeliveryPipeline` (async) + sever `produce()`** | L | `atlas_loop_pipeline_state` (DDL §10.1); `produce()` (sole caller `QueueRefiller::refill()`) → dispatcher returns in µs. *Proof:* refiller microsecond-returns while a projection runs async; zombie-detector doesn't reclaim it. |
| **9** ⚑ | **`AtlasLoopProjectionEngine` + feed real `judge_verdicts`** | L | designer↔critic content-fixpoint + quality-stall PARK. *Proof:* empty→populated verdicts don't change the non-self-mod threshold; a CERTIFY-panel cannot lift a deterministic REFUTE; cert-chain changes fail-closed→PARK when panel down. |
| **10** | **Kill one-shot in the explorer** | L | remove smallest-diff tie-break; invert to leverage; demote patience/max-scenarios to hints. *Proof:* higher-leverage of two maxed designs wins. |
| **11** ⚑ | **`AtlasLoopOrchestrator` + Hermes-down + cost** | L | model-role sequencing; park-on-outage; charge into the existing cost_governor. *Proof:* Hermes-down → leaps park, refactor-supply continues. |
| **12** ⚑ | **`AtlasLoopExternalResearchService`** | L | topic-only, behavioral egress filter, fail-closed. *Proof:* a `$diff`-bearing topic is rejected; tool-absent → no research. |
| **13** | **Drift-restart re-enable (external, debounced)** | L | watchdog-triggered, checkpoint-surviving. *Proof:* a self-merge → ≤1 restart/window; in-flight projection survives. |
| **14** | **`AtlasLoopParkLedger` escalation + health metric** | L | re-attempt-escalation; "high-EV parking faster than delivered" flag. |
| **14.5** | **`AtlasLoopIntentIntakeService` + `atlas:loop:want`** | O→L | the operator's NL write port. *Proof:* free-text want → validated manifest row next cycle. |
| **§K** | **Observability + cost on existing machinery** | L | extend MorningDigest + cost_governor accounting. |
| **15** | **`AtlasLoopTerritoryLadder`** | L | atomic three-sets promotion + behavioral revert-flips-battery classifier (carries the SEV-4 rung-≥2 detector). |

**Re-enable the self-edit flag (FINAL O step)** after Slices 4+4.5+5 green, the §3.6 sentinel green, and every safety file FORBIDDEN.

---

## 9. Honest residuals (documented, NOT furos)

1. **Trusted-base airtightness is a build-time discharge** (§0): proven green by the §3.6 sentinel suite over the reflection-derived closure, not on paper. This is the correct boundary for a self-modifying judge.
2. **SEV-3a behavior-regression floor:** the actuator makes NO behavior-regression-free claim under the 8s lock; canary/broader-regression are pre-lock advisory / mutable-caller gates; the post-merge `MainHealthSentinel` (git-revert on RED main) is the named real net.
3. **Boot-smoke liveness pin:** under-lock boot-smoke ≤5s, fail-closed; `LOCK_EX`+bounded-poll+K=5 park degrades *cadence*, never corrupts main. A liveness nick, not a safety hole.
4. **SEV-4 rung-≥2 territory classifier:** the behavioral revert-flips-battery detector for loop-authored NEW safety files on rung ≥2 is bounded by operator co-sign of the FORBIDDEN list at promotion. Out of v1/rung-0 scope.
5. **`.env` env()-VALUE boundary:** operator-owned, outside the loop's edit surface; env()-DEFAULT disables in `config/atlas.php` ARE caught.
6. **Model-bound caps (§ the N×M ceiling):** greenfield decomposition *origination* + semantic-correctness *origination* live in gpt-5.5/GLM/MiniMax. The Constitution guarantees the cert never goes blinder and the projection loop raises *design* quality, but the ceiling of "how good a leap the loop can originate" is bounded by the frontier model. Atlas multiplies (N×M); it does not originate beyond the engine. The single-judge novel-bad ≥2-mutation-distant region is caught by the append-only ratchet in the wild. Forcing these closed deterministically would be the forbidden Goodhart.

These six are the genuine frontier. Everything else is buildable on the verified, mapped, enumerated machinery.

---

## 10. Build-time schemas & enumerations (zero-ambiguity appendix)

Decided-but-previously-unwritten details an implementer would otherwise have to invent. *(Line refs throughout this doc are frozen-snapshot offsets; re-resolve at build.)*

### 10.1 `atlas_loop_pipeline_state` — NEW table (Slice 8; no migration exists today)
```
id              bigint pk
objective_id    string  unique            -- one row per objective
stage           enum(understand|identify|projection|orchestration|implement|test|wiring|parked)
claim_owner     string  nullable          -- worker lease holder (async projection stage)
lease_expires_at timestamp nullable        -- zombie-detector reclaims only when expired
checkpoint      json                      -- resumable mid-pipeline state (designer↔critic round, etc.)
accrued_ev      numeric                   -- resume priority; checkpointed projections re-enter AHEAD of fresh objectives
obligation_set  json                      -- the typed-obligation tuple set (§10.6), for content-fixpoint convergence
created_at / updated_at  timestamps
INDEX (accrued_ev DESC)                   -- resume-priority ordering
INDEX (stage, lease_expires_at)           -- claim scan
```

### 10.2 `atlas_loop_proposals` — NEW gate-token columns (Slice 4; a migration, NOT a frozen-file class edit)
```
constitution_gate_token   string  nullable  -- H(candidateCommitSha ‖ batteryRootHash ‖ gateVerdict ‖ nonce)
constitution_gate_nonce   string  nullable  -- SINGLE-USE: generated at gate time, consumed+nulled by the actuator under lock; a re-presented nonce ⇒ REJECT (defeats replay of a stale post-apply bind)
constitution_gate_verdict string  nullable  -- PASS|REJECT|<surviving-bad reason>
battery_root_hash         string  nullable  -- the battery version the token was minted against; actuator re-checks against current ⇒ a battery bump invalidates in-flight tokens
```
Adding a column to the FORBIDDEN `AtlasLoopProposal` model is a **migration** (idempotent; never `INSERT INTO migrations` by hand), not an edit of the frozen class file.

### 10.3 `AtlasLoopFrozenBattery` — on-disk format + canonical hash (Slice 3)
- **Path:** `app/Services/Ai/AutonomousEvolution/Constitution/battery/` — one immutable file per case `case-<NNNN>-<kind>.json` + `manifest.json` (the ordered chain).
- **Case record:** `{ id, kind ∈ bad|good|robust, diff_bytes | mutation_ref, contract, expected_verdict, prev_hash, case_hash }`.
- **Canonical hash (byte-reproducible for genesis attestation):** `case_hash = sha256(prev_hash . "\n" . json_encode($case_without_hash, JSON_THROW_ON_ERROR|JSON_SORT_KEYS))`; `battery_root_hash` = the last `case_hash` in the chain. Removing/reordering any case breaks the chain ⇒ REJECT.

### 10.4 Genesis seed CONTENTS (Slice 3; an unwritten list cannot be attested)
Cert-judge **known-BAD** seeds (each must be REFUTED by any judge version), with `expected_verdict=REFUTE`:
1. fake-green no-op (RED-on-revert / diff-earned).
2. wrong-but-green / overfit short-circuit (held-out delta).
3. behavior-break masked by a complexity drop.
4. scope/tamper escape via `.gitignore` self-authoring.
5. consumer-break (green locally, breaks a code-graph consumer).
6. survivor mutant (added branch, no killing assertion).
7. **blinder injections** — one per cert-chain closure class (§3.3): `certify-true` forced into HonestyGate/MutationAdequacy/CrossFileConsumer/… each → must REFUTE.
8. delete-a-refuter; raise `calibrated_confidence.min_samples` to disable abstain; behavior-preserving-refactor-claimed-as-leap.

Cert-judge **known-GOOD** seeds (each must be CERTIFIED, expressed as MINIMUM obligations so a stricter judge isn't rejected), `expected_verdict=CERTIFY`: a genuine RED→GREEN feature with a real killing assertion; a genuine cyclomatic reduction that preserves behavior (revert→RED on the characterization test); a genuine cross-file refactor with consumers green. *(The robustness-6 are specified in Slice 2.)* The genesis `battery_root_hash` is co-signed by a Decision Receipt v2 + a one-time gpt-5.5-authors / GLM+MiniMax-audit pass over each seed's `expected_verdict`.

### 10.5 Probe exit-code surface (Slice 4.5; the BatteryRunner reads ONLY the exit code — stdout is advisory log)
```
0   PASS (all bad REFUTED, all good CERTIFIED, all robust HELD)
10  surviving_bad        → REJECT (receipt: which bad survived)
20  good_not_certified   → REJECT
30  robustness_violation → REJECT
64  probe_crashed        → REJECT (fail-closed)
65  probe_timeout        → REJECT (fail-closed)
66  bytes_not_proven     → REJECT (the candidate-bytes sentinel did not fire ⇒ wrong tree loaded)
*   unknown / null exit  → REJECT (stricter than FrozenJudge's `?? 1`)
```

### 10.6 ProjectionEngine obligation tuple (Slice 9; the "ungameable convergence" rests on this normalization)
- **Tuple:** `{ kind, target_symbol, assertion_ref }`.
- **`kind` enum:** `behavior_preserved | red_to_green | mutation_killed | consumer_intact | complexity_reduced | contract_upheld | perf_bound | coverage_added`.
- **`assertion_ref` discriminator:** `mutop:<id>` (an `AtlasLoopMutationOperators` id) · `chartest:<path::method>` (an `AtlasLoopCharacterizationTestVerifier` target) · `consumer:<fqcn>` (an `AtlasLoopCrossFileConsumerGateService` consumer).
- **Normalization (so set-difference is rephrase-proof):** `key = lower(kind) . '|' . fqcn(target_symbol) . '|' . canonical(assertion_ref)` where `target_symbol` is resolved to its fully-qualified name and whitespace/case are stripped. "New obligation across rounds" = set-difference on these keys. **Persisted** in `atlas_loop_pipeline_state.obligation_set` (§10.1).

> **Standalone-spec status:** with §4.0 (the whole flow drawn), §10 (the NEW-artifact schemas), and the coherence corrections folded, the doc is buildable end-to-end with no decided-but-unwritten detail. The only deliberately-deferred items are the honest residuals of §9 (build-time sentinel discharge, model-bound caps, rung-≥2) — documented, not gaps.

---

## 11. Capability completeness — matrix, gaps, and the new capability designs

A capability-completeness audit (code-verified) checked whether the loop can autonomously deliver EVERY work-type at extreme quality. The **spine** (Constitution §3, the no-blinder mechanism) and the **four frozen-bar work-types** (feature origination, refactor, dead-code, implementation) are DESIGNED. Six capabilities were PARTIAL/MISSING and **not** model-bound — designed here so the path is complete.

### 11.1 Status matrix (designed · partial · missing · model-bound)
| Capability | Status | Note |
|---|---|---|
| Feature origination (single-target, RED-verified) | **designed** | `OriginationBuilder` + `TaskGenerator` isRed; quality ceiling model-bound |
| Refactor (single-file / extract-class / multi-file obra) | **designed** | synthesizers + frozen-behavior cert + consumer gate |
| Implementation (iterate-to-green) | **designed** | `TaskGrinder`/ADEP |
| Feature-spec — single-target | **designed** | `TaskGenerator` authors objective + RED-verified test |
| Slice-DAG decomposition (structure) | **designed** | `ObraDecompositionPlanner`+`PlanReadinessGate`; split semantics model-bound |
| Self-supplied metrics / Constitution / ladder / intent-intake / research / one-shot-retire | **designed** | §3/§5/§6/§4.3/Slice 10 — all unbuilt |
| **Performance (identify · measure · prove)** | **missing → designed §11.2** | no perf axis, no bench harness, unsound timing cert |
| **Feature-spec — obra (criterion → earned RED)** | **partial → designed §11.3** | `PlanReadinessGate` accepts by field-presence, never proves RED |
| **Bug-fix reproduction lane** | **partial → designed §11.4** | failure-signature never becomes a reproduction-RED |
| **Comprehension grounding gate** | **missing → designed §11.5** | hallucinated "true objective" unchecked |
| **Coverage discovery + cross-file dedup** | **partial → designed §11.6** | coverage parasitic; dedup (canon core value) has no lane |
| Closed-cycle-on-main PROOF | **designed, unbuilt** | §5; the #1 live risk (`flock=0`) — Phase 1 |
| Greenfield concept origination · novel-behavior correctness · obra-split semantics · prose-semantics docs · deep purpose-grasp · self-judge airtightness | **model-bound** | §9 / §0 — real frontier ceilings, NOT papered over |

### 11.2 PERFORMANCE — first-class capability (the keystone gap)
- **Identify:** add a `performance` SYSTEM AXIS to `AtlasLoopSystemAxisService` / `ExpectedValueDecider` AXIS_WEIGHTS with a **measured** value `1 − normalized_hot_path_cost` + a `touches_axes` mapping, so a perf candidate can be the binding bottleneck.
- **Measure:** **[NEW] `Constitution/AtlasLoopBenchmarkHarness`** — repeated-sample runner (N iterations + warmup discard, median + IQR/stddev, CPU-affinity where possible) emitting an output-scalar contract the cert consumes.
- **Prove:** **PERF-CERT** = `behavior-preserved (mutation/equivalence gate) AND statistically-significant speedup on a held-out benchmark` (warmed median over N runs, regression threshold, variance guard) — closes `HeldOutDeltaCertifier`'s single-sample / `delta>0` / no-behavior-conjunct holes for timing. Add a `bench:`/`perf:` discriminator to the §10.6 `assertion_ref` so a `perf_bound` obligation names a runnable check.
- **Defend:** a **perf-regression arm** on `AtlasLoopMainHealthSentinel` — a merge that silently slows a hot path is revertable, not just behavior-RED.

### 11.3 FEATURE-SPEC CREATION — first-class + the obra earned-RED bridge
Define **spec = {objective, RED-verified acceptance, slice-DAG, interface contracts}** with a producer for each component, AND the missing bridge: **[NEW] a `criterion → emit failing test → verify RED` producer** that fuses `IntentSpecCompiler.acceptance_criteria` into a Lane-1 (`TaskGenerator`-style `isRed`) acceptance artifact. **Upgrade `PlanReadinessGate`** (or add a gate) to require each obra node's acceptance command is **VERIFIED RED against the pre-implementation tree** — replacing the field-presence + keyword-overlap check (`:435-448`/`:160-189`). Model-bound line drawn at **origination-of-criteria only**; the RED-proof of an asserted criterion is deterministic.

### 11.4 BUG-FIX reproduction lane
**[NEW] a bug-reproduction work-type + slice:** convert a `failure_signatures` row into a **reproduction-RED objective** (RED bar = the reproduced failure), then the normal implement→cert path fixes it. Faithful-repro *synthesis* from a raw log is flagged model-bound; the **lane** is designed.

### 11.5 COMPREHENSION grounding gate
Under §4.2 stage 1: the frontier model's stated true-objective must **cite concrete symbols/consumers that exist in the code-graph** (deterministic citation-existence check, fail-open but logged) — so a hallucinated purpose is detectable before it misdirects every downstream stage.

### 11.6 COVERAGE discovery + cross-file DEDUP
- **De-parasitize coverage:** **[NEW]** a coverage-deficit discovery source scoring wired+complex files by **mutation-survival density independent of a pending refactor**, feeding `characterization_test` tasks directly (today `CoverageGapDetector:44` only fires as a side-effect of a blocked refactor).
- **Cross-file dedup** (canon core value, `loop-canonical-definition:14`): **[NEW]** clone-detection discovery + an equivalence cert (consumer-gate green on both call-sites). If deferred, mark it explicitly — the doc must not imply refactor coverage is complete.

### 11.7 §9 model-bound updates
- **docs-update split:** class/command-existence is deterministically covered (`DocClaimAnalyzer`); **prose-semantics accuracy** is the model-bound residual.
- **non-`.php` / dependency / config delivery** is a **territory-ladder dependency** (out of rung-0 `.php` scope — `TargetDiscovery` filters to `.php` + declares-a-class), not silently uncovered.

---

## 12. Phased roadmap to FULL rung-0 autonomy (the loop at its teto, scoped to itself)

The goal: the loop operating at its **maximum behavior** — understand → identify the most-exponential evolution → project (frontier + cross-model critique) → implement → prove with extreme quality → land on `main` — **scoped only to its own code**, then ready to climb. **5 phases.** Each phase ends with a hard gate; nothing in a later phase starts until the gate passes.

**Phase 1 — Safe hands on `main` (the floor).** Slices **-1, 0, 1, 1.5**. Freeze the door + the cert-chain closure; the locked `MergeActuator` (one `flock`, no corruption race); the post-merge `MainHealthSentinel` (git-revert on RED main). **Gate:** two racing PIDs → exactly one commit object; a green-in-isolation/RED-in-combination commit auto-reverted within one watchdog window. *Closes the #1 live risk (`flock=0`); this is the canonical hard-problem #2, never demonstrated.* → the loop can land on `main` autonomously without corruption. *(This is also the first "deslumbre".)*

**Phase 2 — Extreme-quality moat closes (projected + cross-model proof, no proxy).** Slices **8, 9, 10** + feed real `judge_verdicts`. Async pipeline; the projection engine (gpt-5.5 designs ↔ GLM/MiniMax critique, writer≠judge) with content-fixpoint convergence; kill the one-shot smallest-diff tie-break. **Gate:** a CERTIFY-leaning panel cannot lift a deterministic REFUTE; deliveries are projected, not one-shot. → every delivery is designed + cross-model-proven, not the old proxy/cleanup.

**Phase 3 — Recursive-safety Constitution (improve its OWN judge safely).** Slices **2, 3, 4, 4.5, 5** + §11 perf/coverage/dedup batteries where they touch the moat. The `property_gated` no-blinder gate, the FrozenBattery, the candidate-bytes BatteryRunner + the §3.6 sentinel suite, the OutcomeBattery. **Gate:** the §3.6 sentinel suite is green over the reflection-derived closure (a blinder cert is REJECTED with a surviving-bad receipt). → the loop can now safely edit its own quality machinery — *required*, because its own scope **includes** the judge.

**Phase 4 — The decision brain + the full capability set (extreme quality on every work-type).** Slices **6, 7, 7.5** (EV brain: pick the most-exponential target with 2nd-order judgment) + §11.2 **performance** (axis + harness + perf-cert + regression arm) + §11.3 **obra earned-RED bridge** + §11.4 **bug-fix lane** + §11.5 **comprehension grounding** + §11.6 **coverage + dedup**. **Gate:** the loop discovers + delivers + proves each work-type (refactor, feature, perf, bug-fix, coverage, dedup) with **earned RED**, not asserted; a perf candidate is scorable as the binding bottleneck and its speedup is significance-proven. → the loop understands *what* should be done and does it across the full work-type set.

**Phase 5 — Unattended endurance + ready to climb.** Slices **13, 14, 14.5, §K** + the ladder mechanism (**15**) ARMED but scope still rung-0 (research **12** optional). Drift-restart (external, debounced), park-escalation + loop-health metric, the operator `atlas:loop:want` write port, observability + cost on the existing digest/governor. **Gate:** the loop runs **weeks unattended** on its own scope — self-heals, parks-and-continues, never blocks on a human, and the territory ladder is built (not yet promoted). → full rung-0 teto, climb-ready.

**After Phase 5**, climbing to rung ≥1 is *gained by proving quality* (the ladder), not a new design phase — plus the honestly model-bound ceilings of §9 remain the frontier-engine's, not the architecture's.
