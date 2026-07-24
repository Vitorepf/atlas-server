# AAEOS — The Mother Block of Atlas Agentic Software Engineering · FINAL synthesis

> **Status:** FINAL synthesis · PLAN_ONLY · P0–P4 NOT_STARTED · code requires literal `EXECUTE P0`.
> **What this is:** the merge of the two-track competition — the Claude *constitution + critical path* (clarity, executability, self-audit) fused with the Codex `…-MASTER.md` v15 *completeness* (residual/journey/crash inventory, no-fuse discipline) — audited by an external judge (Grok) and honesty-corrected. It supersedes the C6 competitor draft. On operator acceptance it becomes the single canonical plan; LEDGER/SCOREBOARD repoint here and the parallel drafts archive.
> **Branch:** local `main` only · scoped commits · never `git add -A`.
> **Discipline:** every owner named here was read on disk this session; every done-condition is falsifiable; no new production organ is authorized; a line/score/checkbox never promotes a proof level by implication.

Conflict order (highest wins): (1) live code + durable evidence; (2) phase contracts (E1); (3) completion predicate (E2); (4) the owner map (B1) forbids any parallel owner; (5) uncertainty is `NOT_PROVEN`, never `PASS`.

**Trust boundary (explicit).** god-SOTA proof strength here is defined *relative to* three trusted substrates — the local Postgres ledger, local git history, and an **off-host operator signing key**. The anti-fabrication matrix (B3) can only *anchor authenticity* once the signing key is custodied off the journey process; against a compromised same-host adversary everything else is tamper-evidence, not authenticity. This is the correct boundary for a personal, local-first, single-operator Atlas — not a claim of proof against a compromised host.

---

## PART A — CONSTITUTION (the mother block is LAW)

### A0. What AAEOS is

AAEOS is the **mother block of all Atlas agentic software engineering**: the constitution and governance authority every engineering mode obeys — the product law (A1), the effect-authority protocol (B2), the proof taxonomy (B3) and the residual government (D). It is **thin in muscle** (re-implements no executor, court, governor, ledger or actuator) and **central, non-bypassable in law**.

**Where the law binds a direct-entry mode** (so "non-bypassable" is honest): not by any call to `atlas:aaeos:run`, but at the shared **effect boundaries every mode must cross** — the provider-spawn seam (`ProviderGovernanceConsult`, coverage in `ProviderGovernanceCoverageLedger`), the mutative-surface gate (`AtlasWorkspaceIntelligenceExecutionGateService`), the land/settlement seam (`AtlasTaskMergeActuator`/`CanarySettlementRequest`), the court/governor seam (`EngineeringQualityCourt`/`MergeGovernor`) and the ledger (`AtlasEvidenceLedger`). Falsifiable: with `atlas:aaeos:run` never invoked, a direct Dev/Forge/Autônomos journey still records governed provider coverage, an observer-minted effect and a court verdict — or produced no effect (the AAEOS-ablation test).

The router `atlas:aaeos:run` carries **no productive muscle flags** — `--live`/`--execute-provider`/`--max-seeds`/`--run-worker-once`/`--scope` belong to the native executor commands, not the router; it routes/observes/projects only. This operationalizes both the thin-muscle position and OneShot.

### A1. Product law

**Three elite executors, one floor.** `Dev`, `Forge`, `Autônomos` share the same L0–L5 technical bar; they differ only by horizon, work origin and sovereignty arrangement — never quality.

| Executor | Horizon | Work origin | Sovereignty | Human in eng loop |
|---|---|---|---|---|
| Dev | session | live intent | current intent/authority receipt | outside the technical loop |
| Forge | durable Obra | commissioned plan + packets | sealed commissioning | absent after planning, except a true reserve |
| Autônomos | continuous queue | Brain → Seed → Task | standing mandate | absent |

The legacy field `human_in_engineering_loop` is removed as authoritative (call-sites `AtlasAaeosCertifyCommand:34`, `AaeosCycleRuntime:107`, `DevModeAdapter`) and is **not** replaced by hardcoding `false`; identity is carried by the sovereignty arrangement.

**Three loops, never fused.** ENGINEERING is agentic in all three modes (author ≠ judge ≠ governor). SOVEREIGNTY is pre-issued, asynchronous, never a synchronous runtime loop. AUDIT is optional human observe/pause/revoke — never the default engineering gate.

