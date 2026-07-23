# AAEOS Elite Deepening — MASTER Implementation Plan

> Version: v9 — operator-OneShot, journey integrity and native-loop closure (R75–R82)
> Date: 2026-07-23
> State: PLAN_ONLY
> Implementation: P0–P4 NOT_STARTED
> Authorization required for code: literal EXECUTE P0
> Branch: local main only; scoped commits; never git add -A
> Evidence: docs/evidence/2026-07-23-aaeos-elite-deepening

This is the only master plan for this program. LEDGER.md and SCOREBOARD.md are its only normative satellites. A line, checkbox, score, test fixture, dry receipt, source wire or commit never promotes a stronger proof level by implication.

## 0. Outcome and authority of this document

The target is not a bigger AAEOS. It is a smaller optional Crown that reliably routes into the engineering systems Atlas already owns, while those systems enforce authority, evidence, recovery and claims at their real effect boundaries.

This version replaces the duplicated v2–v6 structure. Historical facts and residual IDs are retained here; repeated phase catalogs, receipt schemas, DONE lists, file maps and architecture diagrams are deleted.

If two statements conflict:

1. live code and durable evidence outrank this plan;
2. the phase contracts in section 8 outrank prose;
3. the completion predicate in section 10 outranks scores;
4. the owner map in section 3 forbids a parallel owner;
5. uncertainty is NOT_PROVEN, never PASS.

## 1. Product law

### 1.1 Three elite executors

Dev, Forge and Autônomos have the same technical quality floor and the same L0–L5 difficulty range. Their difference is primarily horizon, work origin and sovereignty arrangement, never quality.

| Executor | Normal horizon | Work origin | Sovereignty arrangement | Human during engineering |
|---|---|---|---|---|
| Dev | interactive session | live intent | current intent/authority receipt | outside the technical loop |
| Forge | durable Obra | commissioned plan and work packets | sealed commissioning/authority | absent after planning, except a true sovereignty exception |
| Autônomos | continuous queue | Brain → Seed → Task | standing mandate | absent |

The operator states purpose, grants or revokes authority and may inspect the audit surface. Atlas understands the project, makes technical judgments and acts. Routine questions, diff approval, test approval, release voting and provider babysitting are defects.

The legacy field human_in_engineering_loop must be removed as an authoritative field. It must not be replaced by hardcoding false. Dev is preserved by live intent; Forge by commissioning authority; Autônomos by a standing mandate.

### 1.2 Three different loops

- ENGINEERING: agentic in all three modes; author, judge and governor are independently attributable.
- SOVEREIGNTY: purpose, root authority and narrowly reserved exceptions. It may be pre-issued and asynchronous; it is not a synchronous runtime loop.
- AUDIT: optional human observation, sampling, pause or revocation. It never becomes the default engineering gate.

### 1.3 AAEOS position

AAEOS is an optional Control + Spine facade. Direct Dev, Forge and Autônomos remain primary product entries. AAEOS may route, cap, correlate and project evidence; it may not become:

- a mandatory detour;
- a fourth executor;
- a second Kernel, Court, Governor, Task Fabric or ledger;
- a generic effect actuator;
- a shell, IDE or code editor;
- a resurrection path for ACDE, atlas:loop:* or Quarantine.

### 1.4 Non-negotiable engineering laws

1. Provider output is an untrusted proposal. It never verifies, authorizes, lands, promotes learning or issues a comparative claim.
2. Author is not judge; judge is not governor. Different labels or contexts are not proof of independent principals.
3. n=1 is the default. Fan-out requires real work width and measured lift; agent count is never a KPI.
4. Less human presence increases assurance for additional failure surfaces, not the shared quality threshold.
5. Clear intent may remove ceremony, never evidence, mutation safety, courts, failure reasons or rollback.
6. No failure is silent. Every adverse terminal state has a precise failure_reason owned by its native outcome.
7. Quarantine is absent on current disk and must stay absent. ACDE remains dead.
8. A score is diagnostic. Completion is a conjunction of hard evidence.

### 1.5 OneShot Experience Law

OneShot is a property of the operator experience, never of the internal engineering algorithm.

- The operator expresses or commissions the intent once and does not become a workflow worker.
- A small number of genuine intent clarifications may occur; technical approvals and routine status questions may not.
- Internally Atlas may plan, decompose, implement, review, reject, repair, retest, re-plan, canary, roll back and repeat as many times as quality and safety require.
- First-pass acceptance, minimum elapsed time and minimum agent turns are not objectives.
- A failed Court/gate returns work to the appropriate agentic stage; it never returns routine technical labor to the operator.
- Dev may maintain live intent without turning the operator into a reviewer. Forge continues after commissioning. Autônomos operates under its standing mandate with zero per-task operator action.
- The external experience may be OneShot even when the internal execution is long-running, multi-packet, multi-agent and multi-cycle.

Therefore no class, flag, queue, retry policy, test or P4 criterion may interpret OneShot as one technical attempt, one dispatch, one pass, immediate delivery or a shortcut around review.

OneShot proof is server-derived, not declared by a dispatcher or receipt caller. It requires complete capture of the native operator-request and operator-action events for the journey. Missing capture is `unknown`, never zero. Dev may ask only intent questions inside the same commissioning episode; Forge may do so only before its commissioning is sealed; Autônomos permits zero per-task questions or actions. A substantive intent change starts a new commissioning episode rather than being hidden as a clarification.

## 2. Current disk truth

Revalidate before every execution slice. The current plan was grounded on these observed facts:

| Fact | Current state | Consequence |
|---|---|---|
| Aaeos live tree | 27 PHP, all under Control + Spine | no foreign production package is needed |
| Quarantine | directory absent; zero PHP | DELETE_DONE; never recreate or import |
| Phase receipts | zero PHASE-* artifacts | program is PLAN_ONLY |
| predecessor receipts | dry only; runtime_write_performed was incorrectly true | no real-operation proof |
| scorecard/certify | hardcoded hints and GOD_SOTA presentation | not claim authority |
| CycleRuntime | assertShared(mode, []) can self-green; runtime_write_performed=true; Dev human field=true | P0/P2 blockers |
| Autônomos dispatch | calls brain:next with nonexistent --scope; brain expects positional scope | R33 live failure |
| Brain success | exit 0 can mean disabled or dry | R34 false-positive risk |
| Seed dispatch | invents --max although command has no --max | R35 contract error |
| task next | claims/serves work; it is not a worker cycle | never report worker execution from claim |
| admission | invalid mode/technical incident can become halt_sovereign | taxonomy conflates technical and sovereign |
| irreversibility | AaeosIntentCompiler regex/hints feed admission | suspicion, not authority |
| ExecutionOrder v2 | exact schema; duration enum is interactive, durable_task, obra, continuous; work_topology already exists | do not add conflicting top-level axes |
| Dev native path | DevPlanRunFacade → AtlasDevExecutionService → Dev Kernel port → Kernel | AAEOS must not bypass it |
| Forge native path | ForgeObraRuntime → work-packet cycle → ExecutionOrder → Kernel | AAEOS must not flatten an Obra |
| Autônomos native path | Brain → Seed → TaskServing → worker → ScopedCommitter; Kernel only for quality-foundry-bound actions | do not fabricate universal Kernel parity |
| canonical land | Governor/ledger/nonce/lease/fencing/write-set bindings already exist | deepen these owners; do not create CRES owner |
| Evidence Ledger | canonical owner exists; bounded event-type/window query and full chain verification are incomplete | harden owner, never add a counter store |
| EngineeringOutcome | already owns cost, tokens, elapsed and operator_effort; adverse cause coverage is incomplete | no AAEOS economy receipt |
| claim authority | RivalsClaimAuthority exists | comparative status is derived from it |
| reversibility | ReceiptReversibilityConsentGate exists but has no production caller | wire only for true reserved effects |
| signing | Ed25519 signer exists under Services/AtlasCode; general DecisionReceipt issuer is weaker | reuse/promote primitive, not a second signer |

