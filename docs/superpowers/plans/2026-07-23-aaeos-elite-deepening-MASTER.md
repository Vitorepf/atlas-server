# AAEOS Elite Deepening — MASTER Implementation Plan

> Version: v8 — zero-duplicate authority protocol (+ cycle-2 reuse/delete survivors R64–R74)
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
            Dev handler       → DevPlanRunFacade → AtlasDevExecutionService
            Forge handler     → ForgeObraRuntime → packet execution cycle
            Autônomos handler → Brain application → Seed application → Task owner
        → native effect owner performs pre-effect authority replay
        → native actuator acts
        → native actuator/settler records observed effect
        → AtlasEvidenceLedger
        → AAEOS scorecard/certify/cockpit project read models

AAEOS does not call EliteExecutorKernel directly. The native Dev and Forge owners create ExecutionOrder at their proper boundary. Autônomos carries an order only when Task Fabric already supplied a valid binding; AAEOS never synthesizes one.

### 3.2 Owner map

| Concern | Existing owner to reuse | Forbidden duplication |
|---|---|---|
| Dev planning/execution | DevPlanRunFacade; AtlasDevExecutionService | Dev executor inside AAEOS |
| Forge continuity/execution | ForgeObraRuntime; ForgeWorkPacketExecutionCycleService | flat/one-call Forge wrapper |
| Autônomos origination | AtlasBrainNextCommand logic extracted into a shared application seam | CLI-to-CLI forever |
| Autônomos seed | AtlasBrainSeedCommand logic extracted into a shared application seam | invented --max protocol |
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

Autônomos never waits synchronously for a human. It blocks the reserved effect, persists a sovereign work item and continues other admissible work.

## 5. Contracts

### 5.1 ExecutionOrder and mode facts

ExecutionOrder v2 remains exact. Reuse its canonical top-level fields. In particular:

- duration_regime: interactive, durable_task, obra, continuous;
- work_topology: single, candidate_set, workcell, DAG, portfolio;
- authority data belongs in authority_envelope and decision_receipt;
- tool, release, rollback and outcome constraints use their existing nested policies.

Do not add delegation_regime, work_origin, sovereignty_channel or assurance tier as unknown top-level order fields. Mode/runtime facts may be derived in read models. A sovereignty reference belongs inside the existing authority contract.

The legacy operator_contract.presence string is non-authoritative. It cannot be consumed as technical approval, judge identity or release verdict.

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

The canonical Ledger event binds cycle_id and receipt_payload_hash. The receipt binds event id/hash. Duplicate-identical cycle writes are idempotent; divergent duplicates fail closed.

Removed from the target contract:

- human_in_engineering_loop;
- self-declared minter;
- caller-supplied capability proof;
- repeated cost/tokens/operator effort;
- fake worker or mutation claims from pack/intake/claim;
- quality tier by mode.

### 5.3 Native outcomes and economy

EngineeringOutcome remains the owner for cost, tokens, elapsed_ms and operator_effort. Unknown capture is unknown, never zero. AAEOS correlates and projects it.

Economy is a vector conditioned on accepted outcomes, not a completion score:

- accepted_outcomes / commissioned_outcomes, with retries and failures in the denominator;
- operator_active_seconds, tokens, cost and elapsed p50/p95 per accepted outcome;
- revert, rework and late-failure rates;
- sovereign interventions by H-class;
- claim freshness.

Segment by mode, risk, complexity and canonical duration regime. Quality and safety gates remain primary. Operator touches are not a quality or autonomy KPI.

### 5.4 Claim scopes

Do not persist capability_proof in a cycle receipt.