**Non-negotiable laws.**
1. Provider **output** is an untrusted proposal: never verifies, authorizes, lands, promotes learning or issues a comparative claim.
2. Author ≠ judge ≠ governor by principal/capability/mechanical evidence; **same model family (the verboo-only default) degrades to a mechanical court** — labels are not proof.
3. `n=1` default; fan-out needs real width + measured lift; `agent_count` is never a KPI.
4. Less human presence adds **assurance for new failure surfaces**, never a higher shared quality threshold.
5. Clear intent may remove ceremony — never evidence, mutation safety, courts, failure reasons or rollback.
6. No failure is silent: every adverse terminal state has a precise `failure_reason` owned by its native outcome.
7. Quarantine is absent on disk and stays absent; ACDE stays dead.
8. A score is diagnostic; completion is a conjunction of hard evidence, never `composite ≥ x`.
9. Provider **input** is sovereign (dual of Law 1): two pétreo invariants bind every spawn — `sovereignty_local_first` (`AtlasConstitutionalKernelService:95`/:448, sensitive/secret/cyber classes must not appear in outbound data) and `human_approval_for_high_risk` (:101, cross-domain sensitive changes need operator approval). This egress veto is **fail-closed and independent of the economic governance flag** (`ProviderGovernanceConsult::enforce` defaults OFF/observe-only, `:122`) — an unknown or sensitive outbound class blocks the spawn regardless of the economic meter.

### A2. OneShot Experience Law (operator perception, never the algorithm)

OneShot is a property of the **operator experience**, never the internal engineering algorithm.

- The operator commissions intent **once** and never becomes a workflow worker. A genuine intent *clarification* is mechanically discriminated from a technical *approval*: only a resolved `ProductIntentClarificationContract` whose request traces to a native operator-intent event (lineage via `ProductIntentCourt`) counts as an allowed clarification; any plan/diff/test/release approval disqualifies the label.
- Internally Atlas may plan, decompose, implement, review, **reject, repair, retest, re-plan, canary, roll back and repeat** as many times as quality and safety require. First-pass acceptance, minimum time and minimum turns are **not** objectives. A failed court/gate returns work to the appropriate agentic stage, never routine technical labor to the operator.
- OneShot proof is **server-derived**, requires `capture_coverage == 1.0` over the versioned census of native operator ingress/egress events (missing capture is `unknown`, never zero), and for Autônomos the positive proof is the *absence* of any captured operator action across a complete-coverage journey.

No class, flag, queue, retry policy, test or P4 criterion may read OneShot as one attempt, one dispatch, one pass, immediate delivery or a shortcut around review. Building OneShot *into the code* is forbidden.

### A3. The M thesis, made falsifiable

Useful output ≈ **N × M**. `N` = raw provider power. `M` = what Atlas multiplies: governance, refusal, proof, memory, recovery, mandate — at real effect boundaries. Of these, only **governance/refusal/proof** are falsifiably measured now; memory, recovery and mandate are M levers whose measurement is a named horizon.

Measured from existing owners, never a vanity score:
- `enforced_governed_coverage = COVERED / total_provider_spawns` — the M claim cites the **manager-resolved** rate only. The ledger `governed` field folds advisory `consulted` (`ProviderGovernanceCoverageLedger:145`), so `consulted_governed_reach` is reported separately and never cited as M.
- `provider_proposal_refuse_repair_rate = journeys_with_a_court/gate/repair_event_before_land / all_commissioned_journeys` — real ledger denominator (`AtlasEvidenceLedger` + `EngineeringOutcome`).
- **Census owner:** the E1 owner-census architecture test extended to enumerate every provider-spawn call site (a test, not a production organ). **Completeness clause:** no falsifiable claim (M denominator, OneShot `capture_coverage`) is cited as *measured-today* unless its census is green; else `unknown`.

`M` claims of "SOTA / 50×" require `capability_proof=comparative` from `RivalsClaimAuthority` under budget symmetry — never a caller value, never an internal composite.

---

## PART B — ARCHITECTURE (reuse-only; every owner verified on disk)

### B1. Owner map