### 2.1 Revalidation commands

    git branch --show-current
    git status --short --branch
    rg --files app/Services/Ai/Aaeos -g '*.php'
    rg -n 'human_in_engineering_loop|runtime_write_performed|assertShared' app/Services/Ai/Aaeos app/Console/Commands/AtlasAaeosCertifyCommand.php
    rg -n 'brainNextArgs|--scope|--max|atlas:task' app/Services/Ai/Aaeos app/Console/Commands/AtlasBrainNextCommand.php app/Console/Commands/AtlasBrainSeedCommand.php
    find docs/evidence/2026-07-23-aaeos-elite-deepening -maxdepth 1 -name 'PHASE-*' -print

Any material drift updates the residual ledger before code.

## 3. Architecture and owners

### 3.1 Minimal architecture

    atlas:aaeos:run / atlas:aaeos:cycle
        → AaeosRunApplication
        → AaeosCycleRuntime
        → AaeosLiveDispatchGateway
        → existing AaeosModeLiveDispatcher port
            Dev handler       → native Dev journey → DevPlanRunFacade attempts → AtlasDevExecutionService
            Forge handler     → ForgeObraRuntime commission/tick/completeObra
            Autônomos handler → one native scheduler/daemon-cycle entry → native journey ref
        → native effect owner performs pre-effect authority replay
        → native actuator acts
        → native actuator/settler records observed effect
        → AtlasEvidenceLedger
        → AAEOS scorecard/certify/cockpit project read models

AAEOS does not call EliteExecutorKernel directly. The native Dev and Forge owners create ExecutionOrder at their proper boundary. Autônomos carries an order only when Task Fabric already supplied a valid binding; AAEOS never synthesizes one. In particular, AAEOS does not coordinate Brain → Seed → Task → worker step-by-step: it may initiate or observe one existing native scheduler/daemon cycle and correlate the returned refs. Removing AAEOS must leave every direct mode journey executable.

### 3.2 Owner map

| Concern | Existing owner to reuse | Forbidden duplication |
|---|---|---|
| Dev journey and attempts | SeniorEngineerLoopExecutor as journey owner; DevPlanRunFacade and AtlasDevExecutionService as typed attempt owners | Dev executor or retry loop inside AAEOS |
| Forge continuity/execution | ForgeObraRuntime; ForgeWorkPacketExecutionCycleService | flat/one-call Forge wrapper |
| Autônomos native cycle | AtlasSelfConstructionContinuousRuntimeCycleRunner; AtlasSelfConstructionRuntimeDaemonCycle; AtlasNativeWorkerClaimExecuteReportCycle | AAEOS-owned Brain/Seed/Task orchestrator |
| Autônomos origination/seed | Brain and Seed native owners reached by the existing replenisher/scheduler composition | CLI-to-CLI or invented --max protocol |
| task claim/worker | AtlasTaskServingService and native runtime worker | calling task next a worker |
| execution contract | ExecutionOrder v2; EngineeringOutcome | EngineeringMission envelope/receipt |
| technical adjudication | EngineeringQualityCourt and existing verification owners | AAEOS court |
| release authority | KernelEvidenceAuthority; MergeGovernor; AuthorizedMergeAction | SovereigntyPort package |
| code effect | AtlasTaskScopedCommitter; AtlasTaskMergeActuator | generic AAEOS actuator |
| canary settlement | CanarySettlementRequest and canonical settlement owners | second settlement state machine |
| evidence | AtlasEvidenceLedger | JSON counter, second ledger |
| sovereignty receipt | existing DecisionReceipt model/issuer plus promoted Ed25519 verification | string label as authority |
| reversibility | ReceiptReversibilityConsentGate | regex-based consent gate |
| metrics | EngineeringOutcome; Quality Foundry read models | AAEOS cost/token truth |
| comparative claims | RivalsClaimAuthority | capability_proof field accepted from caller |
| terminal audit | existing atlas:cli:cockpit | new cockpit/shell |

### 3.3 Deliberate deletions and fusions

- Keep AaeosModeLiveDispatcher and deepen the three existing handlers.
- Delete the four Control/Adapters files after parity characterization.
- Do not create AaeosModeExecutor or a second mode interface.
- Do not create AaeosActionEffectClassifier as an authority.
- Delete AaeosOperateScorecardProjector unconditionally (R71): the live-consumer census is zero at HEAD; its project() is a hardcoded self-score composite, a self-report forbidden by section 4.
- Delete AaeosTriHygieneScorecardProjector and AtlasTriHygieneScorecardCommand (R72): their lineCount()/is_file() dimensions score AEOS by file size and existence — a refactor-progress proxy forbidden by anti-Goodhart; honest structural signal already belongs to SovereignHonestyFloor + AtlasUniversalGatesEvaluator.
- Replace hardcoded score truth inside AaeosScorecardProjector; preserve only the presenter API if consumers need it.
- One AaeosRunApplication may be created because it removes duplicated run/cycle behavior.
- Brain/Seed application seams are extractions from CLI owners, not wrappers that call Artisan.

## 4. Effect authority protocol

This is a protocol across existing owners, not a new package or product.

### 4.1 Five steps

1. SUSPECT: text, hints and caller declarations may only increase suspected risk.
2. PRE-AUTHORIZE: the native tool/actuator gateway resolves an authorized_effect_ceiling from a server-owned action registry/tool contract, arguments, target, active authority receipt and current world state.
3. ACT: the concrete actuator performs at most the authorized effect.
4. POST-ATTEST: the actuator/settler derives observed_effect_class from actual tool invocation, write-set, landed SHA or external receipt.
5. SETTLE: evidence binds authorization, observed effect and outcome. A mismatch gives zero capability credit and triggers refuse-next, revoke, rollback or compensation as applicable.

Land-only observation is insufficient for an external irreversible effect. An effect cannot be blocked retroactively.

### 4.2 Trust rules

- ExecutionOrder is an untrusted proposal until the referenced decision event and authority binding are reloaded by the owner.
- Caller-declared action_kind, reversibility, source or minter are never authority.
- A declaration may raise the risk ceiling; it may never lower a server-resolved class.
- Trust derives from the emitting owner, canonical event type, causation chain, hashes and replay, not from a minter='observer' string.
- Authorization is replayed immediately before the effect, under the same relevant lock/lease where possible.
- Post-attestation cannot authorize what already happened.

### 4.3 Effect scope of this MT

| Effect class | P0–P4 handling |
|---|---|
| read/spec/origination | may proceed under valid mode authority; no mutation claim |
| workspace mutation | pre-authorize at native mutation boundary; observe actual write-set |
| canonical code release | Governor/canonical land/canary path; observe SHA, files and settlement |
| external effect | unsupported by generic AAEOS; fail closed at the specific gateway |
| credential mint/root authority | reserved; no generic implementation in this MT |
| policy/constitution mutation | reserved; existing constitutional owner or blocked |
| unknown mutative | no mutation; technical repair/refinement, not automatic human question |

P1b proves workspace mutation and canonical release. It does not claim a universal external-effect system.

### 4.4 Sovereignty reserve

Human authority is asynchronous and exceptional:

| Class | Reserved decision | What remains autonomous |
|---|---|---|
| H1 | constitution/root policy change | operation under the current policy |
| H2 | material unresolved value conflict | ordinary intent inference and technical trade-offs |
| H3 | exact truly irreversible external effect outside delegated authority | reversible code delivery under courts/canary/rollback |
| H4 | normative/canonical memory policy or promotion beyond delegated learning policy | operational learning under a reversible, auditable policy |
| H5 | expansion of autonomy/effect/risk ceiling | operation inside the current ceiling |
| H6 | root credentials or expansion of credential authority | mechanically derived scoped ephemeral capability |
| H7 | entry into or expansion of a sensitive domain | actions already covered by an explicit domain mandate |

Autônomos never waits synchronously for a human. In every mode, an H1–H7 exception blocks only the reserved effect, persists an existing Ledger decision/escalation event with the reserved class, action hash, required authority and continuation ref, and projects it into existing attention/cockpit queues. Unrelated admissible work continues. A later valid `DecisionIssued` resumes the continuation after fresh authority replay. This MT creates no sovereignty queue and no synchronous human wait.

## 5. Contracts

### 5.1 ExecutionOrder and mode facts

ExecutionOrder v2 remains exact. Reuse its canonical top-level fields. In particular:

