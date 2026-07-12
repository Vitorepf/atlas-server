# Rivals World Engineering Trial Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan packet-by-packet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Rivals the sole, replayable authority for scoped causal and world-quality claims using preregistered private trials, independent machine adjudication and real downstream outcomes.

**Architecture:** Deepen the existing `app/Services/Ai/Rivals` product, state machine, adapters, evidence pack, result ledger, statistics, adjudicator and report builder. Public benchmarks remain diagnostics; the claim engine consumes a rotating private corpus and canonical Atlas evidence without creating another execution Kernel, ledger, world model or outcome system.

**Tech Stack:** PHP 8.4+, Laravel 13, existing Rivals core/adapters/artifacts, Engineering Kernel receipts, Atlas Evidence Ledger, PHPUnit, external benchmark adapters and statistical fixtures.

## Source contract and dependencies

- Master: `docs/superpowers/plans/2026-07-09-atlas-quality-foundry-world-10x.md`, sections 2.5, 3.5, 13, 15, 16 and 18.
- Predecessor: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`, Task 14 and comparative-proof criteria.
- Depends on plans 01–06 for real Kernel/mode executions, frozen units, independent evidence, governed release, outcomes and causal assignment receipts.
- Rivals work is concurrently active on local `main`. Packet 1 must reconcile current claims/WIP and tests before any implementation edit; never overwrite or reformat another worker's files.
- Produces claim bundles/events consumed as read-only projections by the rest of Atlas.

## Global constraints

- Rivals alone may evaluate, issue, expire or revoke `multiplier_proven`, `world_leading`, `world_10x_quality_proven` and any `world_*` claim.
- Trial state machine is exactly `preregister → freeze units → execute arms → ingest → adjudicate → observe → issue|reject|revoke`.
- Public benchmarks diagnose; private rotating, fresh/time-sliced units govern claims.
- Hidden tests/golds remain outside candidate workspaces; egress is restricted; contamination canaries and invalidation rules are preregistered before unblinding.
- No live human judge or specialist is required. Historical accepted human artifacts are an optional baseline, not a runtime dependency.
- Intent-to-treat includes failure, timeout, refusal, rollback and invalid execution in denominators.
- Resource/model/harness versions and unit snapshots are frozen and comparable. No best-run cherry-pick.
- Cost/time are measured; quality loss governs. No critical dimension may be worse than the best baseline.
- No runtime v3, second Kernel, duplicate ledger, duplicate outcomes, duplicate world model or generic claim ledger.
- Dev, Forge and Autônomos receive separate scoped claims by mode/stack/risk/duration.
- Claims expire after 90 days and revalidate after material frontier, harness or regression change.
- Preserve concurrent WIP; no push, deploy, cutover or claim issuance during plan implementation without completed evidence and separate authority.

## Existing owners to deepen

- Orchestration/state: `RunPlan`, `RunReceipt`, `RunStateMachine`, `AtlasUpliftRunner` and current CLI adapters.
- Preregistration/statistics: existing `Preregistration`, `StatisticalPolicy`.
- Arms/models/suites: `ArmRegistry`, `ModelRegistry`, `SuiteRegistry`, current external and local adapters.
- Safety/corpus: `ContaminationGuard`, `BenchmarkRepoManager`, `RunPaths`, current fixture/corpus owners.
- Evidence/replay: `EvidencePackBuilder`, `BundleManifest`, `ReplayVerifier`, `ResultLedger`.
- Adjudication/report/claims: `Adjudicator`, `ReportBuilder`, `ClaimTier` and canonical claim events.
- Atlas sources: frozen order/spec/world/assignment receipts, 22 dispositions, release/outcome events from existing owners.

## Arms and claim rule

Every eligible trial freezes these arms where available:

1. same model bare;
2. same model with Atlas;
3. strongest competitor in its native harness;
4. frontier model bare;
5. accepted historical human artifact, when available;
6. Atlas full-power.

Quality loss includes escaped defects, vulnerabilities/privacy, regressions, rejection, rollback, rework, incidents/user impact, future maintenance, outcome gap and uncertainty represented as certainty. Weights/normalization are preregistered and identical across arms.

`world_10x_quality_proven` requires:

```text
upper_IC95(quality_loss_atlas / quality_loss_best_baseline) <= 0.10
```

plus no critical dimension inferior, sufficient exposure, intent-to-treat, and no zero-baseline division. The predecessor's throughput/time/security/NFR constraints remain additional conjunctive evidence where that claim includes them.

## Allowed subsystem and file families

- `app/Services/Ai/Rivals/**`
- `app/Console/Commands/AtlasRivalsCommand.php` only as a thin adapter.
- `config/atlas_rivals.php` for explicit versioned policy, never secret corpus content.
- Existing Rivals scripts/adapters under `scripts/rivals*` and matching hermetic fixtures.
- `tests/{Unit,Feature}/Ai/Rivals/**` and Rivals fixture/case families.
- Existing Rivals artifacts/run directories via `RunPaths`; canonical Atlas evidence/outcome tables are read-only inputs.
- Architecture guards outside Rivals only to enforce sole claim authority.
- Canonical Rivals owner docs/runbook.
- No new generic trial/claim/event database. If the current ResultLedger/artifact contract cannot satisfy atomic/replay needs, deepen it in place.

For every packet, attach RED and GREEN focused/neighboring outputs, content-addressed evidence refs, an explicit migration decision, owner-doc/runbook delta (or `docs_unchanged` with reason), local commit, remaining blockers and the named next packet.

---

### Packet 1: Reconcile active Rivals WIP and freeze the baseline

**Finding/hypothesis:** Current local Rivals files are modified/untracked by another stream; editing from an assumed baseline risks destroying work and invalidating trial evidence.

**Owner:** current Rivals workstream plus this plan's implementer after blackboard coordination.

**Allowed files:** read-only inspection of all Rivals files first; edits only after claims are released/coordinated and a packet-specific scope is agreed.

- [x] Record RED behavior for every partial/absent master requirement and every failing baseline test; missing live proof remains RED even when a class/file exists.
- [x] Check blackboard for every intended Rivals path, inspect `git status`, diff/index/untracked artifacts and recent commits, and record ownership/conflicts without staging or reverting them.
- [x] Run the current focused Rivals unit/feature suites and capture exact baseline failures; do not fix unrelated failures in this packet.
- [x] Inventory existing implementations of preregistration, run state, suites/arms/models, contamination, evidence pack/replay, statistics, adjudication, claims and reports.
- [x] Map each master requirement to `implemented_and_proven`, `implemented_unproven`, `partial`, or `absent`; prefer existing owners and delete no concurrent work.
- [x] Freeze schema/version hashes and current golden fixtures for later RED tests.
- [x] Obtain non-conflicting file claims for Packet 2 before implementation.

**GREEN acceptance:** the implementation packets have exact owners/paths, no concurrent change was overwritten, and baseline test/evidence gaps are recorded.

**Migration/data ownership:** none.

**Failure and rollback:** any unresolved cross-engine claim blocks edits to that path. Continue on independent files or wait; never steal/revert.

**Local commit:** documentation/baseline evidence only when it belongs to canonical Rivals docs; otherwise no commit.

---

### Packet 2: Preregistration, frozen units and contamination resistance

**Finding/hypothesis:** A benchmark run cannot support claims if units/resources/metrics/invalidation can change after seeing results or if hidden data leaks into candidate workspaces.

**Owner:** existing `Preregistration`, `RunPlan`, `RunStateMachine`, `ContaminationGuard`, `BenchmarkRepoManager` and `RunPaths`.

**Allowed files:** those Rivals core/support/benchmark owners, versioned config, hermetic fixtures/tests.

- [x] Write RED tests for run without preregistration, mutable unit after freeze, hidden gold inside workspace, unrestricted egress, contamination canary hit, known/public memorized unit, post-unblinding invalidation and unequal resource pinning.
- [x] Require preregistration before run creation: hypothesis, arms, unit/repo/time slice, metric/weights, outcome windows, power, exclusion/invalidation, resources, versions and analysis plan.
- [x] Freeze case/repo as the statistical unit with immutable hashes and nested repetition IDs. `FrozenUnitManifest` persists the preregistration-bound case/base/golden/hidden-test hashes and repetition IDs; non-harness runs cannot enter `native_running` without it.
- [x] Materialize candidate workspace without hidden tests/golds; mediate egress and inject contamination canaries.
- [x] Rotate/renew private cases and alternative solutions; invalidate only under preregistered rules before unblinding.
- [x] Run contamination/security, RunPaths traversal, preregistration/state-machine and deterministic freeze/replay tests.

**GREEN acceptance:** no execution starts before immutable preregistration/unit freeze; hidden data cannot enter candidate context; contamination/invalidation is detectable and replayable.

**Migration/data ownership:** preregistration/unit manifests are Rivals artifacts addressed by hash and referenced in canonical events; no corpus table.

**Failure and rollback:** contaminated/invalid units are excluded under the frozen rule and remain in ITT audit. Quarantine the run; do not rewrite results.

**Local commit:** `feat: freeze and protect Rivals trial units`.

---

### Packet 3: Comparable arms and intent-to-treat execution

**Finding/hypothesis:** Harness/resource drift or omission of failed runs can manufacture uplift. All arms need equivalent snapshots/budgets and complete execution receipts.

**Owner:** existing arm/model/suite registries, adapters, uplift runner and Kernel/Rivals bridges.

**Allowed files:** Rivals core registries/runner/adapters/scripts and focused tests. Shared Atlas services are read-only evidence sources unless a narrow adapter contract is missing.

- [x] Write RED matrix tests for missing same-model bare control, changed model/provider version, unequal compute/tool/egress/time budgets, Atlas mutation executed twice, competitor outside native harness and failure omitted from ledger.
- [x] Build the six-arm plan where available and explicitly mark unavailable historical-human baseline without blocking the other arms.
- [x] Pin models/providers/harnesses/tools/resources and frozen unit snapshot per run; record operator interventions and all failures/timeouts/refusals/rollbacks.
- [x] Execute Atlas arms through the real shared Kernel and bare/competitor arms through isolated native adapters; do not let one arm see another's artifact/gold.
- [x] Import normalized results with source/native receipt hashes and retain raw immutable artifacts.
- [ ] Run adapter contract, native import, ten-suite/external loop, failure-classification, ITT and replay tests. <!-- blocked: ExternalTenSuite/ExternalLoop pipeline tests currently RED on frozen_unit_manifest freeze-fixture gap owned by the concurrent NativeResultNormalizer/RunPaths WIP stream; adapter-contract + native-import + ITT + replay all green. -->

**Owner note (this session):** the six-arm plan builder (`SixArmPlanBuilder`), comparable-arms guard (`ArmComparability`: same-model bare control, provider-version pin, equal per-arm budgets) and `FailureClass::ROLLBACK` landed with `ComparableArmsMatrixTest`. The final ten-suite/external-loop pipeline run remains blocked on concurrent WIP freeze fixtures — never overwrite another worker's files.

**GREEN acceptance:** arms are comparable and isolated; every assigned unit appears in the denominator; raw/native/normalized artifacts correlate; no best-run filtering.

**Migration/data ownership:** ResultLedger/artifact manifests remain Rivals ownership; Atlas execution events are referenced, not copied into another ledger.

**Failure and rollback:** stop/drain a run and preserve partial ITT records. Resume only under the same preregistration/resource hashes; otherwise create a new run.

**Local commit:** `feat: execute comparable Rivals trial arms`.

---

### Packet 4: Independent machine adjudication and real outcomes

**Finding/hypothesis:** Tests alone or author-authored explanations can miss defects. Adjudication must combine independent investigators/oracles and downstream outcomes without live human judges.

**Owner:** existing `Adjudicator`, `EvidencePackBuilder`, `ReplayVerifier` and Atlas outcome readers.

**Allowed files:** Rivals evidence/replay/adjudication/report owners and focused tests.

- [x] Write RED cases for judge seeing author defense, same-model-family author/judge at R4/R5, hidden test omitted, implementation-dependent oracle, security/replay failure ignored, missing outcome scored green and contradictory outcome ignored.
- [ ] Compose hidden, property, differential/metamorphic and implementation-independent tests; independent model investigators; security/privacy/performance/replay; historical artifact comparison; and real 0h–150d outcomes. <!-- partial: AdversarialAdjudicationGate composes hidden + implementation-independent + security/replay + required observed/elapsed outcome and records judge disagreements; the live 0h–150d outcome READER over canonical EngineeringOutcome (referenced by release/run hash) and historical-artifact scoring are the packet-6 outcome-wiring half, still open. -->
- [x] Give judges only frozen intent/spec/unit, artifact and evidence. Record judge family/version and disagreements.
- [x] Require 22 role dispositions and no critical block; unknown/unelapsed outcomes retain uncertainty and claim ineligibility.
- [x] Build a content-addressed evidence pack and replay it from raw artifacts to the same adjudication hash.
- [x] Run evidence pack, replay, adversarial adjudication, judge-independence and delayed/contradictory outcome tests.

**GREEN acceptance:** adjudication is independent, conjunctive and replayable; missing outcome/evidence cannot pass; disagreement/uncertainty is explicit.

**Migration/data ownership:** evidence pack stays Rivals artifact storage; outcome data remains canonical Atlas ownership and is referenced by release/run hash.

**Failure and rollback:** judge/provider outage holds adjudication; no human specialist fallback and no partial claim.

**Local commit:** `feat: adjudicate Rivals evidence independently`.

---

### Packet 5: Statistical policy, power and multiplicity

**Finding/hypothesis:** Point estimates and best runs can support false world claims. Precomputed power, hierarchical analysis, uncertainty and multiple-comparison control are mandatory.

**Owner:** existing `StatisticalPolicy`, adjudicator/report integration.

**Allowed files:** Rivals statistical/core/report services and deterministic statistical fixtures/tests.

- [x] Write RED fixtures for underpowered sample, unit/repetition pseudoreplication, attrition exclusion, zero denominator, best-run choice, multiplicity without correction, wide interval, one campaign only and critical-dimension regression.
- [x] Require power ≥90% before the run; case/repo is unit and repetitions are nested.
- [x] Implement intent-to-treat estimates with hierarchical bootstrap or mixed effects; time-to-event via survival/RMST; counts via Poisson/negative-binomial/event limits as preregistered.
- [x] Apply Holm or frozen hierarchical testing; report effect, IC95, exposure, attrition and sensitivity analyses.
- [x] Require three campaigns and no best-run cherry-pick for world claims. <!-- three-campaign gate lives in WorldTrialReadiness; no-best-run enforced structurally (Adjudicator exact-set equality) and by sensitivity.best_case never being the claim basis; public-claim wiring lands with packet 6. -->
- [x] Validate statistical routines against fixed reference fixtures and property tests; replay yields byte-stable policy/result hashes.

**GREEN acceptance:** every false-claim fixture rejects; adequate fixtures reproduce expected estimates/intervals/corrections; uncertainty never becomes a favorable default.

**Migration/data ownership:** statistical outputs are versioned Rivals artifacts linked to preregistration/evidence hashes.

**Failure and rollback:** underpowered/invalid analyses issue `claim_rejected` with reasons. Never relax thresholds post hoc.

**Local commit:** `feat: enforce Rivals statistical proof policy`.

---

### Packet 6: Sole claim issue, expiry and revocation

**Finding/hypothesis:** Even valid statistics can be overgeneralized across mode/stack/risk/duration or survive frontier regression. Claim bundles must be scoped, expiring and revocable.

**Owner:** Rivals `ClaimTier`, `Adjudicator`, `ReportBuilder` and canonical claim events.

**Allowed files:** Rivals claim/report owners, architecture sole-authority guard and tests.

- [ ] Write RED tests for issuer outside Rivals, missing scope/baseline/evidence/experiment/exposure/IC, universal claim, claim before outcome, claim older than 90d, material frontier/harness change and late adverse outcome.
- [ ] Define claim bundles with claim level, exact mode/stack/risk/duration/unit population, baseline, metric/weights, effect/IC, campaigns/exposure, evidence pack, issued/expiry timestamps and invalidators.
- [ ] Allow issue only after state-machine completion and all conjunctive gates; emit `claim.evaluated` then `claim.issued|rejected`.
- [ ] Expire at 90 days and require revalidation after material frontier/harness/regression change.
- [ ] Consume contradictory/late outcomes and emit `claim.revoked`; never delete the original claim.
- [ ] Ensure all non-Rivals services are read-only claim consumers and cannot mutate state.
- [ ] Run claim-tier, report, architecture authority, expiry/revocation and full evidence replay tests.

**GREEN acceptance:** only Rivals writes claim events; claims are narrow, evidence-backed, expiring and revocable; projections reconstruct from canonical events/artifacts.

**Migration/data ownership:** claim bundle is a Rivals content-addressed artifact; claim events are canonical `atlas_ledger_events`. No duplicate claim ledger.

**Failure and rollback:** claim service outage means no new/renewed claim. Expiry proceeds honestly; stale projections cannot renew.

**Local commit:** `feat: issue scoped expiring Rivals claims`.

---

### Packet 7: Mode frontiers, campaigns and honest trial readiness

**Finding/hypothesis:** The trial is not world-ready until each mode reaches sufficient diverse exposure and real outcome windows.

**Owner:** Rivals run planner/readiness/reporting.

**Allowed files:** Rivals planning/report/readiness docs/tests; no production claim issuance by this implementation packet.

Initial frontiers:

- Dev: approximately 150 distinct tasks, with power analysis taking precedence.
- Forge: at least 30 Obras.
- Autônomos: 150 real days and sufficient exposure.

- [ ] Build campaign manifests spanning stack/risk/duration and private rotating units for each mode.
- [ ] Prove readiness blocks on insufficient power/exposure, missing 30d outcome, contamination, incomplete ITT, absent critical dimension or fewer than three campaigns.
- [ ] Run a hermetic dry trial end-to-end through issue/reject/revoke using synthetic fixtures; assert no production claim event.
- [ ] Produce a readiness report that separates implemented trial, active campaign, elapsed outcome and eligible claim.

**GREEN acceptance:** trial machinery is replayable and ready at the tested scope; real frontier claims remain pending until actual exposure/outcomes/campaigns exist.

**Migration/data ownership:** no migration. Campaign/readiness manifests remain Rivals artifacts referencing existing canonical runs, events and outcomes.

**Failure and rollback:** pause/quarantine affected campaign, retain ITT data and rotate future units under a new preregistration. Never issue from synthetic/dry data.

**Local commit:** `test: prove Rivals world trial readiness`.

## Verification and rollout

Per packet run focused and neighboring Rivals suites, adapter contract tests, full evidence replay, focused Pint/PHPStan, architecture claim-authority guard, docs health and:

```bash
git diff --check -- <packet-files>
```

Rollout is synthetic/hermetic trial → private shadow campaign → separately authorized real campaign. Claims remain disabled until real preregistered evidence and observation windows satisfy every gate.

## Honest states and blockers

- Trial implementation, active campaign and completed adjudication are component phases, not new official delivery states; none is a claim.
- Concurrent WIP conflict, contamination, unblinding error, unequal resources, missing bare control, incomplete ITT, missing independent oracle/judge, underpower, wide IC, critical regression, unelapsed outcome, insufficient campaigns/exposure or stale claim is a blocker.
- Before sufficient real evidence, the only correct comparative state is `world_10x_quality_proof_pending`.
- This plan authorizes no push, deploy, production cutover or unsupported claim.
