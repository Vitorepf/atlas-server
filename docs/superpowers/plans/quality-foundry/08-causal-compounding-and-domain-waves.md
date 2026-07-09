# Causal Compounding and Domain Waves Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan packet-by-packet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Atlas compound only from causal, outcome-backed learning; reward real simplification and structural leverage; and expand domain capability in evidence-gated waves.

**Architecture:** Add one internal `CausalLearningGate` inside the existing Compounding/Atlas Decide learning path. Deepen current Self-Construction External Brain, Task Fabric, Strategy Council, outcome and simplification owners; reuse Software Twin, canonical outcomes, task queue and Rivals evidence instead of building a learning runtime, duplicate domain graph or duplicate memory.

**Tech Stack:** PHP 8.4+, Laravel 13, existing Compounding, Atlas Decide, Self-Construction External Brain/Task Fabric/Strategy Council services, Software Twin, canonical ledger/outcomes, PHPUnit and causal/statistical fixtures.

## Source contract and dependencies

- Master: `docs/superpowers/plans/2026-07-09-atlas-quality-foundry-world-10x.md`, sections 12, 14, 15 and 16.
- Predecessor: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`, outcome/learning proposal-only and temporal truth rules.
- Depends on plans 01–06 for real executions/outcomes and reversible authority; plan 07 is the sole comparative claim authority and provides claim/evidence feedback.
- Produces governed routing/memory/policy proposals and normal code tasks; it never lands code directly.

## Global constraints

- No promotion without hypothesis, assignment receipt, baseline, metric, comparison, observation window, effect+IC, confounders, rollback and real outcome.
- Correlation without governed assignment is `hold`, not learning.
- Automatically applicable changes are limited to reversible routing, provider/model selection within authority, provider-safe memory policy and reversible operational policy experiments.
- Every code/config/schema change outside that narrow reversible policy envelope returns as a normal governed task through Product/Spec/Kernel/Verification/Governor.
- Simplification earns credit only with behavioral equivalence, preserved consumers/contracts/config, measured complexity reduction and non-inferior outcomes.
- All 22 roles disposition every delivery; depth scales by risk.
- Same learning gate serves Dev, Forge and Autônomos. Mode cannot lower causal evidence.
- Cost/time are measured; quality/outcomes govern. Spend without causal gain is waste.
- Only Rivals issues/revokes comparative claims. Learning may consume claim/evidence state but cannot mint it.
- No runtime v3, duplicate memory, duplicate ledger, duplicate world model, template-farm task generator, human specialist dependency or shell.
- Preserve concurrent WIP on local `main`; no push, deploy, cutover or claim authorization.

## Fixed internal interface

```php
CausalLearningGate::adjudicate(
    CausalLearningCandidate $candidate,
): CausalLearningVerdict;
```

`CausalLearningCandidate` contains schema; candidate/assignment/experiment/run/release hashes; change class (`routing|memory_policy|operational_policy|code_task`); hypothesis; baseline/comparator; metric/quality-loss definition; window; effect/IC; confounders; real outcome refs; authority; rollback; reversibility; affected consumers; and evidence provenance.

`CausalLearningVerdict` is `promote_reversible|hold|reject|emit_code_task|revoke`, with reason codes, evidence/decision hash, expiry/review trigger and `claim_eligible=false`.

## Existing owners to deepen

- Causal/outcome: `app/Services/Ai/Compounding/AtlasCompoundingOutcomeEvaluator.php`, `AtlasTemporalCertificationService`, existing outcome recorders/readers.
- Atlas Decide: live outcome feedback, meta-learning and routing owners.
- External Brain: existing outcome learner/feedback, causal attribution, leverage, proposal-arena, anti-duplication and provider-pool services.
- Task Fabric/Graph/Quality: existing Self-Construction task creation, collision, dependency and acceptance owners.
- Simplification: existing `AtlasSelfConstructionSimplification*` services and External Brain simplification learner/governor.
- World/domain signals: Software Twin/AURG/Code World Model from plan 04.
- Claims: Rivals read-only evidence/claim projections.

## Allowed subsystem and file families

- `app/Services/Ai/Compounding/**`
- `app/Services/Ai/AtlasDecide/**` limited to governed learning/routing seams.
- `app/Services/Ai/SelfConstruction/{ExternalBrain,TaskFabric,TaskGraph,TaskQuality,StrategyCouncil,Simplification,LearningTransfer,Maestro/ClosedLoop}/**`
- Minimal Software Twin/outcome/Rivals read adapters; their owners are not duplicated.
- Existing engineering run, outcome, temporal, task queue and provider-safe memory stores. `atlas_ledger_events` remains event authority.
- Additive fields/indexes only on existing experiment/outcome/task owners when proven necessary.
- Matching tests under `tests/{Unit,Feature}/Ai/{Compounding,AtlasDecide,SelfConstruction}/` and existing service-path tests.
- Canonical learning/Autônomos/Quality Foundry owner docs.

For every packet, attach RED and GREEN focused/neighboring outputs, canonical evidence refs, an explicit migration decision, owner-doc delta (or `docs_unchanged` with reason), local commit, remaining blockers and the named next packet.

---

### Packet 1: Typed causal gate and assignment integrity

**Finding/hypothesis:** Existing learning paths can mistake correlation or favorable outcomes for causation. A single gate with immutable assignment/evidence requirements prevents auto-promotion without proof.

**Owner:** new `Compounding\CausalLearningGate`; existing evaluators supply candidates and consume verdicts.

**Allowed files:** Compounding family, narrow outcome/experiment adapters and focused tests. No learning ledger table.

- [ ] Write RED construction/adjudication tests for missing hypothesis/assignment/baseline/metric/window/effect/IC/confounders/rollback/outcome, post-outcome assignment, hash mismatch, simulated outcome and non-reversible promotion.
- [ ] Add RED causal fixtures for selection bias, regression to mean, concurrent change, novelty/provider drift and outcome lag.
- [ ] Implement immutable candidate/verdict types with deterministic hash and exact verdict set.
- [ ] Verify assignment predates execution and matches run/order/release/outcome; keep intent-to-treat failures in analysis.
- [ ] Return `hold` for correlation/uncertainty, `reject` for invalid evidence, `emit_code_task` for code, and `promote_reversible` only for authorized reversible classes.
- [ ] Emit `learning.adjudicated` to the canonical ledger; idempotent replay produces the same verdict.
- [ ] Run focused causal gate, outcome/temporal, assignment replay and adversarial confounder tests.

**GREEN acceptance:** every invalid/correlational fixture holds/rejects; only complete causal reversible evidence promotes; no simulation or code change applies directly.

**Migration/data ownership:** assignment/learning events use `atlas_ledger_events`; candidates reference existing experiment/outcome artifacts. No causal ledger.

**Failure and rollback:** gate or ledger outage disables promotion and queues candidates for replay. Never use a favorable fallback.

**Local commit:** `feat: gate learning on causal outcome evidence`.

---

### Packet 2: Reversible promotion, expiry and late revocation

**Finding/hypothesis:** Even causally supported routing/memory/policy changes can regress later. Promotion must be scoped, reversible, expiring and monitored.

**Owner:** Atlas Decide routing/meta-learning and provider-safe memory/policy owners behind the CausalLearningGate.

**Allowed files:** Compounding, AtlasDecide learning/routing and focused ExternalBrain memory/policy adapters/tests.

- [ ] Write RED tests for change class spoofing, authority escalation, irreversible config/schema/code application, promotion without rollback, stale verdict reuse and late adverse outcome ignored.
- [ ] Bind promoted reversible changes to exact scope, authority, version, expiry, rollback and observation schedule.
- [ ] Apply through existing routing/memory/policy owners only; persist before/after and effect receipt in canonical events.
- [ ] Monitor real outcomes and calibration; late regression invokes gate `revoke` and restores the last proven reversible state.
- [ ] Convert code/config/schema proposals outside the reversible envelope into normal Task Fabric packets with Product/Spec/Kernel gates.
- [ ] Run authority, replay, rollback, expiry, late-regression and no-direct-code-promotion tests.

**GREEN acceptance:** reversible promotion is atomic/idempotent, scoped and rollback-capable; late harm revokes; code always becomes a governed task.

**Migration/data ownership:** use existing routing/memory/policy stores plus canonical events; no promotion ledger.

**Failure and rollback:** restore prior version, quarantine candidate and retain negative evidence. Failure to restore is `release_uncertain`/hard stop, not continued experimentation.

**Local commit:** `feat: make causal learning reversible and revocable`.

---

### Packet 3: Domain map, structural leverage and outcome memory

**Finding/hypothesis:** Task volume does not compound capability. The existing External Brain needs a domain map and structural ranking driven by recurring outcomes and dependency leverage.

**Owner:** existing Software Twin signals plus Self-Construction External Brain/Task Graph/Strategy Council; no duplicate graph.

**Allowed files:** ExternalBrain, TaskGraph, StrategyCouncil and read adapters to Software Twin/outcomes; focused tests.

- [ ] Write RED fixtures where high-volume shallow tasks outrank a low-volume dependency bottleneck, stale domain facts dominate, repeated failures are forgotten or outcome-free work receives credit.
- [ ] Project a domain map from Software Twin/world facts: capabilities, owners, dependencies, consumers, recurrence, failures/outcomes, maturity, evidence gaps and freshness.
- [ ] Extend existing structural-leverage comparator/ranker to score dependency reach, recurrence, outcome gap, simplification opportunity, verification strength and blast radius.
- [ ] Feed real outcome memory and recurrence maps into ranking; missing/stale outcomes lower confidence rather than becoming success.
- [ ] Emit ranked proposal evidence into existing Proposal Arena/Task Fabric; do not create tasks merely to fill a quota.
- [ ] Run structural-leverage, outcome-weighted critical-path, recurrence/freshness and deterministic ranking tests.

**GREEN acceptance:** structural bottlenecks beat shallow volume under fixtures; rankings cite fresh world/outcome evidence and replay deterministically; healthy queue depth changes urgency, not whether leverage work is originated.

**Migration/data ownership:** domain map is a projection over Software Twin/task/outcome data, not a new graph/table. Cache only via existing projection owners with source hashes.

**Failure and rollback:** stale/unavailable world/outcome data yields low-confidence hold or bounded safe work; never synthesize leverage.

**Local commit:** `feat: rank Atlas evolution by structural leverage`.

---

### Packet 4: Proposal competition and anti-template-farm quality

**Finding/hypothesis:** A proposal generator can create cosmetically different tasks that share one template and do not advance a capability. Competition plus novelty/coherence gates can reject farms.

**Owner:** existing `AtlasExternalBrainProposalArena`, Task Fabric template similarity/novelty/quality services and Strategy Council.

**Allowed files:** ExternalBrain, TaskFabric, TaskQuality and StrategyCouncil families/tests.

- [ ] Write RED batches of paraphrased duplicate tasks, one-line spec/data farms, disconnected allowed-files, unverified macro claims, no baseline/delta/rollback and collision with active work.
- [ ] Require 2–3 proposals when the risk/topology warrants competition; each carries finding, baseline, expected structural delta, allowed files, RED behavior, GREEN acceptance, rollback and outcome metric.
- [ ] Score novelty against current/live/history tasks and capability delta, not lexical difference alone.
- [ ] Require proposal competition to choose independent approaches; no-frontier-improvement ends a line and reopens the mission.
- [ ] Pass winners through existing Task Fabric collision/dependency/quality gates; `dry`/`disabled` rotates to another leverage vein unless a true hard stop exists.
- [ ] Run template similarity, novelty, macro-value, collision, proposal arena and dry-rotation tests.

**GREEN acceptance:** duplicate/template-farm batches are rejected; accepted tasks are implementation-ready, collision-safe and traceable to structural/outcome evidence.

**Migration/data ownership:** reuse task queue/history and evidence refs; no proposal ledger or duplicate task registry.

**Failure and rollback:** quarantine malformed/poison batches, preserve negative examples and continue another eligible vein. Never lower the spec bar to maintain supply.

**Local commit:** `feat: reject template farms through proposal competition`.

---

### Packet 5: Simplification-first causal credit

**Finding/hypothesis:** Deleting or consolidating code can be rewarded without proving equivalence or consumer safety. Existing simplification owners need a causal credit gate.

**Owner:** current Self-Construction Simplification services and External Brain simplification learner, adjudicated by CausalLearningGate.

**Allowed files:** SelfConstruction Simplification/ExternalBrain/TaskQuality owners, Software Twin consumer readers and focused tests.

- [ ] Write RED fixtures for line-count reduction that changes behavior, removes a consumer/config/contract, shifts complexity, reduces tests only, creates a hidden duplicate or degrades late outcomes.
- [ ] Require pre-registered equivalence oracles, consumer/reachability map, contract/config/API/schema compatibility and rollback.
- [ ] Measure complexity change using at least structural ownership/dependency/cyclomatic/config surface appropriate to the change; line count alone is insufficient.
- [ ] Credit simplification only after focused/neighboring/architecture tests, outcome observation and non-inferiority; route credit through CausalLearningGate.
- [ ] Feed proven simplification into leverage/outcome memory; failed simplification becomes negative evidence and a repair task.
- [ ] Run duplicate-organ, no-gap proof, outcome-credit, reachability/consumer, compatibility and late-regression tests.

**GREEN acceptance:** only behaviorally equivalent, consumer-safe, truly lower-complexity and non-inferior work receives credit; cosmetic deletion does not.

**Migration/data ownership:** simplification evidence uses existing task/run/outcome/world refs and canonical events. No simplification ledger.

**Failure and rollback:** authorized revert/N−1 restores behavior; failed credit is revoked and candidate quarantined.

**Local commit:** `feat: grant causal credit for real simplification`.

---

### Packet 6: Drift detector, benchmark-gap generator and frontier observer

**Finding/hypothesis:** Capability can decay or frontier baselines can move after a promotion. Continuous observers must generate evidence-backed work without issuing claims.

**Owner:** existing outcome/temporal, Software Twin, External Brain and Rivals read adapters.

**Allowed files:** ExternalBrain/Compounding/AtlasDecide readers and focused tests; Rivals remains read-only unless an agreed evidence API is missing.

- [ ] Write RED fixtures for model/provider/tool/harness drift, declining calibration, outcome regression, benchmark frontier movement, expired claim and alert storm duplicating tasks.
- [ ] Compare frozen route/world/claim versions to current facts and real outcomes; distinguish expected drift, unknown and material regression.
- [ ] Generate a benchmark-gap or repair proposal with exact evidence, scope, risk, invalidator and outcome metric through Proposal Arena/Task Fabric.
- [ ] Deduplicate/merge recurring gaps and respect active claims/reservations; never mutate claims or routes directly.
- [ ] Revoke reversible learning through CausalLearningGate when evidence crosses the frozen threshold; ask Rivals to evaluate claim revocation.
- [ ] Run drift, expiry, recurrence dedupe, benchmark-gap and claim-authority tests.

**GREEN acceptance:** material drift produces one governed evidence-backed response; unknown stays unknown; no observer issues a claim or direct code change.

**Migration/data ownership:** observers project existing world/outcome/claim/task data; no drift or frontier ledger.

**Failure and rollback:** observer outage marks freshness stale and blocks new promotion; it never certifies stability.

**Local commit:** `feat: turn capability drift into governed work`.

---

### Packet 7: Evidence-gated domain waves

**Finding/hypothesis:** Broad domain support cannot be claimed from a few tasks. Each wave must earn promotion with corpus, private benchmark, oracle coverage, causal multiplier, soak, outcomes and non-regression.

**Owner:** existing domain profiles as operating systems, Capability Market, Quality Foundry and Rivals evidence; no mode/provider fork.

**Allowed files:** domain profile/config owners, existing adapters/skills/runtime seams, fixtures/tests, readiness docs; no parallel domain runtime.

Wave order is fixed:

1. Atlas + full-stack enterprise: PHP/Laravel, TypeScript/React, APIs, PostgreSQL, queues, auth, migrations, observability, CI/CD, product/UX and external repos.
2. Polyglot/data: Python, Go, Java/Kotlin, Node, Rust, pipelines, streaming, search and moderately distributed systems.
3. Mobile/desktop: iOS, Android, Expo/React Native, Flutter, desktop, offline/sync, accessibility/performance.
4. Critical infrastructure: cloud, SRE, security/privacy, high-risk migrations, incident response, distributed systems and disaster recovery.
5. Specialized: ML systems, compilers, embedded, real-time, HPC and regulated domains.

- [ ] Define a versioned readiness manifest for each wave: corpus coverage, hidden/private cases, independent oracles, capability routes, risk depths, rollback/DR, causal multiplier evidence, real soak/outcomes and prior-wave non-regression.
- [ ] Write RED state tests for promotion with a missing manifest dimension, public-only benchmark, simulated soak, narrow provider, no rollback, lower prior-wave quality or absent Rivals evidence.
- [ ] Implement only the adapters/profile deltas needed for the current wave through existing provider/tool/Kernel contracts; no domain-specific executor fork.
- [ ] Execute hermetic fixtures and separately authorized private campaigns; feed evidence to Rivals.
- [ ] Promote one wave only after every conjunctive gate passes; keep future waves unpromoted and explicit.
- [ ] Re-run prior-wave regression and claim-expiry checks after each provider/tool/frontier change.

**GREEN acceptance:** wave state exactly matches evidence; missing causal/private/outcome/soak proof blocks promotion; prior waves do not regress.

**Migration/data ownership:** reuse profiles, world snapshots, task/run/outcomes and Rivals artifacts; no wave ledger/database.

**Failure and rollback:** disable the wave adapter/profile version and return to the last proven profile; preserve failed campaign evidence and generate repair work.

**Local commit:** one atomic local commit per wave, beginning with `feat: qualify Quality Foundry domain wave 1` only when Wave 1 implementation actually starts.

---

### Packet 8: End-to-end compounding proof and honest readiness

**Finding/hypothesis:** A working collection of learning services does not prove compounding. A longitudinal fixture must show evidence → causal decision → reversible change/task → later outcome → retain/revoke with no claim leak.

**Owner:** CausalLearningGate and existing projections.

**Allowed files:** cross-subsystem fixture tests, readiness projection and canonical docs.

- [ ] Run a positive reversible-routing fixture with preregistered assignment and observed gain; assert scoped promotion and later retain.
- [ ] Run a confounded fixture; assert hold and zero change.
- [ ] Run a code proposal; assert governed task emission and zero direct application.
- [ ] Run a late-regression fixture; assert route rollback, negative outcome memory, repair proposal and Rivals revocation request.
- [ ] Run a template-farm/simplification/domain-wave fixture; assert every gate above.
- [ ] Produce a readiness manifest with live evidence and explicit temporal/comparative gaps.

**GREEN acceptance:** compounding is causal, reversible, leverage-oriented and claim-safe in fixtures; real causal multiplier/domain promotion remains pending until real campaigns/outcomes satisfy the gates.

**Migration/data ownership:** no migration. The fixture replays existing assignment, learning, task, route, outcome and claim-request records.

**Failure and rollback:** any direct code promotion, correlation promotion, missing rollback, claim write, template-farm acceptance or false domain promotion blocks readiness.

**Local commit:** `test: prove causal compounding control loop`.

## Verification and rollout

Each packet runs focused and neighboring suites, focused Pint/PHPStan, architecture/claim-authority validation, docs health and:

```bash
git diff --check -- <packet-files>
```

Rollout is observe-only candidate adjudication → shadow reversible decisions → limited sandbox policy experiments → separately authorized mode rollout. Code tasks use the ordinary factory; domain campaigns and claims remain Rivals-governed.

## Honest states and blockers

- Causal-learning component readiness, compounding fixture proof and domain-wave readiness are evidence phases, not new official delivery states and not comparative claims.
- Missing assignment/baseline/IC/outcome/rollback, confounding, irreversible autoapply, direct code promotion, stale world/outcome data, template farm, fake simplification, drift ignored, missing private corpus/oracles/soak or prior-wave regression is a blocker.
- Healthy supply or `sufficient_depth` changes origination urgency only; it never stops leverage-first evolution.
- Before real trials and outcomes, use `world_10x_quality_proof_pending`.
- This plan authorizes no push, deploy, production cutover, domain promotion or claim.