| Concern | Existing owner (reuse) | Forbidden duplication |
|---|---|---|
| Dev plan/execute | `DevPlanRunFacade`; `AtlasDevExecutionService`; `DevIntent`; `ConfirmedDevRun` | a Dev executor inside AAEOS |
| Forge continuity | `ForgeObraRuntime`; `ForgeWorkPacketExecutionCycleService`; `ForgeCommissioning` | flattening an Obra into one dispatch |
| Autônomos origination/seed | `AtlasBrainNextCommand` + in-process `AtlasSelfConstructionNativeReplenisherEnqueueRunner` (drafts from `AtlasSelfConstructionFrontierToPacketDrafter`; enqueue authority `AgentControlPlaneTaskQueueOrchestrator::prepareAndEnqueue`; top-up `AtlasSelfConstructionQueueTopUpPolicy`) | CLI→CLI; invented `--max`/`--specs` |
| Autônomos productive cycle | `AtlasSelfConstructionRuntimeDaemon` extracted from `AtlasSelfConstructionRuntimeDaemonCommand::productiveCycle` (both command and AAEOS call it) | a private duplicate composition; a new daemon |
| task claim/worker | `AtlasTaskServingService` + native worker | calling `task next` a worker cycle |
| execution contract | `ExecutionOrder` v2 (28-field canonical payload) + `EngineeringOutcome` | an `EngineeringMission*` envelope; conflicting axes |
| adjudication | `EngineeringQualityCourt`; `AtlasVerificationCourtFalseGreenDetector` | an AAEOS court |
| release authority | `KernelEvidenceAuthority`; `MergeGovernor` family (`AtlasMergeGovernor{AdmissionPolicy,RiskClassifier,RollbackPlanGate,ReleaseDecisionLedger}`) | a `SovereigntyPort` package |
| mutative-surface authority | `AtlasWorkspaceIntelligenceExecutionGateService::gate` (mode-derived confused-deputy guard; `autonomos` in its mutative set; unknown fails closed) | a second mutative-surface gate |
| code effect | `AtlasTaskScopedCommitter`; `AtlasTaskMergeActuator` (`::changedFiles`:883 diff-tree at landed SHA — **private; extract to a callable seam** for external readback) | a generic AAEOS actuator |
| canary/settlement | `CanarySettlementRequest` (`landedSha`, `observerIdentity`, `idempotencyHash`) | a second settlement state machine |
| compensation/rollback | `AtlasTaskMergeActuator::prepareRevert`→`AuthorizedRevertAction` (+ `revert()`) | AAEOS actuating a revert itself |
| cross-journey isolation | `AtlasTaskMergeActuator::leaseIsLive` + `atlas_task_scope_reservations` (lease/fencing/scope_hash; `AtlasTaskServingService` blackboard-defer) | a second lease/reservation authority |
| evidence | `AtlasEvidenceLedger`; readback = `EvidenceLedgerHashChainIntegrityVerifier::verify` + `AtlasLedgerReplayService::eventsForEnvelope`; anchor `EvidenceLedgerDailyChainHeadAnchor` | a second ledger, counter JSON or table |
| sovereignty receipt / signing | `DecisionReceipt` + `HumanDecisionReceiptSigner` (Ed25519, `sodium_crypto_sign_detached`, `:65` config-sourced — see B3 custody caveat) | a string label as authority; a second signer |
| reversibility | `ReceiptReversibilityConsentGate` (orphan — wire only for reserved effects) | a regex consent gate |
| admission | `AtlasMergeGovernorAdmissionPolicy`; Aaeos-side add `AaeosAdmissionVerdict::REPAIR_REQUIRED` (`allowsExecution=false`) — do **not** reuse the disjoint MergeGovernor `DECISION_REPAIR` | a new 6-state AAEOS admission enum |
| independence | `SpecSourceIndependence`; `SovereignSpecFloor`; provider-lock `modelFamily` | a self-declared independence flag |
| metrics / economy | `AtlasMaestroCostAggregator`; `EngineeringOutcome`(operatorEffort/tokens) | an AAEOS cost/token truth |
| provider governance (M-lever) | `ProviderGovernanceCoverageLedger`; `ProviderGovernanceConsult`; `GovernanceConsultSkipCounter` | a second bypass meter; ungoverned spawn |
| delegated learning / memory-M | `AtlasMemoryLearningPromotionService`; `AtlasHeldEvidenceMinerService`; `AtlasOpenBrainContextPackService` | a second memory promoter / evidence→memory bridge |
| comparative claims | `RivalsClaimAuthority` | `capability_proof` from a caller |
| mode routing | `AaeosModeToDualCoreRoute` (single owner; fail-closed on invalid mode) | mode-derivation duplicated in read models |
| provider-input sovereignty (Law 9) | `AtlasConstitutionalKernelService` `sovereignty_local_first`:95/:448 + `human_approval_for_high_risk`:101 | a second data-egress policy |
| terminal audit/review | `atlas:cli:cockpit` + `AtlasReviewDeepCommand` (`atlas.review.deep_packet.v1`) | a new cockpit/shell/editor |

