# Verification, Release, and Outcomes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan packet-by-packet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compose existing verification organs into one sovereign AcceptanceGate, execute release/canary/rollback through the Governor and MergeActuator, and observe truthful outcomes from 0h through 150d.

**Architecture:** Add at most `VerificationCourtAcceptanceGate implements AcceptanceGate`; it composes existing evidence, false-green, replay, security and role-disposition owners. Keep authority in the Governor, mechanics in `MergeActuator`, events in `atlas_ledger_events`, and outcome projections in existing outcome/temporal tables.

**Tech Stack:** PHP 8.4+, Laravel 13, Engineering Kernel, Self-Construction Verification Court and Merge Governor, Atlas Evidence Ledger, existing outcomes/temporal certification, PHPUnit, mutation/property/chaos fixtures.

## Source contract and dependencies

- Master: `docs/superpowers/plans/2026-07-09-atlas-quality-foundry-world-10x.md`, sections 2, 3, 10, 15 and 16.
- Predecessor: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`, pre-actuation hard-stop, `AuthorizedMergeAction`, release uncertainty and outcome contracts.
- Depends on plan 01 for real Kernel/effect chain; plan 02 for 22 dispositions/evidence honesty; plan 03 for frozen ProductIntent/spec; plan 04 for world/capability hashes.
- Produces governed release and outcome evidence consumed by modes, causal learning and Rivals.

## Global constraints

- Same AcceptanceGate, Governor, release semantics and outcome semantics for Dev, Forge and Autônomos.
- Every delivery has all 22 dispositions; depth/evidence scale by risk without changing membership.
- Missing, stale, hash-mismatched or self-authored required evidence blocks.
- Canary precedes terminal `released`; post-effect uncertainty is `release_uncertain`, never success.
- Governor decides; `MergeActuator` performs one authorized effect. Credentials are ephemeral and scoped.
- Cost/time are measured but cannot offset any quality block.
- No human specialist dependency; unavailable independent verification holds/retries.
- No runtime v3, second Verification Court, release runtime, ledger, outcome store or world model.
- Only Rivals issues/revokes comparative claims. Outcomes and temporal certifications provide evidence only.
- Preserve concurrent WIP on local `main`; no push, deploy or production cutover is authorized by this plan.

## Existing owners to deepen

- Acceptance interface: `app/Services/Ai/EngineeringKernel/AcceptanceGate.php`.
- Evidence/honesty: `AcceptanceBundle`, `SovereignHonestyFloor`, `FalseClaimInvariant`, `OutcomeProofGate`, regression/repair/NFR probes.
- Verification organs: `app/Services/Ai/SelfConstruction/VerificationCourt/**` and existing project-lane verification services.
- Release: `AuthorizedMergeAction`, `MergeActuator`, existing adapters, `app/Services/Ai/SelfConstruction/MergeGovernor/**`.
- Canonical evidence/events: `AtlasEvidenceLedger`, `atlas_ledger_events`.
- Outcomes: `AiRunOutcome`, `AtlasEngineeringOutcomeRecorder`, `AtlasCompoundingOutcomeEvaluator`, `AiTemporalCertification`, `AtlasTemporalCertificationService`.

## Fixed interfaces and state machines

```php
VerificationCourtAcceptanceGate::certify(
    AcceptanceBundle $bundle,
    TrustLevel $trust,
): CertVerdict;
```

This is the sole implementation-facing AcceptanceGate. It composes existing organs and does not gather evidence or execute effects.

Release state machine:

```text
prepare → authorize → act → canary → settle
```

Outcome windows are exactly `0h`, `24h`, `7d`, `30d`, `90d`, `150d`; an unelapsed or missing window remains pending/unknown.

## Allowed subsystem and file families

- `app/Services/Ai/EngineeringKernel/**` limited to acceptance/evidence/authorized effect/outcome seams.
- `app/Services/Ai/SelfConstruction/VerificationCourt/**`
- `app/Services/Ai/SelfConstruction/MultiProject/{AtlasProjectLaneVerificationCourt,AtlasProjectLaneVerificationPolicy,AtlasProjectLaneReleaseGovernor}.php`
- `app/Services/Ai/SelfConstruction/MergeGovernor/**`
- `app/Services/Ai/SelfConstruction/Governance/AtlasTaskMergeActuator.php`
- `app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php`
- `app/Services/Ai/{Aemor,Compounding}/**` and `app/Models/{AiRunOutcome,AiTemporalCertification}.php`
- Existing release/outcome/temporal owner tables; additive N−1-compatible fields/indexes only.
- Matching tests under `tests/{Unit,Feature}/Ai/{EngineeringKernel,SelfConstruction,Aemor,Compounding}/` and architecture/fixture tests.
- Canonical verification/release/outcome owner docs.

For every packet, attach RED and GREEN focused/neighboring outputs, canonical receipt/evidence refs, an explicit migration decision, owner-doc delta (or `docs_unchanged` with reason), local commit, remaining blockers and the named next packet.

---

### Packet 1: VerificationCourtAcceptanceGate composition

**Finding/hypothesis:** Existing gates can each pass while no single sovereign gate checks the complete bundle and 22 conjunctive dispositions. One composition owner prevents false-green without replacing organs.

**Owner:** new `EngineeringKernel\VerificationCourtAcceptanceGate`; existing Verification Court services remain component owners.

**Allowed files:** EngineeringKernel acceptance files, SelfConstruction VerificationCourt services and focused tests. No second court namespace or persistence.

- [x] Write RED tests for absent role, one blocked role, forged N/A, self-verification, stale/mismatched hashes, no assertions, fixed smoke, missing rollback posture and incompatible world/spec/order hashes.
- [x] Write RED composition tests that pass each component in isolation but omit one applicable evidence class from the aggregate bundle.
- [x] Implement the gate as deterministic composition: structural/hash floor → 22 dispositions → evidence applicability → false-green → security/NFR → replay/regression → independent witnesses → release/rollback posture.
- [x] Preserve component reason codes in one `CertVerdict`; any block wins, no averaging or score threshold.
- [x] Emit `acceptance.adjudicated` only after the verdict is persisted; retry with identical bundle hash is idempotent.
- [x] Run AcceptanceGate contract, false-green, disposition, Verification Court evidence/replay and mutation suites.

**GREEN acceptance:** one sovereign verdict covers all required evidence and roles; any red blocks; mode/trust changes witness depth only; author cannot certify.

**Migration/data ownership:** no migration. Verdict receipts use the existing Verification Court verdict ledger/projection and `atlas_ledger_events`; no Verification Court table is added.

**Failure and rollback:** gate outage/unavailable independent verifier yields `held`; disabling enforcement is observe-only and cannot generate promotion.

**Local commit:** `feat: compose verification court acceptance gate`.

---

### Packet 2: Applicable evidence breadth and anti-false-green mutation

**Finding/hypothesis:** Quality Foundry needs more than unit tests, but requiring every evidence type blindly would create ceremony. A deterministic applicability policy can require the right evidence and prove its anti-false-green power.

**Owner:** existing evidence contract and AcceptanceBundle; no new evidence ledger.

**Allowed files:** acceptance/evidence/VerificationCourt families and fixtures/tests.

Applicable evidence types are: unit, integration, contract, E2E, property, mutation, differential, metamorphic, security, privacy, performance, accessibility, chaos, recovery, replay, static analysis, compatibility, migration, rollback and product outcome.

- [ ] Build RED fixtures that seed a real defect detectable by each evidence class and show certification fails when that class is applicable but absent.
- [x] Add RED policy tests for R0–R5, delivery facts and explicit evidence N/A proof; mode cannot change applicability.
- [x] Implement evidence receipts with command/tool version, inputs, assertions, exit/timeout, artifact/hash, scope, observer identity and timestamp.
- [x] Require mutation/property/metamorphic or implementation-independent oracles at R4/R5 as fixed by risk; a test written only to the implementation is insufficient.
- [x] Ensure evidence produced before the final spec/world/file hash is stale and cannot pass.
- [ ] Run each evidence fixture, mutation adequacy, property/differential/metamorphic, security/privacy/NFR and replay suites.

**GREEN acceptance:** each seeded false-green is killed by its applicable evidence; stale/self-authored evidence blocks; N/A requires constitutional proof; no blanket ceremony for genuinely inapplicable classes.

**Migration/data ownership:** evidence bodies/artifacts stay in the Evidence Ledger/artifact store; canonical events and outcome rows contain refs/hashes.

**Failure and rollback:** unavailable required tool/verifier holds the delivery and records the blocker; never substitutes lint or a favorable default.

**Local commit:** `test: prove verification evidence catches false greens`.

---

### Packet 3: Governor authorization and pre-actuation hard-stop

**Finding/hypothesis:** A favorable verdict is not authority. Every land/commit/merge/push/deploy/canary/revert must be impossible without a persisted, current `AuthorizedMergeAction`.

**Owner:** existing Merge Governor and `AuthorizedMergeAction`; `MergeActuator` remains mechanical.

**Allowed files:** EngineeringKernel authorized effect/adapters, SelfConstruction MergeGovernor/Governance and focused tests.

- [ ] Write RED tests for ledger down, stale/revoked/expired authority, nonce replay, changed candidate/base/tree/files, missing verification/rollback hash, wrong lease/fencing token, over-broad credential and direct actuator call.
- [x] Make `prepare` recompute authority/scope/candidate/evidence/rollback, persist decision and replay it before authorization. Evidence: `CanonicalCommitActuationTest` and `PreLandSeamTest`.
- [x] Make `authorize` issue an immutable one-effect capability bound to action, candidate, base/tree, files, lease/fence, receipt and expiry. Evidence: `CanonicalCommitActuationTest` binding-mutation, nonce, lease and persisted-authority cases.
- [ ] Make `act` revalidate every binding and execute exactly one idempotent effect with ephemeral scoped credentials.
- [ ] Add static guards for Git/fs/release/deploy entrypoints outside the allowlist and runtime proof for all mutative surfaces.
- [ ] Run PreLandSeam, MergeActuator, governance fail-closed, architecture bypass and credential-scope tests.

**GREEN acceptance:** every invalid/stale path causes zero effect; replay is idempotent; all modes use the same interface; no direct mutation bypass survives.

**Migration/data ownership:** reuse release decision ledgers and canonical events; no release-authority table is added.

**Failure and rollback:** Governor/ledger outage stops actuation. Never cache an authorization across outage or fall back to v1/direct Git.

**Local commit:** `feat: hard stop unauthorized release effects`.

---

### Packet 4: Canary, settle, rollback and reconciliation

**Finding/hypothesis:** An effect can happen before its receipt settles; treating that as success hides uncertainty. Canary and reconciliation must precede terminal release.

**Owner:** Merge Governor/release governor and `MergeActuator`; outcome writer observes rather than deciding release.

**Allowed files:** release/actuator families, existing canary/rollback adapters, fixture repositories and tests.

- [ ] Write RED tests for act success + settlement write failure, canary timeout/failure, revert failure, process kill after each state, duplicate retry and N−1 incompatibility.
- [x] Implement `act → canary → settle` receipts with the same order/candidate/release hash; canary failure triggers authorized revert or quarantine. Evidence: `CanonicalCommitActuationTest` canary, revert and binding cases.
- [x] Set `released` only after successful canary and settlement. Any uncertain post-effect state is `release_uncertain` and enters reconciliation. Evidence: settlement, inconclusive, ledger-failure and post-effect crash cases.
- [x] Reconcile from ledger plus actual repository/deploy state; never infer from process memory. Evidence: observed-effect crash, failed-revert and replay cases in `CanonicalCommitActuationTest`.
- [ ] Exercise rollback to the compatible N−1 artifact in a fixture/staging environment and prove migrations remain forward-only/additive.
- [ ] Run release/canary/revert/reconciliation, crash boundary, N−1 migration and neighboring outcome tests.

**GREEN acceptance:** canary precedes `released`; failures revert/quarantine truthfully; retries produce zero duplicate effect; crash recovery converges to released/reverted/uncertain with evidence.

**Migration/data ownership:** release/canary/revert events live in `atlas_ledger_events`; existing release/outcome projections store refs/status. No release lifecycle ledger.

**Failure and rollback:** stop new releases, drain/quarantine in-flight runs, execute only an authorized revert and redeploy N−1 if separately authorized. No destructive migration.

**Local commit:** `feat: settle releases through canary and rollback`.

---

### Packet 5: Outcome observations and temporal truth

**Finding/hypothesis:** Immediate green delivery can degrade later. Outcomes must stay correlated, independently observed and unable to synthesize missing time.

**Owner:** existing outcome recorder/evaluator and temporal certification; Rivals consumes evidence but owns claims.

**Allowed files:** Aemor/Compounding services, `AiRunOutcome`, `AiTemporalCertification`, canonical event writer/readers and tests.

Outcome dimensions include defects, incidents, rollback, rework, vulnerabilities/privacy, performance/resilience, adoption/impact, maintenance/simplification and product metric gap.

- [ ] Write RED tests for missing status/source, outcome before release, wrong release hash, duplicate/contradictory observer, missing window, delayed observation, favorable default and immediate outcome treated as temporal.
- [x] Record `outcome.observed` for exactly 0h/24h/7d/30d/90d/150d with observer/provenance, release/order/spec/world hashes and uncertainty. Evidence: `EliteExecutorKernelReadOnlyVerticalTest::test_all_canonical_observation_windows_record_independent_receipts`, plus typed provenance/window validation in `TypedEngineeringContractTest`.
- [ ] Materialize reconstructible `ai_run_outcomes` and temporal projections; absent remains `unknown`, historical gaps `legacy_unproven`.
- [ ] Keep observation windows independent; 24h does not imply 7d and later contradiction supersedes eligibility without rewriting prior observations.
- [ ] Route late adverse outcomes to learning hold and Rivals claim evaluation/revocation; no direct claim mutation.
- [ ] Run outcome default/replay/delayed/contradictory/temporal tests and ledger reconstruction.

**GREEN acceptance:** no missing or premature favorable outcome; all observations correlate to the actual release; late harm removes eligibility and remains auditable.

**Migration/data ownership:** reuse `ai_run_outcomes`, `ai_temporal_certifications` and canonical events. Add only required hash/window/provenance fields with N−1 compatibility.

**Failure and rollback:** observer outage leaves windows unknown/pending; it does not backfill success. Projection mismatch quarantines learning/claim eligibility.

**Local commit:** `feat: observe engineering outcomes through 150 days`.

---

### Packet 6: Cross-mode end-to-end failure matrix

**Finding/hypothesis:** Dev, Forge or Autônomos can diverge at verification/release/outcome boundaries even when they share Kernel code. Equivalent failure injection must yield equivalent decisions.

**Owner:** mode adapters around the shared gate/Governor/outcome owners.

**Allowed files:** cross-mode fixtures and minimal adapters; no mode-specific gate or release engine.

- [ ] Write RED cross-mode failure tests that fail on any divergent verdict, unauthorized effect, false success, replay result or claim eligibility.
- [ ] Run equivalent R0/R3/R5 bundles through all three modes and assert identical applicability, dispositions, verdict, authorization, canary and outcome semantics.
- [ ] Inject ledger/Governor/provider/verifier/canary/revert/outcome failure and process kill at each boundary.
- [ ] Assert zero unauthorized effect, no post-effect false success, safe idempotent replay and identical hold/block/uncertain/revert states.
- [ ] Assert no mode writes a comparative claim and all outcomes default ineligible.
- [ ] Run architecture coverage, full shared subsystem tests and receipt-chain replay.

**GREEN acceptance:** mode parity is proven over success and failure; every effect and terminal state has a correlated receipt; no bypass or claim writer exists.

**Migration/data ownership:** no migration. The packet replays existing acceptance, release, outcome and coverage records.

**Failure and rollback:** any divergence blocks rollout for all modes and returns to observe/sandbox. Do not keep one mode on a weaker path.

**Local commit:** `test: prove shared verification release and outcomes`.

---

### Packet 7: Rollout/readiness without cutover

**Finding/hypothesis:** Passing fixtures establishes implementation readiness, not production cutover or elapsed outcomes.

**Owner:** existing readiness projections.

**Allowed files:** readiness manifests, canonical docs and tests only.

- [x] Write RED readiness-state tests that fail when implementation evidence skips rollback, N−1, coverage, outcome or time gates, or performs a cutover action.
- [ ] Require complete gate composition, anti-false-green mutation, 100% mutative coverage, pre-actuation guards, successful canary/revert drills, N−1 compatibility and active outcome writer for readiness.
- [x] Emit separate states for implementation, cutover readiness and each real observation window.
- [x] Prove state tests cannot jump to `quality_foundry_ready`, `multiplier_proven`, `world_leading` or `world_10x_quality_proven`.
- [ ] Produce live manifests for Kernel/Dev/Forge/Autônomos with real receipt and test refs.

**GREEN acceptance:** the manifest states exactly what is proven and lists unelapsed windows/blockers; no cutover action is performed.

**Migration/data ownership:** no migration. Readiness manifests project existing evidence and do not own lifecycle state.

**Failure and rollback:** failed drill, uncovered surface, missing outcome or migration incompatibility retains `implemented_not_cutover_ready`.

**Local commit:** `feat: expose verification and release readiness`.

## Verification and rollout

Per packet run focused tests, neighboring suites, focused Pint/PHPStan, architecture validation/readiness, docs health and:

```bash
git diff --check -- <packet-files>
```

Rollout order is fixture → sandbox/staging → shadow read-only → separately authorized canary by mode. This plan itself grants no production release or cutover.

## Honest states and blockers

- Missing/stale evidence, self-review, mutation survivor, Governor/ledger outage, unauthorized effect, failed/uncertain canary/revert, absent outcome, unelapsed window, N−1 incompatibility or mode divergence is a blocker.
- `released` requires successful canary and settle. `release_uncertain` is not a synonym for success.
- Temporal states appear only after their real clocks and observations.
- Comparative state remains `world_10x_quality_proof_pending` until Rivals proves otherwise.
- No push, deploy, production cutover or comparative claim is authorized.
