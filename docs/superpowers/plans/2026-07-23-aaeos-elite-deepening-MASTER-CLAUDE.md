# AAEOS — Mother Block of Agentic Software Engineering (Claude independent MASTER)

> Author track: Claude (Opus). Independent competitor to the Codex `…-MASTER.md`. The operator compares and picks/fuses.
> Version: C4 — C3 hardened by a 13-lens self-audit + security-max pass. Fixed my own under-scopings: enforced author≠judge≠governor at the release mint (independence attestation, not DOC_ONLY); propagated REPAIR_REQUIRED to its 2 const-consumers; named the full seed-enqueue seam; decoupled the P4 readback veto from the PHPUnit mutation runner (category mismatch); added the 3rd runtime_write_performed site + fixed a nonexistent test citation; owned the B2 pre-authorize ceiling; demoted memory/recovery from falsifiable-M to horizon. All owners re-verified; zero disk drift. (C3 was: C2 corrected by a buildability+red-team pass.) Fixed mis-named owners: evidence readback (`EvidenceLedgerHashChainIntegrityVerifier`+`AtlasLedgerReplayService`, not the readiness replayer), admission verdict (new `AaeosAdmissionVerdict::REPAIR_REQUIRED`, not cross-subsystem `DECISION_REPAIR`), seed seam (`AtlasSelfConstructionNativeReplenisherEnqueueRunner`), effect nonce (LAND `AuthorizedMergeAction::nonce` + SETTLE). Split enforced-vs-consulted M (ledger `governed` folds advisory). Gut the `god_sota>=9.0` self-grader before projecting views. Re-derive-from-world REAL_OPERATION + mutation-veto owner. Imported Codex R95/R100 sovereign witnesses. Added Named-RED test discipline + `runtime_write_performed` derivation source. All owners re-verified on disk. (Prior: C1 authored, C2 self-hardened.)
> Date: 2026-07-23 · State: PLAN_ONLY · P0–P4 NOT_STARTED · code needs literal `EXECUTE P0`.
> Branch: local `main` only; scoped commits; never `git add -A`.
> Every disk claim here was verified by direct read/grep this session; each names the file. Distrust and re-verify before executing.

This file is a competitor draft. It is not the shared MASTER and holds no authority over it. Its bet: the best plan is not the one with the most residuals — it is the smallest **constitution** that makes the right things impossible to get wrong, over an owner map that reuses what Atlas already has.

---

## PART A — CONSTITUTION (the mother block is LAW)

### A0. What AAEOS is

AAEOS is the **mother block of all Atlas agentic software engineering**: the constitution and governance authority that every engineering mode obeys — the product law (A1), the effect-authority protocol (B2), the proof taxonomy (B3) and the residual government (D). It is **thin in muscle** (it re-implements no executor, court, governor, ledger or actuator) and **central, non-bypassable in law**. "Optional" describes only the daily *router* command `atlas:aaeos:run`; it never describes the law. Direct `Dev`, `Forge` and `Autônomos` remain primary entries, and their engineering still runs under this constitution.

**Where the law actually binds a direct-entry mode** (so "non-bypassable" is honest, not a slogan): not by any call to `atlas:aaeos:run`, but at the shared **effect boundaries every mode must cross** — the provider-spawn seam (`ProviderGovernanceConsult`, coverage recorded by `ProviderGovernanceCoverageLedger`), the land/settlement seam (`AtlasTaskMergeActuator`/`CanarySettlementRequest`), the court/governor seam (`EngineeringQualityCourt`/`MergeGovernor`) and the ledger (`AtlasEvidenceLedger`). A mode that skips the router still cannot skip these owners; the AAEOS residual government just names their invariants. Falsifiable: with `atlas:aaeos:run` never invoked, a direct Dev/Forge/Autônomos journey still records governed provider coverage, an observer-minted effect and a court verdict — or it produced no effect.

Conflict order (highest wins): (1) live code and durable evidence; (2) the phase contracts (E); (3) the completion predicate (E); (4) the owner map (B1) forbids any parallel owner; (5) uncertainty is `NOT_PROVEN`, never `PASS`.

### A1. Product law

**Three elite executors, one quality floor.** `Dev`, `Forge`, `Autônomos` share the same L0–L5 technical bar. They differ only by horizon, work origin and sovereignty arrangement — never by quality.

| Executor | Horizon | Work origin | Sovereignty | Human in eng loop |
|---|---|---|---|---|
| Dev | session | live intent | current intent/authority receipt | outside the technical loop |
| Forge | durable Obra | commissioned plan + packets | sealed commissioning | absent after planning, except a true reserve |
| Autônomos | continuous queue | Brain → Seed → Task | standing mandate | absent |