- duration_regime: interactive, durable_task, obra, continuous;
- work_topology: single, candidate_set, workcell, DAG, portfolio;
- authority data belongs in authority_envelope and decision_receipt;
- tool, release, rollback and outcome constraints use their existing nested policies.

Do not add delegation_regime, work_origin, sovereignty_channel or assurance tier as unknown top-level order fields. Mode/runtime facts may be derived in read models. A sovereignty reference belongs inside the existing authority contract.

The legacy operator_contract.presence string is non-authoritative. It cannot be consumed as technical approval, judge identity or release verdict.

A standing mandate is not an opaque receipt label. Its canonically signed payload binds subject principal, issuer/key, workspace and target scope, capability and version, permitted effect/risk/domain ceilings, budgets, release policy, delegation bounds, validity window, revision/head and revocation lineage. The native owner reloads and verifies that full payload at origination, claim/renewal and immediately before each effect. Missing receipt, unsigned metadata, stale revision, scope mismatch or unprovable revocation head means zero mutative/provider/tool calls. The existing DecisionReceipt owner and promoted Ed25519 primitive remain the only authority; no AAEOS signer or authority store is allowed.

### 5.2 Minimal AAEOS cycle receipt

The cycle receipt is a projection and correlation envelope, not a second evidence truth:

    schema_version
    cycle_id
    mode
    status
    dry_run
    intent_hash
    native_run_ref
    native_order_hash            # nullable; not universal for Autônomos
    native_outcome_hash          # nullable until native execution
    declared_risk                # advisory only
    effect_authorization_ref     # nullable canonical reference
    effect_observation_ref       # nullable canonical reference
    effect_level
    proof_level
    runtime_write_performed
    evidence_event_id
    evidence_event_hash
    failure_reason               # required for every adverse terminal status
    next_actions

The binding is deliberately non-circular. A canonical `receipt_core_hash` covers the receipt core while excluding `evidence_event_id` and `evidence_event_hash`; the Ledger event stores that core hash. The final read projection may add the event id/hash, but those fields are not rehashed into the core. A journey manifest later binds the ordered event chain and terminal artifact. Duplicate-identical cycle/core writes are idempotent; divergent duplicates fail closed.

Removed from the target contract:

- human_in_engineering_loop;
- self-declared minter;
- caller-supplied capability proof;
- repeated cost/tokens/operator effort;
- fake worker or mutation claims from pack/intake/claim;
- quality tier by mode.

### 5.3 Native outcomes and economy

EngineeringOutcome remains the owner for cost, tokens, elapsed_ms and operator_effort. Unknown capture is unknown, never zero. AAEOS correlates and projects it.

Economy is a vector over the intent-to-treat cohort of all commissioned journeys, not a completion score and not an accepted-only sample:

- accepted_outcomes / commissioned_outcomes, including blocked, timeout, cancelled, failed, rollback, recovery and retry burn;
- total operator_active_seconds, tokens, cost and elapsed p50/p95 per commissioned journey, with per-accepted-outcome values only as a secondary view;
- revert, rework and late-failure rates;
- sovereign interventions by H-class;
- capture coverage and unknown/unattributed resource burn.

Segment by mode, risk, complexity and canonical duration regime. Quality and safety gates remain primary. Operator touches are not a quality or autonomy KPI.

### 5.4 Claim scopes

Do not persist capability_proof in a cycle receipt.

- Internal MT status is derived from this plan's hard gates and proof level.
- Sustained status is derived from a real time window and heartbeat/SLA evidence.
- Comparative status is derived only from a current Rivals claim and expires/revokes with it. The Rivals authority must itself load and recompute preregistration, arm symmetry, intent-to-treat population, StatisticalPolicy, readiness, replay, formula and expiry; caller-supplied `evidence_complete` or adjudication flags are not claim authority.

The current AtlasAaeosCertify presenter must not print GOD_SOTA from internal numbers. Its target vocabulary is scoped:

- aaeos_mt_not_ready;
- aaeos_mt_real_journey_verified, with operator_experience=oneshot only when its UX evidence is true;
- aaeos_sustained_verified;
- comparative_claim_current.

## 6. Proof taxonomy and scorecard

| Proof level | Minimum evidence | What it may claim |
|---|---|---|
| PLANNED | plan, owner, test and expected artifact | intended work only |
| SOURCE_WIRED | current call path inspection | source connection only |
| AUTOMATED_CHARACTERIZED | relevant green tests with fixture scope named | tested behavior only |
| DRY_RECEIPT | dry=true and zero runtime writes | dry contract only |
| LIVE_EFFECT | real actuator effect observed | local effect only |
| REAL_OPERATION | completed commissioned journey + LIVE_EFFECT + canonical event integrity + downstream artifact hash + fresh-process readback; any number of internal review/repair cycles is allowed | one real journey |
| SUSTAINED | preregistered half-open window; intent-to-treat denominator of every commissioned journey starting inside it; N≥5 per mode over at least 7 elapsed days; blocked, timeout, cancelled, failed, rollback and recovery retained; fresh heartbeat and SLA | sustained internal operation |
| COMPARATIVE | current preregistered Rivals claim with symmetric budgets and invalidators | only its scoped comparative claim |

P4 requires REAL_OPERATION for a completed journey. It does not constrain internal attempt count or elapsed time. It does not require SUSTAINED or COMPARATIVE and may not claim either.

Every measured dimension carries:

- status: measured, unknown, not_applicable or blocked;
- numerator and denominator;
- sample_count;
- UTC half-open window;
- canonical source query and evidence refs;
- computation version;
- failure_reason when blocked.

No default numeric value substitutes for unknown. No composite certifies DONE.

## 7. Residual ledger

### 7.1 Existing residuals R1–R37

| ID | Current truth | Disposition |
|---|---|---|
| R1 | Spine N9/N11 partial | P2: real refs/readback |
| R2 | daily Spine coverage partial | P2: applicability census |
| R3 | Dev/Forge full rewrite would be unsafe | P1/P2 strangler only |
| R4 | brain:next source call exists | CLOSED_SOURCE; never call this live proof |
| R5 | no real Autônomos operation receipt | P4 hard gate |
| R6 | DualCore record best-effort | P0/P1 visible status |
| R7 | skipped evidence can fail open | P0/P2 fail explicit |
| R8 | world snapshot can default silently | P0 measured provenance |
| R9 | legacy aliases deferred | P3 evidence-led retirement |
| R10 | dual Aaeos/AgenticEngineeringOs mental maps; AEOS 43k-LOC lattice unwired from the live Kernel land path yet coupled via a LOC-proxy scorecard | P1 code partition + guard test (R73); P3 canonical docs |
| R11 | generated docs-as-PHP reduction is intentional | ACCEPTED; no LOC restoration |
| R12 | Quarantine absent | CLOSED/HOLD; zero recreation/import |
| R13 | HTTP/desktop is not daily operate | HORIZON; no P4 gate |
| R14 | provider opt-in UX can look inert | P0 honest status |
| R15 | cockpit does not close engineering loop | P1/P3 read model only |
| R16 | score mixes hints/static/scans | P0 replace truth source |
| R17 | runtime_write_performed hardcoded true | P0 derive actual writes |
| R18 | dispatched_live conflates prepared and mutated | P0 typed effect level |
| R19 | score lacks provenance/window/denominator | P0 contract |
| R20 | counter store would duplicate Ledger | PROHIBITED |
| R21 | run/cycle diverge | P0 AaeosRunApplication |
| R22 | RunCommand lacks dedicated complete test | P0 |
| R23 | source/mock/dry/real claims mixed | all phases use section 6 |
| R24 | numeric target/waiver can launder DONE | hard-gate predicate only |
| R25 | blocked ops could replace live proof | P4 remains incomplete |
| R26 | rg=0 is insufficient alias proof | P3 classmap/runtime proof |
| R27 | ObserveRegistry was preselected without evidence | DELETE proposal unless measured need |
| R28 | Spine criteria lacked owner/call-site | P2 applicability matrix |
| R29 | operator OneShot, technical attempt count and 24/7 proof were fused | OneShot is UX; internal cycles are unbounded by this concept; sustained is separate proof |
| R30 | dirty-main verification lacked baseline attribution | section 9 protocol |
| R31 | archive deletion appeared as future work | CLOSED/HOLD |
| R32 | CODEMAP points to legacy source connector alias | P3 |
| R33 | brain scope flag invalid | P1a hard gate |
| R34 | exit-only Brain success is false-positive | P1a payload semantics |
| R35 | seed --max is invented | P1a remove |
| R36 | live needs memory/master/scope preflight | P4 blocked_ops if absent |
| R37 | run/cycle flags/defaults/exit differ | P0 hard gate |