Only **one** new production class is permitted: `AaeosRunApplication` (a run/cycle dedup use-case, not a new owner). Minimal flow: `atlas:aaeos:run|cycle` → `AaeosRunApplication` → `AaeosCycleRuntime` → `AaeosLiveDispatchGateway` → native per-mode dispatcher → **native owner pre-effect authority replay** → native actuator acts → native actuator/settler records the **observed** effect → `AtlasEvidenceLedger` → AAEOS projects read-models. AAEOS never calls `EliteExecutorKernel::execute(ExecutionOrder)` (`:838`) directly.

### B2. Effect-authority protocol

Land-only observation is insufficient for an external irreversible effect. Five steps across existing owners:

1. **SUSPECT** — text/hints/regex/declarations may only *raise* suspicion (disk: `AaeosIntentCompiler:47-49` derives `irreversible` by regex — suspicion, never authority).
2. **PRE-AUTHORIZE** — the native gateway resolves an `authorized_effect_ceiling` from the owned effect-class ceilings AAEOS actually produces (`AtlasMergeGovernorAdmissionPolicy`/`RiskClassifier` for release; `ProviderGovernanceConsult` for provider-spawn) plus arguments/target/active authority receipt/world state, **before** the effect. No generic unowned action-registry.
3. **ACT** — the actuator performs at most the authorized effect.
4. **POST-ATTEST** — the actuator/settler derives `observed_effect_class` from the actual tool invocation / write-set (`AtlasTaskMergeActuator::changedFiles`) / landed SHA / external receipt.
5. **SETTLE** — evidence binds authorization, observed effect and outcome; a mismatch gives zero capability credit and triggers refuse-next/revoke/rollback/compensation (`prepareRevert`→`AuthorizedRevertAction`).

**Trust rules.** `ExecutionOrder` is untrusted (`::fromArray` caller-authored) until its decision/authority event is reloaded by the owner under the same lock/lease; a declaration may raise a ceiling, never lower a server-resolved class; trust derives from the emitting owner + canonical event + causation + hashes + replay, never a `minter='observer'` string. **Law 2 at the mint:** minting `release.authorized` requires an independence attestation (`SpecSourceIndependence`/`SovereignSpecFloor`); same-family author+judge cannot mint without a mechanical court verdict.

**Single-use, three nonce boundaries.** LAND nonce = `AuthorizedMergeAction::nonce` gates the merge; SETTLE nonce = `CanarySettlementRequest::idempotencyHash` gates settlement; each **provider spawn** binds single-use to its `ExecutionOrder`. No nonce is replayed across work/mode/action/owner; the actuator acts at most once; the exactly-one-winner of a consume-vs-revoke race is the `atlas_ledger_events.event_id` PRIMARY KEY via the deterministic event-id idiom (hard `INSERT`, never upsert), the loser to `release_uncertain`.

**Crash & exactly-once.** The crash matrix covers every cutpoint — authorization → provider/tool → sandbox → commit → observation → settlement → queue/lease/report → Forge cycle transition — on the real durable backend: deterministic fault injection at every cutpoint; restart converges to **exactly one effect + one absorbing terminal or precise `release_uncertain`**; Postgres unique constraints/locks prove real races; no second outbox/store.

### B3. Proof taxonomy, scorecard, and anti-fabrication

Proof levels never imply the next: `PLANNED < SOURCE_WIRED < AUTOMATED_CHARACTERIZED < DRY_RECEIPT < LIVE_EFFECT < REAL_OPERATION`. `REAL_OPERATION` is the **terminal derivable level**; `SUSTAINED` (a window+SLA time-series) has no per-cycle derivation and lives only in the Horizon. `certify ok` today = `AUTOMATED_CHARACTERIZED` of structural invariants, not `REAL_OPERATION`.

Every dimension carries `status ∈ {measured, unknown, not_applicable, assessment_only, failed}`, `value`, `source`, `window`, `numerator`, `denominator`, `sample_size`, `failure_reason`; `unknown` has `value:null`, never a floor. `effect_level ∈ {none, prepared, mutated, provider_executed, blocked}` and `proof_level` are **derived** from observed evidence, not settable fields — illegal pairs (`blocked`+`LIVE_EFFECT`) are unrepresentable, not veto-listed. `capability_proof ∈ {none, internal_only, comparative}` is derived from hard gates/Rivals, never persisted from a caller; any UI/text claiming "god/SOTA/Elite" while `capability_proof ≠ comparative` fails a hard gate. **Reflexive closure:** `certify` runs the anti-fabrication matrix over its own certification evidence — a green certify surviving removal of any hard input is itself rejected.