The legacy field `human_in_engineering_loop` is removed as an authoritative field and is **not** replaced by hardcoding `false`. Identity is carried by the sovereignty arrangement, not by a boolean. (Disk: `AtlasAaeosCertifyCommand.php:34` gates on it, `AaeosCycleRuntime.php:107` sets it `= (mode==DEV)`, `DevModeAdapter.php:35` hardcodes `true` — all must go.)

**Three loops, never fused.** ENGINEERING is agentic in all three modes (author ≠ judge ≠ governor, independently attributable). SOVEREIGNTY is pre-issued and asynchronous, never a synchronous runtime loop. AUDIT is optional human observation/pause/revocation and never becomes the default engineering gate.

**Non-negotiable engineering laws.**
1. Provider output is an **untrusted proposal**: it never verifies, authorizes, lands, promotes learning or issues a comparative claim.
2. Author ≠ judge ≠ governor. Different labels or contexts are not proof of independent principals; independence needs principal/capability/mechanical evidence, and same-model-family author+judge degrades to a mechanical court.
3. `n=1` is the default. Fan-out needs real width and measured lift; `agent_count` is never a KPI.
4. Less human presence adds **assurance for the new failure surfaces**, never a higher shared quality threshold.
5. Clear intent may remove ceremony — never evidence, mutation safety, courts, failure reasons or rollback.
6. No failure is silent: every adverse terminal state has a precise `failure_reason` owned by its native outcome.
7. Quarantine is absent on disk (verified: `app/Services/Ai/Aaeos/Quarantine` does not exist) and stays absent; ACDE stays dead.
8. A score is diagnostic. Completion is a conjunction of hard evidence, never `composite ≥ x`.

### A2. OneShot Experience Law (operator perception, never the algorithm)

OneShot is a property of the **operator experience**, never of the internal engineering algorithm.

- The operator commissions intent **once** and never becomes a workflow worker. A few genuine intent clarifications may occur; technical approvals and routine status questions may not.
- Internally, Atlas may plan, decompose, implement, review, **reject, repair, retest, re-plan, canary, roll back and repeat** as many times as quality and safety require. First-pass acceptance, minimum time and minimum turns are **not** objectives.
- A failed court/gate returns work to the appropriate agentic stage — it never returns routine technical labor to the operator.
- OneShot proof is **server-derived** from canonical operator-request/action events; a dispatcher or receipt caller cannot declare it. A genuine intent *clarification* is mechanically discriminated from a technical *approval*: only a resolved `ProductIntentClarificationContract` (schema `*.clarification.v1`, status `required`→answered) counts as an allowed clarification; any operator plan/diff/test/release approval is a routine technical action and disqualifies the label. The label requires `capture_coverage == 1.0` over the versioned census of native operator ingress/egress events; missing capture is `unknown`, never zero. For Autônomos (zero operator ingress by design) the positive proof is the *absence* of any captured operator request/action across a complete-coverage journey, not a caller-written zero.

Therefore no class, flag, queue, retry policy, test or P4 criterion may read OneShot as one attempt, one dispatch, one pass, immediate delivery or a shortcut around review. Building OneShot *into the code* is forbidden — it produces exactly the bug-cascade the operator named.

### A3. The M thesis (the reason the mother block exists), made falsifiable

Useful output ≈ **N × M**. `N` = raw provider power. `M` = what Atlas multiplies on top: governance, refusal of bad output, proof, memory, recovery, mandate — applied at real effect boundaries. AAEOS exists to maximize `M` for the engineering domain without re-implementing muscle. Of these, only **governance/refusal/proof** are falsifiably measured now (the A3 instruments below); **memory, recovery and mandate** are M levers whose measurement is a named horizon, not claimed as falsifiable-M today.

`M` is real only if measured, and it is measured from **existing owners**, never a vanity score:
- `enforced_governed_coverage = COVERED / total_provider_spawns` — the M claim cites this **manager-resolved** rate only. The ledger's `governed` field folds advisory `consulted` into governed (`ProviderGovernanceCoverageLedger:145`), so `consulted_governed_reach = (COVERED+CONSULTED)/total` is reported separately and **never** cited as M. `total_provider_spawns` is `unknown` (never a floor) until a spawn-site census is proven complete. Disk: `ProviderGovernanceCoverageLedger` (`PATH_COVERED`/`PATH_CONSULTED`/`PATH_BYPASS`, schema `atlas.ai.governance.provider_coverage.v1`; empty ledger reports `0.0`, never fabricated).
- `provider_proposal_refuse_repair_rate = journeys_with_a_court/gate/repair_event_before_land / all_commissioned_journeys` — how often untrusted output is caught before land, with a real ledger denominator (disk: `AtlasEvidenceLedger` court/repair events + `EngineeringOutcome`), not a bare rate name.
- Both carry window + denominator + provenance; below min sample they are `unknown`, never a floor number.

`M` claims of "SOTA / 50× / #1" require `capability_proof=comparative` from `RivalsClaimAuthority` under budget symmetry — never a caller-set field, never asserted from an internal composite.