- Internal MT status is derived from this plan's hard gates and proof level.
- Sustained status is derived from a real time window and heartbeat/SLA evidence.
- Comparative status is derived only from a current Rivals claim and expires/revokes with it.

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
| SUSTAINED | bounded window, N≥5 per mode over at least 7 days, fresh heartbeat, failures/recovery/SLA | sustained internal operation |
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
| R45 | attention queues risk becoming authority | read model only |
| R46 | principal/capability independence not proved | P2 owner hardening |
| R47 | durable sustained government | HORIZON after P4; no Mission Runtime |
| R48 | topology ablation | Quality Foundry/Rivals sister |
| R49 | attention/economy | derive from EngineeringOutcome |
| R50 | external superiority | Rivals only |
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
| R69 | R49 economy is hand-waved; the operator unit (tokens/effort per accepted landing) is never projected, and a false 'released' could inflate the denominator | reuse AtlasMaestroCostAggregator (aggregateByCycle) + EngineeringOutcome.operatorEffort/tokens + AaeosScorecardProjector | scorecard --view=economy emits tokens+operator_effort per released landing sourced only from the cost ledger + outcome store; the denominator counts only observer-minted released cycles (present-but-false status='released' with empty write-set excluded); no new receipt field; read-only, so it never raises the OneShot/Autonomy burden |
| R70 | §P1b land-observer names the two chokepoint files but not the exact primitive, and only one chokepoint is observer-minted (autonomos committer records declared evidence) | reuse AtlasTaskMergeActuator::changedFiles (diff-tree at landed SHA) + CanarySettlementRequest.observerIdentity | post-commit the observed write-set is derived by the same diff-tree primitive as the merge actuator; the autonomos committer's landed event carries observer_identity minted by the settler, not the caller; present-but-false read_only and declared-not-edited goldens pass on both chokepoints |
| R71 | AaeosOperateScorecardProjector is a verified-zero-consumer hardcoded self-grader gated behind an already-satisfied census | delete app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php | class removed; scorecard/certify truth sources only measured/observed dimensions |
| R72 | AaeosTriHygieneScorecardProjector scores AEOS by file size/existence — a refactor-progress proxy and the sole Aaeos→AEOS coupling | delete AaeosTriHygieneScorecardProjector + AtlasTriHygieneScorecardCommand | no AAEOS quality number derives from lineCount()/is_file(); honest structural signal reuses SovereignHonestyFloor + AtlasUniversalGatesEvaluator |
| R73 | AEOS 43k-LOC lattice is unwired from the live Kernel land path yet coupled to Aaeos only via the LOC-proxy scorecard; R10 buried it under P3 doc relabels | reuse AaeosHygieneLegacyAliases seam + SovereignHonestyFloor as sole authority; keep Scoring/* live cores | R10 re-scoped to a P1 code partition: a guard test proves the dormant-ritual AEOS group is unreferenced from EliteExecutorKernel::execute and Aaeos/Control/Dispatch; live Scoring cores (recall relevance, pareto filter, spec-completeness) stay; RunbookOrchestrator 17-phase and DepartmentContractRuntime demoted from operate authority |
| R74 | §5.1 permits mode→(route/sovereignty/delegation) derivation in read models with no single owner, and the router silently defaults an invalid mode to Dev | reuse AaeosModeToDualCoreRoute as the single named derivation owner | §5.1 names one derivation owner; AaeosModeToDualCoreRoute fails closed on invalid/unknown mode instead of defaulting to ROUTE_DEV |

R64–R74 reuse or delete existing owners only; none creates a new organ and none may be waived into DONE.

## 8. Single implementation plan

Every phase begins with branch/status, current hash, dirty ownership, residual revalidation and RED characterization. Every commit is scoped. No phase may absorb the next phase.

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
- app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php (delete if consumer census stays zero)
- app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php
- app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php (bounded read API only; chain hardening stays P2)

Required tests:

- AtlasAaeosRunCommandTest
- AtlasAaeosCycleCommandTest
- AaeosRunCycleParityTest
- AaeosReceiptHonestyTest
- AaeosMeasuredScorecardTest
- AaeosLedgerMeasurementReaderTest
- AaeosAdmissionTaxonomyTest
- AaeosHumanLoopFieldRetirementTest
- AaeosIrreversibilitySuspicionCharacterizationTest

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
- app/Services/Ai/Aaeos/Control/Adapters/* (deletion only after parity)
- app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainNextApplication.php (extraction if no equivalent owner exists)
- app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSeedApplication.php (extraction if no equivalent owner exists)
- app/Console/Commands/AtlasBrainNextCommand.php
- app/Console/Commands/AtlasBrainSeedCommand.php
- native Dev/Forge files only for dependency injection or a demonstrated missing application seam

Required behavior:

- Dev calls DevPlanRunFacade; it never injects Kernel directly.
- Forge calls ForgeObraRuntime and preserves commissioning/packet continuity.
- Autônomos calls application seams, not Artisan.
- brain scope is positional with a documented default;
- seed uses only real signature fields;
- task next is task_claimed, never worker_executed;
- already_done is deduplicated/refused, never new origination;
- handlers return native refs and precise failure causes.

Required tests:

- existing AaeosOperateDispatchTest extended, not replaced;
- Dev dispatcher native-facade contract;
- Forge dispatcher Obra-continuity contract;
- Autonomos dispatcher Brain/Seed contract;
- Brain application parity tests;
- architecture test forbidding CLI-to-CLI and direct Kernel bypass.

P1a exit: existing interface retained; three handlers deepened; four Adapters deleted; R33–R35/R43/R60 green; zero authorization-semantic change.

### P1b — effect authority for code

Objective: prove the section 4 protocol for workspace mutation and canonical code release without creating an AAEOS authority owner.

Slice P1b.1 — authoritative pre-effect replay:

- app/Services/Ai/EngineeringKernel/ExecutionOrder.php
- app/Services/Ai/EngineeringKernel/EngineeringModeExecutionOrderFactory.php
- app/Services/Ai/EngineeringKernel/EliteExecutorKernel.php
- app/Services/Ai/EngineeringKernel/KernelEvidenceAuthority.php
- existing decision/authority owners required by the red test

Exit: a caller-authored order cannot cause provider, sandbox, tool or mutation until the referenced authoritative decision is reloaded and bound.

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

No AaeosActionEffectClassifier, SovereigntyPort, generic action registry or second ledger may be created.

### P2 — shared authority, evidence and durability

P2a — evidence/outcome integrity:

- harden AtlasEvidenceLedger canonical event/envelope/chain verification;
- add event-type + half-open UTC window query and cycle-id idempotency/divergence semantics;
- bind every EngineeringOutcome status to authority;
- require failure_reason for adverse terminal outcomes;
- preserve precise causes through Forge and AAEOS.

P2b — independence and sovereignty receipts:

- use principal_id, capability_id/version, issuer_key_id and producer path from trusted issuance;
- prevent the same capability from minting author + judge + governor;
- reuse/promote the existing Ed25519 signer/verifier into the Decision owner;
- wire ReceiptReversibilityConsentGate only for true H3/unrecoverable effects;
- derive scoped ephemeral capabilities mechanically inside an active authority ceiling.

P2c — unattended durability:

- add a RED stale-resume/revocation test first;
- prefer binding to the exact active decision receipt;
- add a revision/epoch only in the existing receipt owner if the RED proves current id/hash/revocation insufficient;
- revalidate at claim, renewal and pre-effect boundary;
- harden Forge cycle position and terminal transitions with unique constraint + transaction/lock/CAS;
- prove one terminal winner and divergent replay conflict.

P2d — Spine:

- assert applicable shared gates from real order/outcome/event refs;
- empty declarations cannot pass an applicable gate;
- publish applicability, owner, ref/hash, result and readback;
- no class-name-only proof.

Each P2 sub-slice is a separate scoped commit and receipt.

### P3 — deletion and canonical alignment

- remove aliases only after classmap/runtime parity;
- delete unused ObserveRegistry proposal;
- delete dead AAEOS score projectors after consumer census;
- align atlas-agentic-engineering-os-runbook.md so 17 phases/human_review are not the daily operate path;
- align atlas-agentic-engineering-os.md and the elite executor owner with v7 authority semantics;
- replace invented plan_seal/session labels with existing commissioning/authority hashes and canonical enums;
- keep terminal-first: extend atlas:cli:cockpit with read-only proof/economy refs; no new shell;
- update CODEMAP without legacy alias as canonical owner;
- prove Quarantine path/import count remains zero.

P3 does not broaden runtime capability.

### P4 — real-journey gauntlet, OneShot UX proof and freeze

Preconditions: P0–P3 receipts green; R33/R34/R51–R63 green; ops preflight explicit.

P4 gives Atlas the time required to reach a governed result. It does not impose a one-pass or speed target. Every rejection loops internally to the correct planning, implementation, verification or repair stage until accepted, safely blocked or exhausted by an explicit mandate/budget constraint.

Dev proof:

- live intent bound to one plan/run;
- native facade → ExecutionOrder → Kernel → EngineeringOutcome;
- accepted artifact and canonical event fresh-read.

Forge proof:

- commissioned Obra → real packet cycle;
- reservation/lease/fencing and order/outcome refs;
- one terminal winner;
- landed/canary or precise blocked failure;
- packet completion is not falsely promoted to Obra completion.

Autônomos proof:

- Brain status served with a new packet/journal/done-set binding;
- seed propagation;
- task claim;
- real worker report;
- verification;
- scoped canonical land;
- resolved state and Ledger readback;
- Kernel ref only if the packet genuinely carried an order.

Every mode requires a completed REAL_OPERATION journey with:

- operator intent/commissioning/mandate ref;
- operator_required_actions and clarification_count, separated from technical work;
- engineering_attempt_count, review_cycle_count, repair_cycle_count and elapsed time as observed facts, never failure by themselves;
- cycle receipt hash;
- authoritative decision/authorization ref and hash;
- observed effect event ref and hash;
- downstream artifact ref, SHA-256 and bytes where applicable;
- fresh-process read command/result hash;
- exact failure_reason for any adverse result.

P4 exit status is aaeos_mt_real_journey_verified. The derived UX label operator_experience=oneshot is true only when the operator supplied the intent/commission once and performed no routine technical workflow action; allowed exceptional clarifications are enumerated. Sustained and comparative remain not_claimed unless their separate evidence actually exists.

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
2. R1–R74 are closed, accepted as explicitly non-applicable, or assigned to a named horizon only where this plan already marks HORIZON.
3. R43, R44, R46 and R51–R74 are closed; none may be waived as debt.
4. Dev, Forge and Autônomos each have a completed REAL_OPERATION journey through their native owner chain; internal retries, reviews and repairs are preserved in evidence.
5. runtime_write_performed, effect and proof claims match canonical events and fresh readback.
6. every adverse terminal state has a precise failure_reason.
7. no score, hint, fixture or dry receipt substitutes for a hard gate.
8. one Evidence Ledger remains canonical and passes integrity/replay tests.
9. direct modes remain usable without AAEOS.
10. Quarantine and ACDE remain absent from production.
11. no forbidden duplicate owner was created.
12. the final diff and commits are scoped on local main.

DONE means aaeos_mt_real_journey_verified. It may also prove operator_experience=oneshot, which describes operator effort only. It does not mean one-pass code or immediate delivery, and it does not mean:

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
| 3 | current | pending | pending | pending |
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

## 14. Handoff

Current state after this document-only version:

- P0–P4 remain NOT_STARTED.
- No production code was authorized or changed by this plan round.
- The next action is another absolute audit cycle, not automatic execution.
- EXECUTE P0 authorizes only section 8 P0.

Operator decision after all ten cycles: EXECUTE P0 or another absolute round.