### 7.2 Constitutional/runtime residuals R38–R51

| ID | Current truth | Disposition |
|---|---|---|
| R38 | human field is authoritative in code | P0 remove reads/writes; never hardcode false |
| R39 | review presence and quality were coupled | P0/P3 semantic cleanup |
| R40 | technical invalidity maps to sovereignty | P0 taxonomy; no human escalation |
| R41 | v6 invented conflicting mode axes | v7 deletes; derive from existing contracts |
| R42 | 17-phase/human-review runbook presented as operate | P3 demote/alignment |
| R43 | AAEOS uses CLI-to-CLI for Brain/Seed | P1a application extraction |
| R44 | Spine can self-green from empty declarations | P2 fail closed on applicable missing refs |
| R45 | attention queues risk becoming authority | P2: existing Ledger decision events + read-model projection only |
| R46 | principal/capability/spec-witness independence not proved | P2 owner hardening, same floor in all modes |
| R47 | durable sustained government | HORIZON after P4: preregistered ITT window; no Mission Runtime |
| R48 | topology ablation | HORIZON: Quality Foundry/Rivals sister claim, never MT DONE |
| R49 | attention/economy | P3 read model derived from all commissioned EngineeringOutcomes |
| R50 | external superiority | HORIZON: Rivals-only recomputed current claim |
| R51 | regex/hints/self-report used for irreversibility | P0 suspicion; P1b native authority |

### 7.3 v7 residuals R52–R63

| ID | Gap discovered by cycle 1 | Hard done condition |
|---|---|---|
| R52 | v6 land-only CRES is too late | pre-authorize → act → post-attest → settle proven for code effects |
| R53 | ExecutionOrder proposal may carry caller-authored authority; provider can run before authoritative replay | active decision/authority event reloaded before provider/tool/sandbox |
| R54 | Ledger integrity/query/dedupe incomplete | recompute canonical event/envelope chain; bounded event-type/window query; divergent duplicate veto |
| R55 | EngineeringOutcome adverse causes/authority incomplete | all statuses authority-bound; adverse status requires precise failure_reason |
| R56 | Autônomos claim/already_done can be sold as worker/origination | distinct served, claimed, executed, landed, resolved refs; already_done gets no new-work credit |
| R57 | Forge terminal concurrency/exactly-once is not proved | unique cycle position + lock/CAS + single terminal winner + precise cause |
| R58 | v6 capability_proof can become second truth | status derived from hard gates/Rivals only; caller value ignored |
| R59 | standing mandate is a label without stale-resume proof | mutative unattended path binds active receipt id/hash/revision or remains blocked |
| R60 | planned ModeExecutor duplicates existing dispatcher port | no new interface; adapters removed after parity |
| R61 | plan_seal/session enums are labels conflicting with owners | use commissioning/authority hashes and canonical duration enum |
| R62 | P4 criteria accepted a first effect/exit instead of a completed operator journey | P4 requires end-to-end REAL_OPERATION artifact + fresh-process replay; retries/review/repair remain visible |
| R63 | reversibility gate is orphan and DecisionReceipt signing is not global-strength | integrate existing gate only for reserved effects; reuse/promote Ed25519 verification at Decide owner |

R43, R44, R46, R51–R63 cannot be waived into DONE. A follow-up debt may exist, but the MT remains PARTIAL.

### 7.4 v8 cycle-2 survivors R64–R74

Root judged the cycle-2 adversarial panel (15 lenses) against v7 and disk. Only reuse/delete/fuse survived; every duplicate of an existing owner was rejected — capability_proof is already killed by §5.4/R58, the 5-axis envelope is already collapsed by §5.1, the cockpit proof/economy render is already committed in P3, and a 6-state Aaeos admission enum duplicates AtlasMergeGovernorAdmissionPolicy. Each survivor names the existing owner it reuses or deletes; none creates a new organ.