---

## PART B — ARCHITECTURE (reuse-only; verified owners)

### B1. Owner map — every concern reuses an existing owner

Verified present on disk this session unless marked. Creating a parallel owner is a hard veto.

| Concern | Existing owner (reuse) | Forbidden duplication |
|---|---|---|
| Dev plan/execute | `DevPlanRunFacade`; `AtlasDevExecutionService`; `DevIntent`; `ConfirmedDevRun` | a Dev executor inside AAEOS |
| Forge continuity | `ForgeObraRuntime`; `ForgeWorkPacketExecutionCycleService`; `ForgeCommissioning` | flattening an Obra into one dispatch |
| Autônomos origination/seed | `AtlasBrainNextCommand` + in-process `AtlasSelfConstructionNativeReplenisherEnqueueRunner` (packet drafts from `AtlasSelfConstructionFrontierToPacketDrafter`) for seed enqueue | CLI→CLI forever; invented `--max`/`--specs` file hand-off |
| task claim/worker | `AtlasTaskServingService` + native worker | calling `task next` a worker cycle |
| execution contract | `ExecutionOrder` v2 (28-field canonical payload, `duration_regime`+`work_topology` enums — verified) + `EngineeringOutcome` | an `EngineeringMission*` envelope/receipt; conflicting axes |
| adjudication | `EngineeringQualityCourt`; `AtlasVerificationCourtFalseGreenDetector` | an AAEOS court |
| release authority | `KernelEvidenceAuthority`; `MergeGovernor` family (`AtlasMergeGovernor{AdmissionPolicy,RiskClassifier,RollbackPlanGate,ReleaseDecisionLedger}`) | a `SovereigntyPort` package |
| code effect | `AtlasTaskScopedCommitter`; `AtlasTaskMergeActuator` (`::changedFiles` = diff-tree write-set at landed SHA) | a generic AAEOS actuator |
| canary/settlement | `CanarySettlementRequest` (`landedSha` + `observerIdentity`) | a second settlement state machine |
| effect nonce / single-use | `CanarySettlementRequest::idempotencyHash` + canary terminal-id (`KernelEvidenceAuthority`) | replaying one authorization across work/mode/action/owner |
| Autônomos productive cycle (P4) | `AtlasSelfConstructionRuntimeDaemonCommand` cycle + `AutonomosPreflightService` (read-only preflight) | a new daemon/scheduler |
| compensation/rollback | `AtlasTaskMergeActuator::prepareRevert(CanarySettlementRequest)→AuthorizedRevertAction` (+ `revert()`) | AAEOS actuating a revert itself |
| evidence | `AtlasEvidenceLedger`; evidence readback = `EvidenceLedgerHashChainIntegrityVerifier::verify` + `AtlasLedgerReplayService::eventsForEnvelope` (NOT the readiness-chain replayer, which never reads the ledger) | a second ledger, counter JSON or table |
| sovereignty receipt / signing | `DecisionReceipt` + promoted `HumanDecisionReceiptSigner` (Ed25519) | a string label as authority; a second signer |
| reversibility | `ReceiptReversibilityConsentGate` (orphan today — wire only for reserved effects) | a regex-based consent gate |
| admission | `AtlasMergeGovernorAdmissionPolicy` verdicts `{admitted, rejected, repair_required, blocked}` (`DECISION_REPAIR`) | a new 6-state AAEOS admission enum |
| independence | `SpecSourceIndependence`; `SovereignSpecFloor`; provider-lock `modelFamily`/adapter `MODEL_FAMILY` | a self-declared independence flag |
| metrics / economy | `AtlasMaestroCostAggregator`; `EngineeringOutcome`(operatorEffort/tokens) | an AAEOS cost/token truth |
| **provider governance (M-lever)** | `ProviderGovernanceCoverageLedger`; `ProviderGovernanceConsult`; `GovernanceConsultSkipCounter` | a second bypass meter; ungoverned muscle spawn |
| delegated learning / memory-M | `AtlasMemoryLearningPromotionService`; `AtlasHeldEvidenceMinerService`; `AtlasOpenBrainContextPackService` | a second memory promoter / evidence→memory bridge under AAEOS |
| comparative claims | `RivalsClaimAuthority` | `capability_proof` accepted from a caller |
| mode routing | `AaeosModeToDualCoreRoute` (single owner; must fail-closed on invalid mode, not default to Dev) | mode-derivation duplicated in read models |
| terminal audit/review | `atlas:cli:cockpit` + `AtlasReviewDeepCommand` (`atlas.review.deep_packet.v1`) | a new cockpit/shell/editor |

