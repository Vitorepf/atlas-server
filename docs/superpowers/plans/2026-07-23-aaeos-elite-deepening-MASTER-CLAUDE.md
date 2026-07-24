# AAEOS — Mother Block of Agentic Software Engineering (Claude independent MASTER)

> Author track: Claude (Opus). Independent competitor to the Codex `…-MASTER.md`. The operator compares and picks/fuses.
> Version: C1 — constitution-first, disk-verified, theme-grouped residuals, falsifiable M.
> Date: 2026-07-23 · State: PLAN_ONLY · P0–P4 NOT_STARTED · code needs literal `EXECUTE P0`.
> Branch: local `main` only; scoped commits; never `git add -A`.
> Every disk claim here was verified by direct read/grep this session; each names the file. Distrust and re-verify before executing.

This file is a competitor draft. It is not the shared MASTER and holds no authority over it. Its bet: the best plan is not the one with the most residuals — it is the smallest **constitution** that makes the right things impossible to get wrong, over an owner map that reuses what Atlas already has.

---

## PART A — CONSTITUTION (the mother block is LAW)

### A0. What AAEOS is

AAEOS is the **mother block of all Atlas agentic software engineering**: the constitution and governance authority that every engineering mode obeys — the product law (A1), the effect-authority protocol (B2), the proof taxonomy (B3) and the residual government (D). It is **thin in muscle** (it re-implements no executor, court, governor, ledger or actuator) and **central, non-bypassable in law**. "Optional" describes only the daily *router* command `atlas:aaeos:run`; it never describes the law. Direct `Dev`, `Forge` and `Autônomos` remain primary entries, and their engineering still runs under this constitution.

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
- OneShot proof is **server-derived** from canonical operator-request/action events; a dispatcher or receipt caller cannot declare it. Missing capture is `unknown`, never zero.

Therefore no class, flag, queue, retry policy, test or P4 criterion may read OneShot as one attempt, one dispatch, one pass, immediate delivery or a shortcut around review. Building OneShot *into the code* is forbidden — it produces exactly the bug-cascade the operator named.

### A3. The M thesis (the reason the mother block exists), made falsifiable

Useful output ≈ **N × M**. `N` = raw provider power. `M` = what Atlas multiplies on top: governance, refusal of bad output, proof, memory, recovery, mandate — applied at real effect boundaries. AAEOS exists to maximize `M` for the engineering domain without re-implementing muscle.

`M` is real only if measured, and it is measured from **existing owners**, never a vanity score:
- `governed_spawn_coverage = governed_provider_spawns / total_provider_spawns` — the honest bypass meter (disk: `ProviderGovernanceCoverageLedger`, schema `atlas.ai.governance.provider_coverage.v1`; empty ledger reports `0.0`, never fabricated).
- `provider_proposal_refuse_repair_rate` — how often untrusted output is caught before land (disk: `EngineeringOutcome` + `AtlasEvidenceLedger`).
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
| Autônomos origination/seed | `AtlasBrainNextCommand`/`AtlasBrainSeedCommand` logic extracted to a shared application seam | CLI→CLI forever; invented `--max` |
| task claim/worker | `AtlasTaskServingService` + native worker | calling `task next` a worker cycle |
| execution contract | `ExecutionOrder` v2 (28-field canonical payload, `duration_regime`+`work_topology` enums — verified) + `EngineeringOutcome` | an `EngineeringMission*` envelope/receipt; conflicting axes |
| adjudication | `EngineeringQualityCourt`; `AtlasVerificationCourtFalseGreenDetector` | an AAEOS court |
| release authority | `KernelEvidenceAuthority`; `MergeGovernor` family (`AtlasMergeGovernor{AdmissionPolicy,RiskClassifier,RollbackPlanGate,ReleaseDecisionLedger}`) | a `SovereigntyPort` package |
| code effect | `AtlasTaskScopedCommitter`; `AtlasTaskMergeActuator` (`::changedFiles` = diff-tree write-set at landed SHA) | a generic AAEOS actuator |
| canary/settlement | `CanarySettlementRequest` (`landedSha` + `observerIdentity`) | a second settlement state machine |
| compensation/rollback | `AtlasTaskMergeActuator::prepareRevert(CanarySettlementRequest)→AuthorizedRevertAction` (+ `revert()`) | AAEOS actuating a revert itself |
| evidence | `AtlasEvidenceLedger`; chain-replay `AgentControlPlaneDeterministicChainReplayService` | a second ledger, counter JSON or table |
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