**Anti-fabrication (REAL_OPERATION).** Produced **outside PHPUnit** on a real workspace + durable DB with real provider/tool/effect; a fresh process recomputes all truth; simulator/exit-0/test-JSON never qualifies. Each leg re-derives from a **distinct source the journey process does not jointly control**:
- **(i) Authenticity anchor** — a sovereign Ed25519 signature over the journey-root manifest by `HumanDecisionReceiptSigner::signDecision`. **Open P4 requirement, not a code guarantee (verified):** on the current disk the keypair is config-sourced (`atlas_code_signing.keypair_base64`, `:65`, same process env), so a same-host fabricator reading config could forge it. This leg becomes an authenticity anchor **only** once P4 proves the signing key is custodied off the journey process (separate host / OS keychain / HSM). Until then it is tamper-evidence, and the from-scratch same-host-fabricator gap is an **explicit open P4 requirement**.
- **(ii) Provider binding** — REAL_OPERATION requires ≥1 causally-linked `COVERED` provider spawn re-derived from `ProviderGovernanceCoverageLedger`; a zero-provider hand-built commit cannot qualify.
- **(iii) Tamper-evidence legs** — the git-world leg (`changedFiles(repo, landedSha)`; **landed SHA and write-set hash are ONE correlated measurement, counted once**, since `changedFilesHash:807` derives from `changedFiles:883`), the external effect receipt, and the durable-environment attestation (`EvidenceLedgerDailyChainHeadAnchor` + DB identity + git HEAD + no-PHPUnit) — form a subtract-one matrix where removing any single **source** flips the verdict.
- The ledger hash-chain (`EvidenceLedgerHashChainIntegrityVerifier`) and Infection suite-adequacy (`QualityFoundryMutationCoverageRunner`) are **tamper-evidence / suite gates**, NOT authenticity legs.

**Honest reach.** The matrix is tamper-evident and proves a real out-of-PHPUnit git+DB effect with a governed provider; a from-scratch same-host fabricator is defeated *only* by the off-host key custody in (i) — an open P4 item, not closed by this plan.

---

## PART C — DISK TRUTH (verified; revalidate before every slice)

| Fact | State | Consequence |
|---|---|---|
| Aaeos live tree | 27 PHP under Control/+Spine/ | no foreign package |
| Quarantine | directory absent (code-graph symbols are stale index) | never recreate/import |
| `runtime_write_performed` | hardcoded at `AaeosCycleRuntime:93`, `AaeosOrgStateProjector:46`, `AaeosScorecardProjector` | derive from dispatch effect kinds + `changedFiles`, not DualCore bookkeeping |
| Spine self-green | `AaeosEngineeringSpine::assertShared(mode, [])` defaults evidence/delivery to constants → `ok:true` with zero refs; `evidenceSlots`/`deliveryArtifacts`:94-119 unenforced; called with `[]` at `:75` | fail closed on applicable missing refs |
| Autônomos dispatch | `brainNextArgs` passes `--scope`; `AtlasBrainNextCommand` signature is positional `{scope}`, no `--scope` option → live always fails | P1a hard gate |
| Brain success | exit 0 can be `disabled`/`dry` | classify by payload status |
| Seed `--max` | command has none; dispatcher invents it | P1a: in-process enqueue seam, no CLI hand-off |
| admission | `AaeosAdmissionPolicy:36` `invalid_mode → HALT_SOVEREIGN` | technical ≠ sovereign; `REPAIR_REQUIRED` |
| provider governance | `ProviderGovernanceConsult::enforce` default OFF/observe-only (`:122`) | Law 9 must fail-closed independent of it |
| scorecard | `AaeosScorecardProjector:30-57` hardcoded dims (9.5/9.5/9.2) + `god_sota>=9.0` | gut to measured-only before projecting views |
| ExecutionOrder | v2, 28-field, `duration_regime`+`work_topology` enums | do not add conflicting axes |
| dead self-graders | `AaeosOperateScorecardProjector` (0 consumers) + `AaeosTriHygieneScorecardProjector`/command (LOC/`is_file` proxy) | delete |