Minimal flow: `atlas:aaeos:run|cycle` → `AaeosRunApplication` (the **only** new production class this plan permits — a run/cycle dedup use-case, not a new owner) → `AaeosCycleRuntime` → `AaeosLiveDispatchGateway` → existing per-mode dispatcher → **native owner performs pre-effect authority replay** → native actuator acts → native actuator/settler records the **observed** effect → `AtlasEvidenceLedger` → AAEOS projects read-models (scorecard/certify/cockpit/review). AAEOS never calls `EliteExecutorKernel::execute(ExecutionOrder)` (verified at `EliteExecutorKernel.php:838`) directly; native owners create the order at their boundary.

### B2. Effect-authority protocol (authority at the effect boundary, not in a facade)

Land-only observation is **insufficient** for an external irreversible effect — it cannot be blocked retroactively. Five steps, across existing owners:

1. **SUSPECT** — text, hints, regex and caller declarations may only *raise* suspicion. (Disk: `AaeosIntentCompiler.php:47-49` derives `irreversible` by regex today; that is suspicion, never authority.)
2. **PRE-AUTHORIZE** — the native tool/actuator gateway resolves an `authorized_effect_ceiling` from the owned effect-class ceilings AAEOS actually produces (`AtlasMergeGovernorAdmissionPolicy`/`AtlasMergeGovernorRiskClassifier` for the release class; `ProviderGovernanceConsult` for the provider-spawn class), plus arguments, target, active authority receipt and world state, **before** the effect. There is no generic unowned action-registry.
3. **ACT** — the actuator performs at most the authorized effect.
4. **POST-ATTEST** — the actuator/settler derives `observed_effect_class` from the actual tool invocation, write-set, landed SHA or external receipt (reuse `AtlasTaskMergeActuator::changedFiles`).
5. **SETTLE** — evidence binds authorization, observed effect and outcome; a mismatch gives zero capability credit and triggers refuse-next/revoke/rollback/**compensation** (owned by `AtlasTaskMergeActuator::prepareRevert`).

Trust rules: `ExecutionOrder` is an untrusted proposal (it is `ExecutionOrder::fromArray(array)` — caller-authored, verified) until its decision/authority event is reloaded by the owner under the same lock/lease; a declaration may raise a ceiling, never lower a server-resolved class; trust derives from the emitting owner + canonical event + causation + hashes + replay, never from a `minter='observer'` string. Each authorization binds a single-use nonce **at its own boundary** (the `idempotencyHash` only exists post-land, so it cannot gate the merge): the **LAND nonce** = `AuthorizedMergeAction::nonce` gates the merge at `AtlasTaskMergeActuator`; the **SETTLE nonce** = `CanarySettlementRequest::idempotencyHash` gates settlement. A nonce may not be replayed across work, mode, action or owner; the actuator acts **at most once**; the exactly-one-winner of a consume-vs-revoke race is enforced by the `atlas_ledger_events.event_id` PRIMARY KEY via the deterministic event-id idiom (a hard `INSERT`, never upsert/insertOrIgnore), the loser to `release_uncertain`. **Law 2 (author≠judge≠governor) is enforced at the mint, not asserted:** minting `release.authorized` requires an independence attestation (`SpecSourceIndependence`/`SovereignSpecFloor`); when author and judge resolve to the same model family (the verboo-only default), the release cannot be minted without a mechanical `EngineeringQualityCourt`/`AtlasVerificationCourtFalseGreenDetector` verdict.

Sovereignty reserve is narrow and asynchronous (H1–H7): constitution/root-policy, material value conflict, truly-irreversible external effect outside delegated authority, canonical-memory promotion beyond delegated policy, autonomy/ceiling expansion, root-credential expansion, sensitive-domain entry. Everything reversible under courts/canary/rollback stays agentic. A reserved effect *blocks that effect only*, persists a sovereign work item as a ledger escalation event (`DecisionDrafted`/`EscalationRequested`/`DecisionIssued`), and other admissible work continues — Autônomos never waits synchronously. This is **falsifiable, not asserted** (import of the Codex two-work-item witness): while a reservation is open, an A/B proof shows a *different* task-serving work item claims→executes→settles (`AtlasTaskServingService` + ledger continuation), and a task-causal `DecisionIssued` that resolves a *mid-journey* H1–H7 reservation is itself a sovereignty **action** that disqualifies that Autônomos journey from the zero-touch P4 label (a pre-issued standing decision does not). A **technical** invalidity (e.g. invalid mode) is `repair_required`, never `halt_sovereign` (disk: `AaeosAdmissionPolicy.php:36` wrongly maps `invalid_mode→HALT_SOVEREIGN` today).

### B3. Proof taxonomy and scorecard

Proof levels never imply the next: `PLANNED < SOURCE_WIRED < AUTOMATED_CHARACTERIZED < DRY_RECEIPT < LIVE_EFFECT < REAL_OPERATION < SUSTAINED`. `certify ok` today = `AUTOMATED_CHARACTERIZED` of structural invariants, not `REAL_OPERATION`.

Every scorecard dimension carries `status ∈ {measured, unknown, not_applicable, assessment_only, failed}`, `value`, `source`, `window`, `numerator`, `denominator`, `sample_size`, `failure_reason`. `unknown` always has `value:null` and never a floor number. `effect_level ∈ {none, prepared, mutated, provider_executed, blocked}` and `proof_level` are **derived** from observed evidence, not settable fields — illegal pairs (e.g. `blocked`+`LIVE_EFFECT`) are unrepresentable, not merely veto-listed. `capability_proof ∈ {none, internal_only, comparative}` is derived from hard gates/Rivals and never persisted from a caller; any UI/text claiming "god/SOTA/Elite-world" while `capability_proof ≠ comparative` fails a hard gate.

`REAL_OPERATION` proof must be produced **outside PHPUnit** on a real workspace and durable DB with real provider/tool/effect where required; a fresh process recomputes all truth; simulator/exit-0/test-JSON never qualifies. Every evidence leg must be **load-bearing AND authentic**: (i) the P4 readback **re-derives** each effect leg from world state — re-runs `AtlasTaskMergeActuator::changedFiles(repo, landedSha)` and re-reads the external receipt, never re-reads the ledger's own copy; (ii) a durable-environment attestation stamps the DB identity + workspace git HEAD + a no-PHPUnit assertion into the manifest; (iii) a **hand-built subtract-one invalidator matrix over the world-state legs** (effect receipt, landed SHA, observed write-set hash, canonical event) is the sole P4 readback veto — removing any single hard input must flip the verdict, else the readback is decorative/fabricated. Suite-adequacy mutation testing (`QualityFoundryMutationCoverageRunner`/`MutationTestingAdapter`) is a *separate* PHPUnit-bound gate, never part of the outside-PHPUnit REAL_OPERATION readback (category mismatch — the Infection runner cannot mutate world-state legs).

---

## PART C — DISK TRUTH (verified; revalidate before every slice)

| Fact | State | Consequence |
|---|---|---|
| Aaeos live tree | 27 PHP under `Control/`+`Spine/` | no foreign production package |
| Quarantine | directory **absent** (verified) | never recreate/import |
| `runtime_write_performed` | hardcoded `true` at `AaeosCycleRuntime.php:93` | P0 must derive from actual writes |
| Spine self-green | `AaeosEngineeringSpine::assertShared(mode, [])` defaults evidence/delivery to shared constants → `ok:true` with zero refs; called with `[]` at `AaeosCycleRuntime.php:75` | fail closed on applicable missing refs |
| Autônomos dispatch | `brainNextArgs` passes `--scope`; `AtlasBrainNextCommand` signature is positional `{scope}`, no `--scope` option → **always fails live** | P1a hard gate |
| Brain success | exit 0 can mean `disabled`/`dry` | classify by payload status |
| Seed `--max` | command has no `--max`; dispatcher invents it | P1a remove |
| admission | `invalid_mode → HALT_SOVEREIGN` at `AaeosAdmissionPolicy.php:36` | taxonomy fix (technical ≠ sovereign) |
| irreversibility | `AaeosIntentCompiler.php:47-49` regex feeds admission | suspicion, not authority |
| ExecutionOrder | v2, 28-field canonical payload; `work_topology` already exists | do not add conflicting axes |
| effect observer | `AtlasTaskMergeActuator::changedFiles(repo,sha)` (diff-tree at landed SHA); `CanarySettlementRequest.observerIdentity` | reuse as the sole POST-ATTEST source |
| provider bypass meter | `ProviderGovernanceCoverageLedger` exists | reuse for M; do not build a second |
| dead self-graders | `AaeosOperateScorecardProjector` (zero consumers, verified) and `AaeosTriHygieneScorecardProjector`/`AtlasTriHygieneScorecardCommand` (LOC/`is_file` proxy) | delete |

Revalidation: `git branch --show-current`; `rg --files app/Services/Ai/Aaeos -g '*.php'`; `rg -n 'human_in_engineering_loop|runtime_write_performed|assertShared' app/Services/Ai/Aaeos`; `rg -n 'brainNextArgs|--scope|--max' app/Services/Ai/Aaeos app/Console/Commands/AtlasBrainNextCommand.php`.

---

## PART D — RESIDUAL GOVERNMENT (theme-grouped, not a flat sprawl)

The anti-accretion principle: residuals are grouped into **themes**; each theme names its owner(s) and its concrete blockers with hard done-conditions. Rows that share a theme but have distinct owner+phase+done-condition stay **independently closable** (no laundering-by-fusion); genuinely-redundant rows are folded (no duplicate-accounting). None may be waived into DONE.

**T1 — Live transport contract (P1a).** `brain:next` positional scope + default; payload-status success (not exit-only); the seed leg enqueues **in-process** via `AtlasSelfConstructionNativeReplenisherEnqueueRunner` (packet drafts from `AtlasSelfConstructionFrontierToPacketDrafter`), dropping the `--max`/`--specs` CLI hand-off entirely (never emits `no_packets`). Owners: `AtlasBrainNextCommand`, `AtlasSelfConstructionNativeReplenisherEnqueueRunner`, `AtlasTaskServingService`.

**T2 — Truth-before-capability (P0).** derive `runtime_write_performed` (3 hardcode sites to fix: `AaeosCycleRuntime:93`, `AaeosOrgStateProjector:46`, `AaeosScorecardProjector`) from dispatch effect kinds + `AtlasTaskMergeActuator::changedFiles` (NOT DualCore bookkeeping, which writes even for `plan_only` at `AaeosLiveDispatchGateway`; `runtime_write_performed = effect_level ∈ {mutated, provider_executed}`, at most `prepared` in P0 before P1b supplies authoritative observed classes); unify `run`/`cycle` on `AaeosRunApplication`; typed `effect_level`; **gut** the hardcoded composite/`god_sota>=9.0` self-grade in `AaeosScorecardProjector:34-57` to a measured-only projector (no hints/constants); dry-run zero writes; remove `human_in_engineering_loop` reads/writes (call-sites `certify:34`, `CycleRuntime:107`, `DevModeAdapter`), never hardcode false; `invalid_mode → repair_required` by adding a new `AaeosAdmissionVerdict::REPAIR_REQUIRED` (`allowsExecution=false`) — do NOT reuse the disjoint MergeGovernor `DECISION_REPAIR`) — not sovereign, and propagate the new verdict to its two const-consumers `AaeosCycleOutcomeRecorder:33-34` + `AtlasAaeosCertifyCommand:43`, routing both through `allowsExecution()` not `=== HALT_SOVEREIGN`.

**T3 — Effect authority (P1b).** PRE-AUTHORIZE→ACT→POST-ATTEST→SETTLE bound to native owners; `ExecutionOrder` decision-event reloaded before provider/tool; server-resolved effect class, declared fields advisory-monotone; present-but-false and no-keyword goldens block by observed effect; `AtlasDevPlanApprovalGate`/`AtlasDevProviderExecutionBlock` named read-only so the AAEOS Dev path stays gate-free (OneShot). Compensation for a landed-then-bad release via `prepareRevert`.

**T4 — Canonical evidence integrity (P2, one readback).** These are one property at four altitudes, verified by a **single fresh-process readback** (`EvidenceLedgerHashChainIntegrityVerifier::verify` over ledger events + `AtlasLedgerReplayService::eventsForEnvelope` — never the readiness-chain replayer), but kept as distinct owner+phase rows (no-fuse guard): (a) ledger chain/envelope recompute + bounded query + divergent-duplicate veto [`AtlasEvidenceLedger`]; (b) N11 spine ref resolves to a settlement-emitted effect receipt, empty/declared refs fail closed, and a site's **applicability is server-derived** from mode + resolved effect-class using the existing per-mode required-slot census (`AaeosEngineeringSpine::evidenceSlots`/`deliveryArtifacts` — `verification_receipt`, `evidence_refs`, `seed_gate_receipt`, `task_lease_binding`), which `assertShared` must actually enforce instead of defaulting two class-name strings on `[]` [`AaeosEngineeringSpine` — folds the old standalone R44]; (c) non-circular `receipt_core_hash` (excludes event id/hash) [`AaeosCycleRuntime`+ledger]; (d) journey one-root ordered manifest of event/native/artifact hashes + terminal-status enum, splice/omit/reorder fail. Crash/exactly-once: deterministic fault injection at every cutpoint; restart converges to one effect + one absorbing terminal or precise `release_uncertain`; Postgres unique constraints/locks prove real races; no second outbox.

**T5 — Identity & mandate (P1b/P2).** typed native ingress + authority lineage (no remint/fallback) [`DevIntent`/`ConfirmedDevRun`/`ForgeCommissioning`]; standing mandate Ed25519-signed, active and non-revoked at origination, claim/renew, **after any long provider call**, and immediately before sandbox/effect under the effect lock (absence/stale/mismatch = zero mutative/effect call — a check taken only pre-provider does not satisfy the gate) [`HumanDecisionReceiptSigner`]; schema migration expand→dual-read→canary→cutover with legacy read-only [`DecisionReceipt` v3].

**T6 — Journey & repair (P2/P4).** distinct `served|claimed|executed|landed|resolved` refs; `already_done` gets no new-work credit; every repair/replan/re-review is an **intra-journey** continuation appended to the single-root manifest, never a new journey [`AtlasRepairOrchestrator`, Forge cycle, `AtlasTaskServingService`]; reserved-decision captures derive from ledger escalation events, not operator per-task actions (keeps zero-touch Autônomos consistent).

**T7 — Independence (P2/P3).** author ≠ judge ≠ governor by principal/capability/mechanical evidence; `SelfComposedUnwitnessed` fails in all modes merely because an operator exists; same model-family author+judge degrades to a mechanical `VerificationCourt` verdict [`SpecSourceIndependence`, `SovereignSpecFloor`, provider-lock `modelFamily`].

**T8 — Economy & M (P1/P3, read-only).** `scorecard --view=economy` over **every commissioned journey** (ITT cohort; includes blocked/timeout/cancel/failure/retry/rollback burn), accepted-landing efficiency secondary and observer-minted only [`AtlasMaestroCostAggregator`+`EngineeringOutcome`]; `enforced_governed_coverage = COVERED/total` (manager-resolved; `consulted` folded into ledger `governed` at `:145` is reported separately, never cited as M) + refuse/repair rate over the same ITT cohort as the falsifiable **M** instrument [`ProviderGovernanceCoverageLedger` + `EngineeringOutcome`]; delegated-learning/memory-M seam named at H4 [`AtlasMemoryLearningPromotionService` et al.]. No new receipt field; instrumentation never raises the OneShot/Autonomy burden. **Amplification neutrality:** AAEOS adds **zero** provider/context/retry/worker/mutation hops versus the paired direct native journey; identical no-delta retries are held before another provider call; fan-out dedupes identical candidate hashes and stays under the signed root budget, which survives internal id changes and handoffs. A paired-run golden (AAEOS-routed vs direct, same intent) fails if AAEOS's hop count exceeds the direct path.

**T9 — Deletion & hygiene (P0/P3).** delete `AaeosOperateScorecardProjector` (zero consumers) and `AaeosTriHygieneScorecardProjector`+command (LOC/`is_file` proxy); delete the four `Control/Adapters/*` after parity characterization; make `AaeosHygieneLegacyAliases::register()` lazy (reuse `RootSinglesLegacyAliases` pattern); AEOS 43k-LOC lattice partitioned from the live Kernel path with a guard test (keep Scoring cores; demote 17-phase runbook + department authority); alias burn evidence-led (classmap/runtime proof, not `rg=0`).

**T10 — Product surface (P1/P3).** the 2026 verification moat is first-class as a `scorecard --view=verification` projected — after T2/T9 gut its `god_sota>=9.0` self-grade (`AaeosScorecardProjector:34-57`) leaving a measured-only projector — by that same `AaeosScorecardProjector` from the T4(d) single-root journey manifest — operator artifact = observer-minted effect + proof + admission + journey manifest — consumed by both `atlas:cli:cockpit` and `atlas:review:deep` [`AtlasReviewDeepCommand`]. No new read model (it is a projector view, exactly like T8), no new shell.

**Horizon (not P4 gates):** durable sustained government at scale; topology ablation (sister Rivals/Foundry); external comparative superiority (`capability_proof=comparative`); multi-domain M (trading/marketing/…) composing the same governance without restart.

---

## PART E — EXECUTION

### E1. Phases (bounded, ordered, path-manifested)

**Scope fence (per phase):** each phase begins with branch/status, current hash, dirty-ownership attribution and RED-first characterization. Its touched production/test paths — the concrete files named in that phase's themes (Part D) and the owner map (B1) — are a **closed authorization manifest** enumerated in the phase receipt at EXECUTE time. If a RED proves an unlisted path must change, execution **stops** and this plan is amended before that path is edited. `*`, "if needed" and unnamed native owners are forbidden. No phase absorbs the next.

**Named RED per gate:** every theme (T1–T10) and every B2/B3/E2 hard done-condition names one existing-or-new test that is **red on today's code** and green only when the slice lands — reuse `AaeosControlPlaneTest`, `AaeosOperateDispatchTest` (both exist under `tests/Unit/Ai/Aaeos/Control`), and invert `AaeosGodSotaCertificationTest` (its `composite>=9.0` and `human_in_engineering_loop` assertions must flip). A slice without a red-first test does not start. New tests are the only permitted new artifacts (they are tests, not production organs). The reuse-only / no-new-owner law is itself **mechanized** by one owner-census architecture test (`glob` over `app/Services/Ai/Aaeos` asserting every production symbol the plan names resolves to an existing class, and that no new production class beyond `AaeosRunApplication` is introduced) — turning C3's prose #1-bet into red/green.

- **P0 — truth/port:** T2 + T9-deletions. Remove false success, unify the port, honest read-only projection; no Brain/effect fix. RED-first on fake constants, dry writes, invalid caps, alias parity.
- **P1a — native dispatch:** T1 (fuse the 8 adapters/dispatchers into the existing gateway path; R33/R34/R35). Behavior-preserving fuse + contract fix only. Commit separate from P1b.
- **P1b — effect authority:** T3 + T5-signing. Server-resolved effect class; admission taxonomy; compensation owner; land-observer both chokepoints. Security slice, isolated commit.
- **P2 — authority/evidence/durability:** split **P2-MVP** (required for DONE: T4 single fresh-process readback, T5 signed-mandate liveness, T6 journey, T7 independence-mint, plus the deterministic-event-id hard-`INSERT` exactly-one-winner as its *own* named slice — genuinely new concurrency work, honestly *not* pure reuse) from **P2-HORIZON** (crash/exactly-once recovery *at scale*, deferred, not a DONE gate). Ordered DAG **P2a first** (T4 ledger/evidence foundation) → T5-ingress → T6 → T7. Three slices edit `AtlasEvidenceLedger.php` under the same-file/same-owner commit rule.
- **P3 — deletion/alignment:** T9-remainder + T10 + docs canonical alignment; observe compaction only on measured evidence.
- **P4 — real journey (out-of-PHPUnit):** one completed `REAL_OPERATION` journey per mode through its native owner chain, retries/reviews/repairs preserved; server-derived OneShot label. The single point of DONE-failure is P4-Autônomos (master switch OFF + stale heartbeat): it runs an operator-authorized read-only preflight (`AutonomosPreflightService`/`AtlasAutonomosPreflightCommand`) and initiates/observes ONE existing native producer cycle (`AtlasSelfConstructionRuntimeDaemonCommand`), never a new daemon. If preflight fails, P4 records `blocked_ops` with a precise `failure_reason` and the program stays PARTIAL — an honest partial, never a waiver into DONE. Per-mode `REAL_OPERATION` producers reuse existing commands: Dev `AtlasDevSeniorLoopRunCommand`, Forge `AtlasForgeLiveExecuteCommand`, Autônomos `AtlasSelfConstructionRuntimeDaemonCommand` (extract its private `productiveCycle` into a callable seam so P4 wires it, not a shell-out).

### E2. Completion predicate

DONE iff: P0–P4 receipts green; every theme T1–T10 blocker closed (or explicitly `not_applicable`, or a named horizon this file already marks); Dev/Forge/Autônomos each have a completed `REAL_OPERATION` journey with internal retries/reviews preserved; effect/proof/`runtime_write_performed` match canonical events and fresh readback; every adverse state has a precise `failure_reason`; one canonical `AtlasEvidenceLedger` passes integrity/replay; the away-by-design sole operator has failures SURFACED via a `scorecard --view=liveness` projection over existing ledger adverse-terminal events + `AtlasSelfConstructionRuntimeDaemonState` heartbeat (no daemon, no alerting service); direct modes remain usable without AAEOS **and still governed** — a P4 acceptance leg runs one direct-entry journey per mode with `atlas:aaeos:run` never invoked and proves it still recorded `enforced_governed_coverage`, an observer-minted effect and a court verdict (or produced no effect), making A0's non-bypassability falsifiable; Quarantine/ACDE absent; no forbidden duplicate owner created; commits scoped on local `main`. `capability_proof ≥ internal_only`. DONE means `aaeos_mt_real_journey_verified` + optionally `operator_experience=oneshot` (operator-effort only) — it does **not** mean one-pass code, sustained 24/7, comparative superiority, or "complete autonomous software company"; those need their own current evidence and claim authority.

### E3. Non-goals (hard vetoes)

Mission Runtime/Envelope/Receipt; WorkGraph-as-OS; SovereigntyPort package; `AaeosModeExecutor`; `AaeosActionEffectClassifier` as authority; generic external-effect gateway under AAEOS; second Ledger/counter/table; Evaluation Foundry inside P4; new cockpit/shell/editor; Quarantine/ACDE resurrection; `mandate_epoch` as a free number; `minter='observer'` as proof; `capability_proof` from a caller; hardcoding the human field `false`; flattening Forge into a dispatch; claiming Autônomos worker execution from `task next`.

### E4. Handoff

PLAN_ONLY; no production code authorized. Next action is another absolute audit round or `EXECUTE P0` (which authorizes only E1-P0). This file competes with the Codex `…-MASTER.md`; the operator picks or fuses.

---

## Why this structure beats a flat 90-residual ledger

1. **Constitution-first:** the mother-block law (A) is stated once, up front, as the thing every mode obeys — not diluted as "an optional facade."
2. **Theme-grouped residuals (D):** T1–T10 give the same coverage as ~90 flat rows but are executable and non-accreting; the no-fuse guard keeps distinct owner+phase rows honest without duplicate-accounting.
3. **M is falsifiable and central (A3, T8):** the reason to exist is measured from `ProviderGovernanceCoverageLedger`, not asserted.
4. **Everything reuses a verified owner (B1):** no invented owner survived; each was read on disk this session.
5. **OneShot is law-of-perception, not code (A2):** the operator's exact correction, protected against a future implementer.