Minimal flow: `atlas:aaeos:run|cycle` → `AaeosRunApplication` → `AaeosCycleRuntime` → `AaeosLiveDispatchGateway` → existing per-mode dispatcher → **native owner performs pre-effect authority replay** → native actuator acts → native actuator/settler records the **observed** effect → `AtlasEvidenceLedger` → AAEOS projects read-models (scorecard/certify/cockpit/review). AAEOS never calls `EliteExecutorKernel::execute(ExecutionOrder)` (verified at `EliteExecutorKernel.php:838`) directly; native owners create the order at their boundary.

### B2. Effect-authority protocol (authority at the effect boundary, not in a facade)

Land-only observation is **insufficient** for an external irreversible effect — it cannot be blocked retroactively. Five steps, across existing owners:

1. **SUSPECT** — text, hints, regex and caller declarations may only *raise* suspicion. (Disk: `AaeosIntentCompiler.php:47-49` derives `irreversible` by regex today; that is suspicion, never authority.)
2. **PRE-AUTHORIZE** — the native tool/actuator gateway resolves an `authorized_effect_ceiling` from a server-owned action-registry/tool-contract, arguments, target, active authority receipt and world state, **before** the effect.
3. **ACT** — the actuator performs at most the authorized effect.
4. **POST-ATTEST** — the actuator/settler derives `observed_effect_class` from the actual tool invocation, write-set, landed SHA or external receipt (reuse `AtlasTaskMergeActuator::changedFiles`).
5. **SETTLE** — evidence binds authorization, observed effect and outcome; a mismatch gives zero capability credit and triggers refuse-next/revoke/rollback/**compensation** (owned by `AtlasTaskMergeActuator::prepareRevert`).

Trust rules: `ExecutionOrder` is an untrusted proposal (it is `ExecutionOrder::fromArray(array)` — caller-authored, verified) until its decision/authority event is reloaded by the owner under the same lock/lease; a declaration may raise a ceiling, never lower a server-resolved class; trust derives from the emitting owner + canonical event + causation + hashes + replay, never from a `minter='observer'` string.

Sovereignty reserve is narrow and asynchronous (H1–H7): constitution/root-policy, material value conflict, truly-irreversible external effect outside delegated authority, canonical-memory promotion beyond delegated policy, autonomy/ceiling expansion, root-credential expansion, sensitive-domain entry. Everything reversible under courts/canary/rollback stays agentic. A reserved effect *blocks that effect only*, persists a sovereign work item as a ledger escalation event (`DecisionDrafted`/`EscalationRequested`/`DecisionIssued`), and other admissible work continues — Autônomos never waits synchronously. A **technical** invalidity (e.g. invalid mode) is `repair_required`, never `halt_sovereign` (disk: `AaeosAdmissionPolicy.php:36` wrongly maps `invalid_mode→HALT_SOVEREIGN` today).

### B3. Proof taxonomy and scorecard

Proof levels never imply the next: `PLANNED < SOURCE_WIRED < AUTOMATED_CHARACTERIZED < DRY_RECEIPT < LIVE_EFFECT < REAL_OPERATION < SUSTAINED`. `certify ok` today = `AUTOMATED_CHARACTERIZED` of structural invariants, not `REAL_OPERATION`.

Every scorecard dimension carries `status ∈ {measured, unknown, not_applicable, assessment_only, failed}`, `value`, `source`, `window`, `numerator`, `denominator`, `sample_size`, `failure_reason`. `unknown` always has `value:null` and never a floor number. `effect_level ∈ {none, prepared, mutated, provider_executed, blocked}` and `proof_level` are **derived** from observed evidence, not settable fields — illegal pairs (e.g. `blocked`+`LIVE_EFFECT`) are unrepresentable, not merely veto-listed. `capability_proof ∈ {none, internal_only, comparative}` is derived from hard gates/Rivals and never persisted from a caller; any UI/text claiming "god/SOTA/Elite-world" while `capability_proof ≠ comparative` fails a hard gate.

`REAL_OPERATION` proof must be produced **outside PHPUnit** on a real workspace and durable DB with real provider/tool/effect where required; a fresh process recomputes all truth; simulator/exit-0/test-JSON never qualifies.

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

**T1 — Live transport contract (P1a).** `brain:next` positional scope + default; payload-status success (not exit-only); real seed flags (no `--max`); Brain/Seed extracted to a shared application seam, not CLI→CLI. Owners: `AtlasBrainNextCommand`, `AtlasBrainSeedCommand`, `AtlasTaskServingService`.

**T2 — Truth-before-capability (P0).** derive `runtime_write_performed`; unify `run`/`cycle` on `AaeosRunApplication`; typed `effect_level`; measured scorecard with provenance (no hints/constants); dry-run zero writes; remove `human_in_engineering_loop` reads/writes (call-sites `certify:34`, `CycleRuntime:107`, `DevModeAdapter`), never hardcode false; `invalid_mode → repair_required` (reuse `DECISION_REPAIR`), not sovereign.

**T3 — Effect authority (P1b).** PRE-AUTHORIZE→ACT→POST-ATTEST→SETTLE bound to native owners; `ExecutionOrder` decision-event reloaded before provider/tool; server-resolved effect class, declared fields advisory-monotone; present-but-false and no-keyword goldens block by observed effect; `AtlasDevPlanApprovalGate`/`AtlasDevProviderExecutionBlock` named read-only so the AAEOS Dev path stays gate-free (OneShot). Compensation for a landed-then-bad release via `prepareRevert`.

**T4 — Canonical evidence integrity (P2, one readback).** These are one property at four altitudes, verified by a **single fresh-process readback**, but kept as distinct owner+phase rows (no-fuse guard): (a) ledger chain/envelope recompute + bounded query + divergent-duplicate veto [`AtlasEvidenceLedger`]; (b) N11 spine ref resolves to a settlement-emitted effect receipt, empty/declared refs fail closed [`AaeosEngineeringSpine` — folds the old standalone R44]; (c) non-circular `receipt_core_hash` (excludes event id/hash) [`AaeosCycleRuntime`+ledger]; (d) journey one-root ordered manifest of event/native/artifact hashes + terminal-status enum, splice/omit/reorder fail. Crash/exactly-once: deterministic fault injection at every cutpoint; restart converges to one effect + one absorbing terminal or precise `release_uncertain`; Postgres unique constraints/locks prove real races; no second outbox.

**T5 — Identity & mandate (P1b/P2).** typed native ingress + authority lineage (no remint/fallback) [`DevIntent`/`ConfirmedDevRun`/`ForgeCommissioning`]; standing mandate Ed25519-signed, active and non-revoked at origination/claim/renew/pre-effect (absence/stale = zero effect) [`HumanDecisionReceiptSigner`]; schema migration expand→dual-read→canary→cutover with legacy read-only [`DecisionReceipt` v3].

**T6 — Journey & repair (P2/P4).** distinct `served|claimed|executed|landed|resolved` refs; `already_done` gets no new-work credit; every repair/replan/re-review is an **intra-journey** continuation appended to the single-root manifest, never a new journey [`AtlasRepairOrchestrator`, Forge cycle, `AtlasTaskServingService`]; reserved-decision captures derive from ledger escalation events, not operator per-task actions (keeps zero-touch Autônomos consistent).

**T7 — Independence (P2/P3).** author ≠ judge ≠ governor by principal/capability/mechanical evidence; `SelfComposedUnwitnessed` fails in all modes merely because an operator exists; same model-family author+judge degrades to a mechanical `VerificationCourt` verdict [`SpecSourceIndependence`, `SovereignSpecFloor`, provider-lock `modelFamily`].

**T8 — Economy & M (P1/P3, read-only).** `scorecard --view=economy` over **every commissioned journey** (ITT cohort; includes blocked/timeout/cancel/failure/retry/rollback burn), accepted-landing efficiency secondary and observer-minted only [`AtlasMaestroCostAggregator`+`EngineeringOutcome`]; `governed_spawn_coverage` + refuse/repair rate as the falsifiable **M** instrument [`ProviderGovernanceCoverageLedger`]; delegated-learning/memory-M seam named at H4 [`AtlasMemoryLearningPromotionService` et al.]. No new receipt field; instrumentation never raises the OneShot/Autonomy burden.

**T9 — Deletion & hygiene (P0/P3).** delete `AaeosOperateScorecardProjector` (zero consumers) and `AaeosTriHygieneScorecardProjector`+command (LOC/`is_file` proxy); delete the four `Control/Adapters/*` after parity characterization; make `AaeosHygieneLegacyAliases::register()` lazy (reuse `RootSinglesLegacyAliases` pattern); AEOS 43k-LOC lattice partitioned from the live Kernel path with a guard test (keep Scoring cores; demote 17-phase runbook + department authority); alias burn evidence-led (classmap/runtime proof, not `rg=0`).

**T10 — Product surface (P1/P3).** the 2026 verification moat is first-class: one verification read model that both `atlas:cli:cockpit` and `atlas:review:deep` consume — operator artifact = observer-minted effect + proof + admission + journey manifest [`AtlasReviewDeepCommand`]; no new shell.

**Horizon (not P4 gates):** durable sustained government at scale; topology ablation (sister Rivals/Foundry); external comparative superiority (`capability_proof=comparative`); multi-domain M (trading/marketing/…) composing the same governance without restart.

---

## PART E — EXECUTION

### E1. Phases (bounded, ordered, path-manifested)

- **P0 — truth/port:** T2 + T9-deletions. Remove false success, unify the port, honest read-only projection; no Brain/effect fix. RED-first on fake constants, dry writes, invalid caps, alias parity.
- **P1a — native dispatch:** T1 (fuse the 8 adapters/dispatchers into the existing gateway path; R33/R34/R35). Behavior-preserving fuse + contract fix only. Commit separate from P1b.
- **P1b — effect authority:** T3 + T5-signing. Server-resolved effect class; admission taxonomy; compensation owner; land-observer both chokepoints. Security slice, isolated commit.
- **P2 — authority/evidence/durability:** ordered DAG **P2a first** (T4 ledger/evidence foundation) → T5-ingress → T6 → T7 → crash/exactly-once. Three slices edit `AtlasEvidenceLedger.php` under the same-file/same-owner commit rule.
- **P3 — deletion/alignment:** T9-remainder + T10 + docs canonical alignment; observe compaction only on measured evidence.
- **P4 — real journey (out-of-PHPUnit):** one completed `REAL_OPERATION` journey per mode through its native owner chain, retries/reviews/repairs preserved; server-derived OneShot label; the single point of DONE-failure is P4-Autônomos (master switch + stale heartbeat — R36 ops preflight).

### E2. Completion predicate

DONE iff: P0–P4 receipts green; every theme T1–T10 blocker closed (or explicitly `not_applicable`, or a named horizon this file already marks); Dev/Forge/Autônomos each have a completed `REAL_OPERATION` journey with internal retries/reviews preserved; effect/proof/`runtime_write_performed` match canonical events and fresh readback; every adverse state has a precise `failure_reason`; one canonical `AtlasEvidenceLedger` passes integrity/replay; direct modes remain usable without AAEOS; Quarantine/ACDE absent; no forbidden duplicate owner created; commits scoped on local `main`. `capability_proof ≥ internal_only`. DONE means `aaeos_mt_real_journey_verified` + optionally `operator_experience=oneshot` (operator-effort only) — it does **not** mean one-pass code, sustained 24/7, comparative superiority, or "complete autonomous software company"; those need their own current evidence and claim authority.

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