Revalidation: `git branch --show-current`; `rg --files app/Services/Ai/Aaeos -g '*.php'`; `rg -n 'human_in_engineering_loop|runtime_write_performed|assertShared' app/Services/Ai/Aaeos`; `rg -n 'brainNextArgs|--scope|--max' app/Services/Ai/Aaeos app/Console/Commands/AtlasBrainNextCommand.php`.

---

## PART D — FAILURE INVENTORY (complete; theme-grouped; every done-condition falsifiable)

Themes group the residuals into an executable inventory. Rows sharing a theme but with distinct owner+phase+done-condition stay **independently closable** (no-fuse guard). None waivable into DONE.

**T1 — Live transport (P1a).** `brain:next` positional scope + default; payload-status success (not exit-only); the seed leg enqueues in-process via `AtlasSelfConstructionNativeReplenisherEnqueueRunner`→`AgentControlPlaneTaskQueueOrchestrator::prepareAndEnqueue` (drop `--max`/`--specs`, never `no_packets`). *Done:* live dispatch drives brain→packet→enqueue in-process; counts reflect real drafts in a fresh readback.

**T2 — Truth-before-capability (P0).** derive `runtime_write_performed` (3 sites) from dispatch effect kinds + `changedFiles`; unify `run`/`cycle` on `AaeosRunApplication`; typed `effect_level`; gut the `god_sota>=9.0` self-grade to measured-only; dry-run zero writes; remove `human_in_engineering_loop` (3 call-sites), never hardcode false; `invalid_mode → repair_required` via new `AaeosAdmissionVerdict::REPAIR_REQUIRED`, propagated to its const-consumers (`AaeosCycleOutcomeRecorder:33-34`, `AtlasAaeosCertifyCommand:43`) through `allowsExecution()`.

**T3 — Effect authority (P1b, refusal-only until P2a).** the B2 five-step bound to native owners; `ExecutionOrder` decision-event reloaded before provider/tool; server-resolved effect class, declared fields advisory-monotone; present-but-false + no-keyword goldens block by observed effect; independence-at-mint (Law 2); `AtlasDevPlanApprovalGate`/`AtlasDevProviderExecutionBlock` named read-only so the AAEOS Dev path stays gate-free (OneShot). *Dependency:* P1b is characterization/refusal-only for authoritative ACT until P2a Ledger + P2b Decision-v3/keyring/revocation + AWIS parity exist (else absent/stale authority ⇒ zero provider/tool/sandbox/mutation).

**T4 — Canonical evidence integrity (P2a, one readback).** verified by a single fresh-process readback (`EvidenceLedgerHashChainIntegrityVerifier::verify` + `AtlasLedgerReplayService::eventsForEnvelope`): (a) ledger chain/envelope recompute + bounded query + divergent-duplicate veto; (b) N11 spine ref resolves to a settlement-emitted effect receipt, empty/declared refs fail closed, applicability server-derived from `evidenceSlots`/`deliveryArtifacts` (folds old R44); (c) non-circular `receipt_core_hash` (excludes event id/hash), linear append head/position; (d) journey one-root ordered **causal-DAG manifest**, canonical fold **stable under concurrent permutation**, splice/omit/reorder/race goldens fail.

**T5 — Identity & mandate (P1b/P2b).** typed native ingress + authority lineage, no remint/fallback (`DevIntent`/`ConfirmedDevRun`/`ForgeCommissioning`); standing mandate Ed25519-signed, active + non-revoked at origination, claim/renew, **after any long provider call**, and immediately before sandbox/effect under the lock (absence/stale/mismatch ⇒ zero effect); schema migration expand→dual-read→canary→cutover, legacy read-only, unsupported version refuses before effect (`DecisionReceipt` v3 + keyring + revocation head).

**T6 — Journey & repair (P2/P4).** distinct `served|claimed|executed|landed|resolved` refs; `already_done` gets no new-work credit; every repair/replan/re-review is an **intra-journey** continuation on the single-root manifest (same root/budget across reject→repair→re-review; late siblings evidence-only); **crash resumes one cycle/claim/effect without duplicate**; reserved-decision captures derive from ledger escalation events (`DecisionDrafted`/`EscalationRequested`/`DecisionIssued`); **H1–H7 two-work-item witness** — while A persists a reservation and produces no reserved effect, unrelated B is actually claimed/executed/settled, then A resumes exactly once under its `action_hash`/`continuation_ref` (no global halt masquerading as zero-wait). Owners: `AtlasRepairOrchestrator`/`ReceiptStorage`, Forge cycle journal, `AtlasTaskServingService`.