| ID | Gap surpassing v7 | Existing owner reused / deleted | Hard done condition |
|---|---|---|---|
| R64 | R40 fix under-specified: an implementer could de-conflate technical vs sovereign by growing the verdict enum | reuse AtlasMergeGovernorAdmissionPolicy::DECISION_REPAIR ('repair_required') | AaeosAdmissionPolicy invalid_mode/unknown-mode returns repair_required, never HALT_SOVEREIGN; AaeosAdmissionVerdict stays 3 consts; golden: invalid_mode is repair-classed and only irreversible/business_ambiguous reach HALT_SOVEREIGN |
| R65 | proof_level is a settable field checked only at DONE, so illegal (effect,proof) cells are constructible pre-P4 | reuse AaeosCycleRuntime receipt assembler + section 6 ladder | proof_level is a pure derivation of observed effect class + canonical event integrity + fresh-readback presence; caller-supplied proof_level=LIVE_EFFECT with effect_level=blocked derives ≤ AUTOMATED_CHARACTERIZED; proof_level not independently settable |
| R66 | Spine N11 self-greens from empty/declared refs and is a second disjoint proof | fuse into AaeosEngineeringSpine::assertShared + AaeosSpineGate + AtlasEvidenceLedger | an applicable critical N11 site is satisfied only by an evidence ref resolving to a settlement-emitted effect receipt (observer identity + changed-files hash + landed SHA) via ledger readback; caller-declared/empty refs fail closed; closes R44 by construction |
| R67 | measure-first is enforced only by a one-time human census; a future receipt field can ship with zero readers | reuse tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest harness | one standing red test reads the field-set from the single receipt builder and fails when a field ships with no non-test reader (nullable-correlation fields exempt by name); no new registry/service |
| R68 | independence proves principal/capability but is blind to model-weights; under verboo-only, author and judge always share weights and the same in-artifact injection | reuse provider-lock modelFamily / adapter MODEL_FAMILY + SelfConstruction/VerificationCourt | the independence receipt carries model_family_id from trusted issuance (not caller); a same-family author+judge cannot reach landed/certify without a mechanical VerificationCourt verdict; the LLM judge is recorded advisory only |
| R69 | R49 economy is hand-waved and an accepted-only denominator hides failed/cancelled/recovery burn | reuse AtlasMaestroCostAggregator (aggregateByCycle) + EngineeringOutcome.operatorEffort/tokens + AaeosScorecardProjector | scorecard --view=economy uses every commissioned journey as the primary ITT cohort, includes blocked/timeout/cancel/failure/retry/rollback/recovery burn and capture coverage; accepted-landing efficiency is secondary and counts only observer-minted releases; no new receipt field |
| R70 | §P1b land-observer names the two chokepoint files but not the exact primitive, and only one chokepoint is observer-minted (autonomos committer records declared evidence) | reuse AtlasTaskMergeActuator::changedFiles (diff-tree at landed SHA) + CanarySettlementRequest.observerIdentity | post-commit the observed write-set is derived by the same diff-tree primitive as the merge actuator; the autonomos committer's landed event carries observer_identity minted by the settler, not the caller; present-but-false read_only and declared-not-edited goldens pass on both chokepoints |
| R71 | AaeosOperateScorecardProjector is a verified-zero-consumer hardcoded self-grader gated behind an already-satisfied census | delete app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php | class removed; scorecard/certify truth sources only measured/observed dimensions |
| R72 | AaeosTriHygieneScorecardProjector scores AEOS by file size/existence — a refactor-progress proxy and the sole Aaeos→AEOS coupling | delete AaeosTriHygieneScorecardProjector + AtlasTriHygieneScorecardCommand | no AAEOS quality number derives from lineCount()/is_file(); honest structural signal reuses SovereignHonestyFloor + AtlasUniversalGatesEvaluator |
| R73 | AEOS 43k-LOC lattice is unwired from the live Kernel land path yet coupled to Aaeos only via the LOC-proxy scorecard; R10 buried it under P3 doc relabels | reuse AaeosHygieneLegacyAliases seam + SovereignHonestyFloor as sole authority; keep Scoring/* live cores | R10 re-scoped to a P1 code partition: a guard test proves the dormant-ritual AEOS group is unreferenced from EliteExecutorKernel::execute and Aaeos/Control/Dispatch; live Scoring cores (recall relevance, pareto filter, spec-completeness) stay; RunbookOrchestrator 17-phase and DepartmentContractRuntime demoted from operate authority |
| R74 | §5.1 permits mode→(route/sovereignty/delegation) derivation in read models with no single owner, and the router silently defaults an invalid mode to Dev | reuse AaeosModeToDualCoreRoute as the single named derivation owner | §5.1 names one derivation owner; AaeosModeToDualCoreRoute fails closed on invalid/unknown mode instead of defaulting to ROUTE_DEV |

R64–R74 reuse or delete existing owners only; none creates a new organ and none may be waived into DONE.

### 7.5 v9 cycle-3 survivors R75–R82

The cycle-3 panel attacked v8 with native Dev/Forge/Autônomos semantics, journey forensics, cryptographic authority, plan executability, sustained statistics and comparative-claim authority. Root rejected a new `AtlasAutonomosCycleApplication`, a second journey store, caller counters and accepted-only economics. The surviving changes deepen existing owners.

| ID | Gap surpassing v8 | Existing owner reused / deletion | Hard done condition |
|---|---|---|---|
| R75 | refs from different valid runs can be spliced into a fake REAL_OPERATION | reuse native root refs + AtlasEvidenceLedger correlation/causation | journey has one root event/ref and a canonical ordered manifest of event/native/artifact hashes; terminal status is exactly `real_operation_completed`, `blocked_before_effect`, `failed_after_effect`, `authority_exhausted` or `cancelled`; only the first qualifies P4; splice/omit/reorder goldens fail |
| R76 | OneShot counters can be caller-zeroed or hide technical approvals as clarifications | reuse native Dev operator-action table, Forge commissioning/control events and Decision/Ledger events | 100% capture coverage; typed request/action refs and hashes; derived commissioning episodes, clarifications, technical approvals/reviews, cancel/revoke and reserved decisions; missing capture = unknown; Dev intent clarification is allowed only in the same episode, Forge only pre-seal, Autônomos zero per-task requests/actions/clarifications/waits |
| R77 | standing mandate is neither fully signed nor provably current; absent receipt currently can pass the runtime guard | reuse/promote HumanDecisionReceiptSigner inside Kernel/Decision owner; delete textual signer authority | full payload in §5.1 is Ed25519 verified, active and non-revoked at origination/claim/renew/pre-effect; absence/mismatch/stale head means zero provider/tool/mutation calls; cross-order and TOCTOU revocation goldens pass |
| R78 | AAEOS arrays can mint or discard native identity before Dev/Forge/Autônomos owners | reuse DevIntent + ConfirmedDevRun, ForgeCommissioning, existing native scheduler/daemon/worker entry points | native ingress constructs and validates typed input and authority lineage; Forge rejects unknown/malformed packet fields and synthetic decision fallback; AAEOS receives returned refs only; Autônomos propagates one authority ref/hash/revision Brain/Seed→packet→lease without remint |
| R79 | rejected work has no proven owner-complete return path; worker `queue_repair_signal` is incompatible with TaskServing outcomes | reuse Dev RepairOrchestrator, Forge state/cycle owners, TaskServing give-back/repair semantics and native worker | each mode proves reject→repair/replan→re-review→terminal under the same root intent/authority; no AAEOS retry loop; Forge preserves failed-cycle→child packet/attempt→blocker resolution→next cycle; Autônomos normalizes repair into a TaskServing-owned transition rather than invalid_report |
| R80 | receipt↔Ledger binding is circular and fresh-readback can validate only a projected hash | reuse AaeosCycleRuntime receipt core + AtlasEvidenceLedger | receipt_core_hash excludes event id/hash; event stores core hash; final projection adds the event ref; fresh process recomputes core, chain, causation, manifest and terminal artifact bytes; tamper/splice/missing evidence fails precisely |
| R81 | H1–H7 says “persist a work item” but has no mechanical event/continuation protocol | reuse AtlasEvidenceLedger `DecisionDrafted`/`EscalationRequested`/`DecisionIssued` and existing attention/cockpit projections | reserved effect alone blocks; event binds H-class/action/needed-authority/continuation; valid issued decision resumes after replay; unrelated work continues; no new queue or synchronous human wait |
| R82 | Spec Floor treats Dev/Forge operator presence as technical independence | reuse SpecSourceIndependence, witness resolvers, SovereignSpecFloor and VerificationCourt | `SelfComposedUnwitnessed` cannot pass in any mode merely because an operator exists; independent principal/capability or mechanical Court evidence is required; same goldens apply to Dev, Forge and Autônomos |

R75–R82 are hard, reuse-only blockers. None may be waived into DONE.

## 8. Single implementation plan

Every phase begins with branch/status, current hash, dirty ownership, residual revalidation and RED characterization. Every commit is scoped. No phase may absorb the next phase. The production and test path lists below are authorization manifests, not examples: `*`, “if needed” and unnamed native owners are forbidden. If a RED proves that an unlisted path must change, execution stops and this MASTER is amended before that path is edited.

### P0 — truth before capability

Objective: remove false success, unify the daily port and produce an honest read-only projection. P0 does not fix Brain execution or effect authority.

Production paths:

- app/Console/Commands/AtlasAaeosRunCommand.php
- app/Console/Commands/AtlasAaeosCycleCommand.php
- app/Console/Commands/AtlasAaeosScorecardCommand.php
- app/Console/Commands/AtlasAaeosCertifyCommand.php
- app/Services/Ai/Aaeos/Control/AaeosRunApplication.php (new only as a deep unifying use case)
- app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
- app/Services/Ai/Aaeos/Control/AaeosIntentCompiler.php
- app/Services/Ai/Aaeos/Control/AaeosAdmissionPolicy.php
- app/Services/Ai/Aaeos/Control/AaeosAdmissionVerdict.php
- app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
- app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php (delete; R71 census already zero)
- app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php
- app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php (bounded read API only; chain hardening stays P2)

Test paths:

- tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php (existing; extend)
- tests/Feature/Ai/Aaeos/AtlasAaeosRunCommandTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosRunCycleParityTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosLedgerMeasurementReaderTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosAdmissionTaxonomyTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosHumanLoopFieldRetirementTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosIrreversibilitySuspicionCharacterizationTest.php (NEW)

P0 exit:

- run/cycle share one application use case and parity matrix;
- dry run performs zero runtime writes;
- runtime_write_performed is derived;
- prepared/claimed/mutated are distinct;
- zero authoritative human_in_engineering_loop read/write;
- invalid technical input returns technical repair/block, never sovereign halt;
- regex/hints emit suspicion only;
- zero-sample measures are unknown/null;
- certify cannot emit GOD_SOTA;
- P0 receipt and current SCOREBOARD are durable.

Commit boundary: one truth/port commit; one measured-reader/presenter commit if the diff would mix owners.

### P1a — native dispatch, no new executor

Objective: deepen the existing dispatcher port, remove the duplicate adapter stack and fix native transport semantics.

Production paths:

- app/Services/Ai/Aaeos/Control/Dispatch/AaeosModeLiveDispatcher.php
- app/Services/Ai/Aaeos/Control/Dispatch/AaeosLiveDispatchGateway.php
- app/Services/Ai/Aaeos/Control/Dispatch/DevLiveDispatcher.php
- app/Services/Ai/Aaeos/Control/Dispatch/ForgeLiveDispatcher.php
- app/Services/Ai/Aaeos/Control/Dispatch/AutonomosLiveDispatcher.php
- app/Services/Ai/Aaeos/Control/Adapters/AaeosExecutorModeAdapter.php (delete after parity)
- app/Services/Ai/Aaeos/Control/Adapters/DevModeAdapter.php (delete after parity)
- app/Services/Ai/Aaeos/Control/Adapters/ForgeModeAdapter.php (delete after parity)
- app/Services/Ai/Aaeos/Control/Adapters/AutonomosModeAdapter.php (delete after parity)
- app/Services/Ai/Programming/AtlasDev/Execution/DevIntent.php
- app/Services/Ai/Programming/AtlasDev/Execution/ConfirmedDevRun.php
- app/Services/Ai/Programming/AtlasDev/Execution/DevPlanRunFacade.php
- app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopExecutor.php
- app/Services/Ai/Programming/Forge/Execution/ForgeCommissioning.php
- app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php
- app/Services/Ai/SelfConstruction/ContinuousRuntime/AtlasSelfConstructionContinuousRuntimeCycleRunner.php
- app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycle.php
- app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php

Required behavior:

- Dev accepts native DevIntent/ConfirmedDevRun lineage, delegates the journey to the existing senior-loop owner and attempts to DevPlanRunFacade; it never injects Kernel or owns retry directly.
- Forge constructs strict ForgeCommissioning in its native owner, rejects unknown/malformed packet data, calls ForgeObraRuntime and returns the Obra ref; P1a does not enable provider execution before P1b authority replay is green.
- Autônomos invokes one existing native scheduler/daemon-cycle entry, not Artisan and not a step-by-step AAEOS orchestration; it returns native cycle/task refs.
- brain scope is positional with a documented default;
- seed uses only real signature fields;
- task next is task_claimed, never worker_executed;
- already_done is deduplicated/refused, never new origination;
- handlers return native refs and precise failure causes.

Test paths:

- tests/Unit/Ai/Aaeos/Control/AaeosOperateDispatchTest.php (existing; extend)
- tests/Unit/Ai/Aaeos/Control/AaeosNativeDevDispatchContractTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosNativeForgeDispatchContractTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosNativeAutonomosDispatchContractTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosDirectModeAblationTest.php (NEW)
- tests/Unit/Ai/Programming/Forge/Execution/ForgeObraRuntimeContractTest.php (existing; extend)
- tests/Unit/Ai/SelfConstruction/ContinuousRuntime/AtlasSelfConstructionContinuousRuntimeCycleRunnerTest.php (existing; extend)
- tests/Unit/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycleTest.php (existing; extend)
- tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycleTest.php (existing; extend)

P1a exit: existing interface retained; three handlers deepened; four Adapters deleted; R33–R35/R43/R60/R78 dispatch portions green; direct modes pass the AAEOS-ablation test; zero authorization-semantic change.

### P1b — effect authority for code

Objective: prove the section 4 protocol for workspace mutation and canonical code release without creating an AAEOS authority owner.

Slice P1b.1 — authoritative pre-effect replay:

- app/Services/Ai/EngineeringKernel/ExecutionOrder.php
- app/Services/Ai/EngineeringKernel/EngineeringModeExecutionOrderFactory.php
- app/Services/Ai/EngineeringKernel/EliteExecutorKernel.php
- app/Services/Ai/EngineeringKernel/KernelEvidenceAuthority.php
- app/Services/Ai/Programming/AtlasDev/Execution/EliteExecutorKernelDevAdapter.php
- app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
- app/Services/Ai/Kernel/Decision/DecisionReceipt.php
- app/Services/Ai/Kernel/Decision/DecisionReceiptHash.php
- app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php
- app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php

Exit: a caller-authored order cannot cause provider, sandbox, tool or mutation until the referenced authoritative decision is reloaded and bound. Dev and Forge must supply the real decision event/ref from their typed commissioning lineage; `EngineeringModeExecutionOrderFactory` may not synthesize `*-decision-*` fallbacks.

Slice P1b.2 — native code actuator:

- app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php
- app/Services/Ai/SelfConstruction/Governance/AtlasTaskMergeActuator.php
- app/Services/Ai/EngineeringKernel/AuthorizedMergeAction.php
- app/Services/Ai/EngineeringKernel/CanarySettlementRequest.php
- app/Services/Ai/EngineeringKernel/KernelEvidenceAuthority.php

Exit:

- declared read_only cannot downgrade an observed write;
- changed paths/SHA/tool action determine observed effect;
- authorization is replayed before commit under the relevant lock;
- landed and settlement events bind order, decision, lease/fencing, candidate, write-set and outcome;
- mismatch receives zero claim credit and triggers the existing rollback/settlement path;
- external effects remain unsupported and blocked.

Slice P1b.3 — AAEOS projection:

- AaeosCycleRuntime.php
- AaeosAdmissionPolicy.php
- AaeosScorecardProjector.php

Exit: AAEOS projects canonical authorization/observation refs; it cannot self-mint observed authority.

Required goldens:

- present-but-false declared read_only with real mutation;
- keyword-free code release;
- tampered authority event;
- expired/revoked authority;
- stale lease/fencing;
- observed effect exceeds ceiling;
- external effect presented to generic AAEOS;
- no effect after pre-effect refusal.

Test paths:

- tests/Unit/Ai/EngineeringKernel/TypedEngineeringContractTest.php (existing; extend)
- tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php (existing; extend)
- tests/Feature/Ai/EngineeringKernel/CanonicalCommitActuationTest.php (existing; extend)
- tests/Feature/Ai/AtlasTaskScopedCommitterTest.php (existing; extend)
- tests/Feature/Ai/Aaeos/AaeosEffectAuthorityProtocolTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosAuthorityReplayToctouTest.php (NEW)

No AaeosActionEffectClassifier, SovereigntyPort, generic action registry or second ledger may be created.

### P2 — shared authority, evidence and durability

P2a — evidence/outcome integrity:

- Production paths:
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Services/Ai/Kernel/Evidence/LedgerEventType.php
  - app/Services/Ai/EngineeringKernel/EngineeringOutcome.php
  - app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
- harden AtlasEvidenceLedger canonical event/envelope/chain verification;
- add event-type + half-open UTC window query and cycle-id idempotency/divergence semantics;
- implement the non-circular receipt-core/event binding and ordered journey manifest from R75/R80;
- bind every EngineeringOutcome status to authority;
- require failure_reason for adverse terminal outcomes;
- preserve precise causes through Forge and AAEOS.
- Test paths:
  - tests/Unit/Ai/Kernel/EvidenceLedgerTest.php (existing; extend)
  - tests/Unit/Ai/EngineeringKernel/TypedEngineeringContractTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosJourneyManifestIntegrityTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosFreshProcessJourneyReplayTest.php (NEW)
  - tests/Unit/Ai/EngineeringKernel/EngineeringOutcomeAdverseReasonTest.php (NEW)

P2b — independence and sovereignty receipts:

- Production paths:
  - app/Services/Ai/Kernel/Decision/DecisionReceipt.php
  - app/Services/Ai/Kernel/Decision/DecisionReceiptHash.php
  - app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php
  - app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php
  - app/Services/Ai/Kernel/Decision/Reversibility/ReceiptReversibilityConsentGate.php
  - app/Services/AtlasCode/HumanDecisionReceiptSigner.php
  - app/Services/Ai/EngineeringKernel/Spec/SpecSourceIndependence.php
  - app/Services/Ai/EngineeringKernel/Spec/SelfComposedWitnessResolver.php
  - app/Services/Ai/EngineeringKernel/Spec/AdvisorWitnessResolver.php
  - app/Services/Ai/EngineeringKernel/Spec/SovereignSpecFloor.php
- use principal_id, capability_id/version, issuer_key_id and producer path from trusted issuance;
- prevent the same capability from minting author + judge + governor;
- reuse/promote the existing Ed25519 signer/verifier into the Decision owner;
- sign every authority-bearing payload field listed in §5.1 and fail closed when receipt/revocation head is absent, stale or mismatched;
- wire the existing ReceiptReversibilityConsentGate only for true H3/unrecoverable effects;
- derive scoped ephemeral capabilities mechanically inside an active authority ceiling.
- Test paths:
  - tests/Unit/Ai/Kernel/DecisionReceiptIssuerTest.php (existing; extend)
  - tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php (existing; extend)
  - tests/Unit/Ai/Kernel/Decision/Reversibility/ReceiptReversibilityConsentGateTest.php (existing; extend)
  - tests/Unit/AtlasCode/HumanDecisionReceiptSignerTest.php (existing; extend)
  - tests/Unit/Ai/EngineeringKernel/Spec/SpecAdversaryContractTest.php (existing; extend)
  - tests/Unit/Ai/EngineeringKernel/Spec/SovereignSpecFloorTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosStandingMandateAuthorityTest.php (NEW)

P2c — unattended durability:

- Production paths:
  - app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketBuilder.php
  - app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneClaimLeaseRepository.php
  - app/Services/Ai/SelfConstruction/AtlasTaskServingService.php
  - app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php
  - app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerOutcomeMapper.php
  - app/Services/Ai/Programming/AtlasDev/Execution/DevPlanRunFacade.php
  - app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopExecutor.php
  - app/Services/Ai/Programming/AtlasDev/Repair/RepairOrchestrator.php
  - app/Services/Ai/Programming/AtlasDev/Repair/DevRepairLoopService.php
  - app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php
  - app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php
  - app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
- add a RED stale-resume/revocation test first;
- bind the exact authority ref/hash/revision from native origination through packet and lease without remint;
- revalidate at origination, claim, renewal and pre-effect boundary;
- normalize worker retry/repair signals into a TaskServing-owned transition; unknown outcome remains invalid and never silently gives back;
- make Dev's existing RepairOrchestrator the retry owner reached through a production DevPlanRunFacade adapter; SeniorEngineerLoopExecutor owns the journey, while PipelineRunExecutor must not remain a second independent retry loop;
- harden Forge cycle position and terminal transitions with unique constraint + transaction/lock/CAS;
- prove one terminal winner and divergent replay conflict.
- Test paths:
  - tests/Unit/Ai/SelfConstruction/AtlasTaskServingServiceTest.php (existing; extend)
  - tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycleTest.php (existing; extend)
  - tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerOutcomeMapperTest.php (existing; extend)
  - tests/Unit/Ai/Programming/AtlasDev/Repair/RepairOrchestratorTest.php (existing; extend)
  - tests/Feature/Ai/Programming/AtlasDev/Repair/DevRepairLoopServiceTest.php (existing; extend)
  - tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php (existing; extend)
  - tests/Feature/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleServiceTest.php (existing; extend)
  - tests/Feature/Ai/Programming/Forge/ForgeLongHorizonStateServiceTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosAuthorityLineageResumeTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosNativeRepairContinuationTest.php (NEW)

P2d — Spine:

- Production paths:
  - app/Services/Ai/Aaeos/Spine/AaeosEngineeringSpine.php
  - app/Services/Ai/Aaeos/Spine/AaeosSpineGate.php
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
- assert applicable shared gates from real order/outcome/event refs;
- empty declarations cannot pass an applicable gate;
- publish applicability, owner, ref/hash, result and readback;
- no class-name-only proof.
- Test paths:
  - tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosSpineSettlementEvidenceTest.php (NEW)

P2e — asynchronous H1–H7 continuation:

- Production paths:
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Services/Ai/Kernel/Evidence/LedgerEventType.php
  - app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php
  - app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php
- persist the reserved-effect event and continuation ref; project only through existing attention/cockpit readers;
- replay a later valid DecisionIssued before continuation; unrelated work remains runnable.
- Test path: tests/Feature/Ai/Aaeos/AaeosSovereignContinuationTest.php (NEW).

Each P2 sub-slice is a separate scoped commit and receipt.

### P3 — deletion and canonical alignment

Production/document paths:

- app/Services/Ai/Aaeos/Control/AaeosTriHygieneScorecardProjector.php (delete)
- app/Console/Commands/AtlasTriHygieneScorecardCommand.php (delete)
- app/Services/Ai/Compat/AaeosHygieneLegacyAliases.php
- app/Services/Ai/Aaeos/Control/AaeosModeToDualCoreRoute.php
- app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
- app/Services/Ai/SelfConstruction/Maestro/Cost/AtlasMaestroCostAggregator.php
- app/Services/Ai/Rivals/Core/RivalsClaimAuthority.php
- app/Services/Ai/CODEMAP.md
- docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
- docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
- docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md

Required behavior:

- delete the two LOC/file-existence hygiene graders; retain only live scoring cores proven by the P1 architecture guard;
- keep compatibility aliases unless classmap/runtime parity proves a named alias dead; no bulk alias deletion is authorized;
- 17 phases and `human_review` are historical/governance material, not the daily operate path;
- replace invented plan_seal/session labels with native commissioning/authority hashes and canonical enums;
- terminal-first: the existing `atlas:cli:cockpit` consumes read-only proof/economy refs through its current projection owner; no new shell or authority;
- Rivals recomputes comparative authority inputs; the AAEOS projector only presents the result;
- Quarantine path/import count remains zero.

Test paths:

- tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php (existing; extend with field-reader census)
- tests/Feature/Ai/Aaeos/AaeosAeosPartitionGuardTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosEconomyIntentToTreatProjectionTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosRivalsClaimRecomputationTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosCanonicalDocsAndAliasTest.php (NEW)

P3 does not broaden runtime capability.

### P4 — real-journey gauntlet, OneShot UX proof and freeze

Preconditions: P0–P3 receipts green; all automated/source portions of R33/R34/R51–R82 green; ops preflight explicit. R5/R25/R36/R62/R75/R76 require the live P4 journeys themselves and are therefore P4 exit conditions, not circular preconditions.

P4 gives Atlas the time required to reach a governed result. It does not impose a one-pass or speed target. Every rejection loops internally to the correct planning, implementation, verification or repair stage until accepted or terminated with an explicit mandate/budget/no-progress cause. A safely blocked/failed/cancelled journey is honest evidence, but it does not qualify that mode as REAL_OPERATION and keeps P4 incomplete.

Dev proof:

- one native DevIntent/ConfirmedDevRun root and authority lineage bound to every plan, replan, run and repair attempt;
- SeniorEngineerLoopExecutor journey → DevPlanRunFacade attempts → AtlasDevExecutionService → ExecutionOrder → Kernel → EngineeringOutcome;
- at least one rejection → agentic repair/replan → re-review → accepted artifact, with distinct attempt refs and no technical operator approval;
- accepted artifact and canonical journey fresh-read.

Forge proof:

- strict ForgeCommissioning → commissioned Obra → full DAG/milestones → `ForgeLongHorizonStateService::completeObra`;
- at least one rejected cycle → repair child packet/attempt → re-review → blocker resolution → continued Obra;
- reservation/lease/fencing and order/outcome refs;
- one terminal winner;
- landed/canary for the completed Obra;
- a blocked packet/Obra is recorded precisely but cannot qualify Forge P4.

Autônomos proof:

- direct native journey is proven first with `aaeos_initiated=false`; disabling AAEOS cannot break it;
- Brain status served with a new packet/journal/done-set and standing-authority binding;
- seed propagation;
- task claim;
- real worker report;
- at least one rejection/repair-or-give-back/new-claim/reverification cycle;
- scoped canonical land;
- resolved state and Ledger readback;
- Kernel ref only if the packet genuinely carried an order.
- zero per-task operator requests, actions, clarifications or synchronous waits from seed to terminal.

Every mode requires a completed REAL_OPERATION journey with:

- journey root event/ref/hash and a correlation id derived from native intent/commissioning/mandate;
- `journey_terminal_status=real_operation_completed`; blocked_before_effect, failed_after_effect, authority_exhausted and cancelled do not qualify;
- operator request/action refs with canonical types and hashes, capture_coverage=1.0 and derived OneShot counters; self-reported zeros are ignored;
- ordered attempt refs `{kind,native_id,parent_ref,event_id,event_hash,outcome,failure_reason}`; engineering/review/repair counters are folds of this sequence and never failure by themselves;
- receipt_core_hash and ordered journey_manifest_hash;
- authoritative decision/authorization ref and hash;
- observed effect event ref and hash;
- downstream artifact ref, SHA-256 and bytes where applicable;
- fresh-process read from a new app/container and DB connection with no prior object/cache; recompute receipt core, event chain/causation/dedupe, journey manifest and terminal artifact bytes;
- exact failure_reason for any adverse result.

P4 execution/test paths:

- tests/Feature/Ai/Aaeos/AaeosDevRealJourneyTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosForgeRealObraJourneyTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosAutonomosRealJourneyTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosOneShotOperatorEvidenceTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosFreshProcessJourneyReplayTest.php (created in P2; run live profile)
- docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P4-DEV.json (generated)
- docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P4-FORGE.json (generated)
- docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P4-AUTONOMOS.json (generated)
- docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P4-FREEZE.json (generated)

P4 exit status is `aaeos_mt_real_journey_verified` only when all three mode journeys qualify. The separate derived UX label `operator_experience=oneshot` requires one commissioning episode, complete event capture and zero routine technical request/action; any allowed intent clarification is typed and enumerated, while Autônomos remains strict zero-touch. Sustained and comparative remain `not_claimed` unless their separate evidence actually exists.

Freeze:

- version target contracts;
- record canonical hashes;
- archive predecessor receipts as historical;
- retain zero Quarantine/ACDE imports;
- set SCOREBOARD from evidence, not prose.

## 9. Verification and evidence discipline

### 9.1 Dirty main

Before each slice:

1. record branch, HEAD and status;
2. list allowed paths and current staged paths;
3. attribute baseline failures;
4. never reset, stash or sweep unrelated work;
5. stage explicit pathspecs only;
6. verify cached diff contains only the slice;
7. commit on local main.

Baseline failure does not excuse a new lane failure. A green focused test does not prove adjacent or full health.

### 9.2 Phase receipt

Each PHASE receipt contains:

    phase
    status
    base_sha
    result_sha
    allowed_paths
    touched_paths
    deleted_paths
    tests_and_exit_codes
    baseline_failures
    new_failures
    residuals_closed
    residuals_open
    proof_level
    canonical_event_refs
    artifact_hashes
    runtime_write_inventory
    failure_reason
    next_phase_authorized=false

No phase auto-authorizes the next.

### 9.3 Required architecture regressions

- no production import from Aaeos/Quarantine;
- no atlas:loop:* operate path;
- no AAEOS direct Kernel call;
- no AAEOS CLI-to-CLI after P1a;
- no second evidence store;
- no AaeosModeExecutor or AaeosActionEffectClassifier authority;
- no human_in_engineering_loop authoritative access;
- no GOD_SOTA output from internal score;
- no caller-declared observer/minter trust;
- no external effect through the generic code path.

## 10. Completion predicate

The MT is DONE only if every item below is proven:

1. P0, P1a, P1b, P2, P3 and P4 receipts exist and are green.
2. R1–R82 are closed, accepted as explicitly non-applicable, or assigned to a named horizon only where this plan already marks HORIZON.
3. R43, R44, R46 and R51–R82 are closed; none may be waived as debt.
4. Dev, Forge and Autônomos each have a completed REAL_OPERATION journey through their native owner chain; internal retries, reviews and repairs are preserved in evidence.
5. runtime_write_performed, effect and proof claims match canonical events and fresh readback.
6. every adverse terminal state has a precise failure_reason.
7. no score, hint, fixture or dry receipt substitutes for a hard gate.
8. one Evidence Ledger remains canonical and passes integrity/replay tests.
9. direct modes remain usable without AAEOS.
10. Quarantine and ACDE remain absent from production.
11. no forbidden duplicate owner was created.
12. the final diff and commits are scoped on local main.
13. `operator_experience=oneshot` is independently proven for all three qualifying journeys from complete canonical operator-event capture; Dev has no technical workflow action, Forge has no post-seal operator action and Autônomos has zero per-task request/action/clarification/wait.

DONE requires both `aaeos_mt_real_journey_verified` and the separate derived fact `operator_experience=oneshot`. The latter describes operator effort only; it never constrains internal attempts or time. DONE does not mean:

- sustained 24/7 reliability;
- comparative superiority;
- world-leading, number one, 10x/50x or GOD/SOTA;
- complete autonomous software company.

Those claims require their own current evidence and claim authority.

## 11. Explicit non-goals and rejected proposals

- Mission Runtime, MissionEnvelope or MissionReceipt.
- WorkGraph-as-OS.
- SovereigntyPort package.
- AaeosModeExecutor.
- AaeosActionEffectClassifier as authority.
- generic external-effect gateway under AAEOS.
- second Ledger, counter JSON or measurement table.
- Evaluation Foundry inside P4.
- raw n={1,2,4,8} as an evaluation plan.
- new cockpit, shell, editor or IDE.
- Quarantine/ACDE resurrection.
- mandate_epoch as a free optional number.
- minter='observer' as proof.
- capability_proof persisted from a caller.
- fixing the human field to false.
- flattening Forge into a dispatch.
- claiming Autônomos worker execution from task next.

## 12. Ten-cycle absolute audit record

The goal requires ten adversarial cycles. A cycle counts only when the current MASTER is attacked, the root judges every proposed survivor against disk owners, and accepted deltas are integrated into this same file plus LEDGER/SCOREBOARD. LOC, score and version bumps do not count.

| Cycle | Input version | Lenses/evidence | Root judgment | Result |
|---|---|---|---|---|
| 1 | v6 RSS+CRES | runtime ownership; enterprise authority; SOTA claims; plan logic; Dev; Forge; Autônomos; evidence/durability; security; minimalism; economics; adversarial falsifier; user-provided six-critic panel; user-provided Claude 11-agent panel | accepted reuse/authority split; rejected land-only CRES, ModeExecutor, new classifier, duplicate axes/metrics and debt-laundered DONE | v7 |
| 2 | v7 zero-duplicate authority | 15 lenses: admission totality; effect×proof seal; axis collapse; economy; model-family collusion; verification cockpit; AEOS duplication; land-observer disk; measure-first teeth; capability_proof dedup; delete-dormant; efficiency/hops; new-tier observer; spine-effect fusion; adversary | accepted 11 reuse/delete/fuse survivors (R64–R74); rejected capability_proof-dedup, axis-collapse and cockpit-render as already-in-v7, and the 6-state admission enum / new observer service / new economy fields as duplication | v8 |
| 3 | v8 reuse/delete survivors | 9 specialized disk-grounded lenses: native Dev, Forge and Autônomos; evidence durability; security/authority; enterprise architecture; SOTA claims; plan logic; OneShot falsification | accepted journey manifest/status, canonical operator-event proof, typed native ingress, signed/revocable mandate, native repair continuation, non-circular receipt binding, async H1–H7 and equal Spec Floor; rejected new Autônomos application/store and caller counters; amended accepted-only economy | v9 |
| 4 | current | pending | pending | pending |
| 5 | current | pending | pending | pending |
| 6 | current | pending | pending | pending |
| 7 | current | pending | pending | pending |
| 8 | current | pending | pending | pending |
| 9 | current | pending | pending | pending |
| 10 | current | pending | pending | pending |

## 13. Changelog

| Version | Change |
|---|---|
| v1–v4 | initial plan, disk forensics, R33–R37 |
| v5 | RSS: same bar, sovereignty/engineering separation, anti-bloat |
| v6 | CRES land observer, claim scope vocabulary, P1 split |
| v7 | replaced land-only with pre-authorize/act/post-attest/settle; reused native owners; removed planned ModeExecutor/classifier/duplicate axes/metrics; added R52–R63; single phase contract, single residual ledger and single DONE |
| v8 | cycle-2 reuse/delete survivors R64–R74: repair_required admission (no enum growth); proof_level derived not settable; spine↔settlement proof fusion; standing measure-first reader-census test; model-family independence + mechanical court; economy view with observer-minted denominator; land-observer two-chokepoint fix; unconditional deletion of two dead self-grading projectors; AEOS code-partition guard; single mode-derivation owner. Zero new organs |
| v9 | cycle-3 R75–R82: operator-only OneShot proof from canonical events; same-journey manifest/status; strict native typed ingress; full signed/revocable standing authority; owner-native repair continuations; non-circular receipt/Ledger binding; async H1–H7; equal Spec Floor. P4 corrected to full Dev journey, full Forge Obra and direct zero-touch Autônomos |

## 14. Handoff

Current state after this document-only version:

- P0–P4 remain NOT_STARTED.
- No production code was authorized or changed by this plan round.
- The next action is another absolute audit cycle, not automatic execution.
- EXECUTE P0 authorizes only section 8 P0.

Operator decision after all ten cycles: EXECUTE P0 or another absolute round.