**T7 — Independence (P2/P3).** author ≠ judge ≠ governor by principal/capability/mechanical evidence; `SelfComposedUnwitnessed` fails in all modes merely because an operator exists; same model-family degrades to a mechanical `EngineeringQualityCourt`/`AtlasVerificationCourtFalseGreenDetector` verdict (`SpecSourceIndependence`, `SovereignSpecFloor`, provider-lock `modelFamily`).

**T8 — Economy & M (P3, read-only).** `scorecard --view=economy` over **every commissioned journey** (ITT cohort; includes blocked/timeout/cancel/failure/retry/rollback burn); `enforced_governed_coverage` + refuse/repair rate as the falsifiable M (never the ledger `governed` folding `consulted`); **amplification neutrality:** AAEOS adds **zero** provider/context/retry/worker/mutation hops vs the paired direct journey; no-delta retries held before another spawn; fan-out deduped under the signed root budget. Owners: `AtlasMaestroCostAggregator`, `EngineeringOutcome`, `ProviderGovernanceCoverageLedger`.

**T9 — Deletion & hygiene (P0/P3).** delete `AaeosOperateScorecardProjector` (0 consumers) + `AaeosTriHygieneScorecardProjector`/command (LOC/`is_file` proxy); delete the four `Control/Adapters/*` after parity characterization; make `AaeosHygieneLegacyAliases::register()` lazy (reuse `RootSinglesLegacyAliases`); partition the AEOS 43k-LOC lattice from the live Kernel path with a guard test (keep Scoring cores; demote 17-phase runbook + department authority); alias burn evidence-led (classmap/runtime proof, not `rg=0`).

**T10 — Product surface (P1/P3).** the 2026 verification moat is first-class as a `scorecard --view=verification` projected by the (gutted, measured-only) `AaeosScorecardProjector` from the T4(d) journey manifest — operator artifact = observer-minted effect + proof + admission + manifest — consumed by both `atlas:cli:cockpit` and `atlas:review:deep` (optional inspection, never a required operator verdict). Away-operator failure-**surfacing** via a `scorecard --view=liveness` over ledger adverse-terminal events + `AtlasSelfConstructionRuntimeDaemonState` heartbeat (no daemon, no alerting service).

**T11 — Cold-start & public-entry parity (P1a/P4).** extract `AtlasSelfConstructionRuntimeDaemon` from the command's private `productiveCycle` so direct daemon and AAEOS-routed dispatch return the **same** native journey/cycle/task refs through the same service (AAEOS ablation leaves direct execution green); a fresh-process matrix proves `atlas dev`, `atlas forge`, direct Autônomos and routed `atlas:aaeos:run` preserve exact native mode/root/authority/outcome semantics with zero second technical action; **no legacy executor/alias/adapter/review owner is deleted until all production/config/reflection/doc consumers are migrated and cold-process negative resolution is green.**

**Horizon (not P4 gates, correctly deferred):** SUSTAINED time-series; durable government at scale / crash-recovery at scale; topology ablation (sister Rivals/Foundry); external comparative superiority (`capability_proof=comparative`); multi-domain M; multi-operator concurrent-session isolation (solo-operator reality; reuses `correlation_id`/`envelope_id` if ever needed); memory-M compounding loop (read-side `AtlasOpenBrainContextPackService` live; write-side H4 propose-only via `AtlasHeldEvidenceMinerService`→`AtlasMemoryLearningPromotionService`, currently un-fed); the off-host signing-key custody that turns B3(i) into a true authenticity anchor.

---

## PART E — EXECUTION

### E1. Phases (bounded, ordered, path-manifested)

**Scope fence (per phase):** each phase begins with branch/status, current hash, dirty-ownership attribution and RED-first characterization; its touched production/test paths are a **closed authorization manifest** in the phase receipt at EXECUTE time. If a RED proves an unlisted path must change, execution **stops** and this plan is amended first. `*`, "if needed" and unnamed owners are forbidden. **Named RED per gate:** every theme + every B2/B3/E2 done-condition names one existing-or-new test red on today's code (reuse `AaeosControlPlaneTest`, `AaeosOperateDispatchTest`; invert `AaeosGodSotaCertificationTest`); a slice without a red-first test does not start. The reuse-only / no-new-owner law is mechanized by one owner-census architecture test (glob over `app/Services/Ai/Aaeos` asserting every named production symbol resolves and no new production class beyond `AaeosRunApplication` exists).

- **P0 — truth/port:** T2 + T9 deletions. Remove false success, unify the port, honest read-only projection. No Brain/effect fix.
- **P1a — native dispatch:** T1 + T11 daemon-seam extraction (fuse the adapters/dispatchers into the existing gateway; fix R33/R34/R35; strip productive router flags). Behavior-preserving fuse + contract fix only.
- **P1b — effect authority (refusal-only until P2a):** T3 + T5 signing; server-resolved effect class; admission taxonomy; compensation owner; independence-at-mint; both land chokepoints observer-minted. Authoritative ACT gated on P2a/P2b.
- **P2a — evidence foundation:** T4 (ledger readback/manifest/non-circular core/exactly-once hard-INSERT).
- **P2b — authority infra:** T5 Decision-v3/keyring/revocation + AWIS parity; then P1b authoritative replay/ACT unlocks.
- **P2 (cont.) — durability:** T6 journey/repair/crash-resume/H1–H7 witness; T7 independence.
- **P3 — deletion/alignment + economy:** T8 (read-only) + T9 remainder + T10 + docs canonical alignment; observe compaction only on measured evidence.
- **P4 — real journey (out-of-PHPUnit):** one completed `REAL_OPERATION` journey per mode via native owners, retries/reviews/repairs preserved; server-derived OneShot; producers reuse `AtlasDevSeniorLoopRunCommand`/`AtlasForgeLiveExecuteCommand`/`AtlasSelfConstructionRuntimeDaemon`. Autônomos runs an operator-authorized read-only preflight (`AutonomosPreflightService`) + the extracted daemon cycle; if preflight fails (master-switch OFF / stale heartbeat) it records `blocked_ops` with a precise `failure_reason` and stays PARTIAL — an honest partial, never a waiver.

### E2. Completion predicate

**The 90-day committed deliverable is P0–P3 DONE + P4 PARTIAL** (Dev/Forge journeys real; Autônomos `blocked_ops` surfaced), because Autônomos `REAL_OPERATION` depends on the operator master-switch + a live heartbeat (currently off/stale); full DONE lands once the operator enables the fleet.

DONE iff: P0–P4 receipts green; every theme T1–T11 blocker closed (or explicitly `not_applicable`, or a named horizon); Dev/Forge/Autônomos each have a completed `REAL_OPERATION` journey with internal retries preserved; effect/proof/`runtime_write_performed` match canonical events + fresh readback; every adverse state has a precise `failure_reason`; one canonical `AtlasEvidenceLedger` passes integrity/replay/DAG-fold; failures surfaced via `--view=liveness`; direct modes usable without AAEOS **and still governed** (the ablation acceptance leg); the amplification-neutrality paired golden passes (efficiency gated, not just quality); Quarantine/ACDE absent; no forbidden duplicate owner created; commits scoped on local `main`; `capability_proof ≥ internal_only`. DONE means `aaeos_mt_real_journey_verified` + optionally `operator_experience=oneshot` (operator-effort only) — **not** one-pass code, sustained 24/7, comparative superiority, or "complete autonomous software company"; those need their own current evidence and claim authority.

### E3. Non-goals (hard vetoes)

Mission Runtime/Envelope/Receipt; WorkGraph-as-OS; SovereigntyPort package; `AaeosModeExecutor`; `AaeosActionEffectClassifier` as authority; generic external-effect gateway under AAEOS; second Ledger/counter/table; Evaluation Foundry inside P4; new cockpit/shell/editor; Quarantine/ACDE resurrection; `mandate_epoch` as a free number; `minter='observer'` as proof; `capability_proof` from a caller; hardcoding the human field `false`; flattening Forge into a dispatch; claiming Autônomos worker execution from `task next`.

### E4. Handoff

PLAN_ONLY; no production code authorized. On operator acceptance this file becomes canonical: LEDGER/SCOREBOARD repoint here; the Codex `…-MASTER.md` v15 and the C6 competitor archive (their unique falsifiable content is folded above). The next real action is **`EXECUTE P0`** (authorizes only E1-P0), then **P1a to close R33** — the single disk bug that unblocks live Autônomos. Not another plan cycle.

---

## Honest bottom line

This is a **plan** at its structural ceiling — the strongest constitution + owner map + effect protocol + failure inventory the two tracks produced, honesty-corrected against an external judge. It is **not** "Atlas at the M ceiling": P0–P4 are NOT_STARTED, R33/R51/the human field/admission are still open in code, zero PHASE receipts exist, and measured M in production is `null`. The masterpiece of the *plan* is done. The masterpiece of the *system* starts at `EXECUTE P0`.
