# AAEOS Elite Deepening — MASTER Implementation Plan

> Version: v16 — cycle-9 adversarial security convergence; authority, provenance, resource, filesystem, Ledger and recovery invariants deepened, zero new IDs or organs
> Date: 2026-07-23
> State: PLAN_ONLY
> Implementation: P0–P4 NOT_STARTED
> Authorization required for code: literal EXECUTE P0
> Branch: local main only; scoped commits; never git add -A
> Evidence: docs/evidence/2026-07-23-aaeos-elite-deepening

This is the only master plan for this program. LEDGER.md and SCOREBOARD.md are its only normative satellites. A line, checkbox, score, test fixture, dry receipt, source wire or commit never promotes a stronger proof level by implication.

## 0. Outcome and authority of this document

The target is not a bigger AAEOS. It is a smaller optional Crown that reliably routes into the engineering systems Atlas already owns, while those systems enforce authority, evidence, recovery and claims at their real effect boundaries.

AAEOS is nonetheless the **mother block** of all Atlas agentic software engineering: it is the constitution and governance authority — the product law (§1), the effect-authority protocol (§4), the proof taxonomy (§6) and the residual government (§7) that every mode obeys. "Optional" and "thin" describe its **muscle** (it re-implements no executor), never its **law**: the governance is central and non-bypassable even though the muscle stays in native owners. The reason this mother block exists is the multiplier **M** — governance, refusal, proof, memory, recovery and mandate applied over untrusted provider output N. M is only real if measured (R88), so this plan keeps M falsifiable and never a vanity number.

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

AAEOS is optional-in-**muscle** but the mother authority in **law**: it is a Control + Spine facade that re-implements no executor, yet Dev, Forge and Autônomos — though primary product entries reachable directly — perform their engineering under the AAEOS product law (§1), effect-authority protocol (§4) and proof/residual government. AAEOS may route, cap, correlate and project evidence; it may not become:

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

The default daily command embodies that contract: intent, executor mode when explicitly sovereign-selected, and an authority reference are operator inputs; provider choice, worker cadence, batch width, scope derivation, retry/review/repair policy and live-vs-plan execution are Atlas decisions under that authority. `--dry-run`, audit, pause and revoke remain explicit elective controls, never prerequisites for productive execution. A user who must discover `--live`, `--execute-provider`, `--run-worker-once`, `--max-seeds` or an implementation scope before Atlas will act has been turned into the runtime orchestrator and cannot receive OneShot credit.

OneShot proof is server-derived, not declared by a dispatcher or receipt caller. Its denominator is the complete, versioned census of native operator ingresses and Atlas-to-operator request egresses; every event binds direction, authenticated principal, ingress/egress, episode ref and journey causation. Missing instrumentation or producer seal is `unknown`, never zero.

Dev may ask only an intent question inside the same commissioning episode; Forge may do so only before commissioning is sealed; Autônomos permits zero per-task questions or actions. A clarification must resolve an ambiguity already found by the existing ProductIntent Court and may not choose implementation, provider, test, review, repair, budget, release or effect. Scope, acceptance, risk or authority change opens a new episode. Elective operator audit/pause/revoke is recorded as `operator_initiated_control`, not work requested by Atlas; it never hides an Atlas-requested technical action.

Internal retries are invariant to the OneShot label. They consume the same monotonic signed journey budget and no-progress policy across replan, child packet, handoff, worker or respec; changing an internal id cannot reset authority. A useful attempt changes a server-observed plan, hypothesis, candidate, context delta, test set or authority revision. An identical no-delta attempt is held before another provider call. This is safety and resource governance, not a speed target.

## 2. Current disk truth

Revalidate before every execution slice. The current plan was grounded on these observed facts:

| Fact | Current state | Consequence |
|---|---|---|
| Aaeos live tree | 27 PHP, all under Control + Spine | no foreign production package is needed |
| Quarantine | directory absent; zero PHP | DELETE_DONE; never recreate or import |
| Phase receipts | zero PHASE-* artifacts | program is PLAN_ONLY |
| predecessor receipts | dry only; runtime_write_performed was incorrectly true | no real-operation proof |
| scorecard/certify | hardcoded hints and GOD_SOTA presentation | not claim authority |
| exact score fiction | certify injects operate/spine 9.2 and antifragile 9.0; projector defaults operate/spine/antifragile 9.0 and control 9.2 | P0 removes numeric fantasy; preserve this fact in regression tests |
| CycleRuntime | assertShared(mode, []) can self-green; runtime_write_performed=true; Dev human field=true | P0/P2 blockers |
| Autônomos dispatch | calls brain:next with nonexistent --scope; brain expects positional scope | R33 live failure |
| Brain success | exit 0 can mean disabled or dry | R34 false-positive risk |
| Seed dispatch | invents --max although command has no --max | R35 contract error |
| task next | claims/serves work; it is not a worker cycle | never report worker execution from claim |
| admission | invalid mode/technical incident can become halt_sovereign | taxonomy conflates technical and sovereign |
| irreversibility | AaeosIntentCompiler regex/hints feed admission | suspicion, not authority |
| ExecutionOrder v2 | exact schema; duration enum is interactive, durable_task, obra, continuous; work_topology already exists | do not add conflicting top-level axes |
| Dev native path | DevPlanRunFacade → AtlasDevExecutionService → Dev Kernel port → Kernel | AAEOS must not bypass it |
| Dev live binding | AtlasDevServiceProvider binds RunExecutor→KernelRunExecutor and DevPlanRunFacade→AtlasDevExecutionService; PlanApprovalGate still asks operator ship/reject | use the productive binding; retire repeated approval, not the initial authority token |
| Forge native path | ForgeObraRuntime → work-packet cycle → ExecutionOrder → Kernel | AAEOS must not flatten an Obra |
| Autônomos native path | Brain → Seed → TaskServing → worker → ScopedCommitter; Kernel only for quality-foundry-bound actions | do not fabricate universal Kernel parity |
| Autônomos productive composition | ContinuousRuntimeCycleRunner is pure; RuntimeDaemonCycle receives composed facts; productive composition lives in AtlasSelfConstructionRuntimeDaemonCommand | P1a must name one native productive entry; no fictitious “single call” to three unrelated services |
| Daily AAEOS CLI | `atlas:aaeos:run` defaults plan-only and exposes `--live`, `--execute-provider`, `--run-worker-once`, `--max-seeds` and `--scope` as operator choices | P1a/P2f remove technical commissioning knobs from the productive interface; `--dry-run` remains elective audit |
| Public direct entries | `bin/atlas dev|forge` both reach `AtlasCliDevCommand`; the default efficient branch runs before `--forge` is interpreted and Dev requires a second `--yes` action | public-entry parity must prove native Dev vs Forge identity and zero second technical action |
| Forge forward progress | scheduled `ForgeObraSupervisor` maintains/reaps/heartbeats but does not drive `ForgeObraRuntime::tick/advanceMilestone/completeObra`; no production caller of `completeObra` was found | deepen the existing supervisor/runtime; never add a scheduler or flatten Obra into one dispatch |
| Autônomos liveness | RuntimeSchedulerManifest is disabled/self-install=false and has no proven productive scheduler consumer; default daemon tick without durable facts stops; TaskServing is fail-closed/default-off | R98 must consume server-observed durable facts and existing scheduling/TaskServing owners; no harness `--facts` may qualify |
| AWIS identity | mutative classification omits `autonomos`/unknown while TaskServing currently presents Autônomos as `dev` | signed native mode must reach AWIS unchanged; unknown fails closed |
| Ledger current integrity | first-writer lock can lock no row; event integrity does not recompute the full event hash; runtime DB role can update/delete rows | R54/R80/R83/R85 must prove PostgreSQL append-only and a versioned full-envelope chain |
| Ledger tenant isolation | predecessor and public read queries are not consistently constrained by authenticated tenant | P2a binds tenant into chain/read/replay/dedupe and proves A/B isolation |
| EngineeringOutcome schema | exact v2 rejects unknown fields and has no stable `failure_reason`; many readers consume v2 | R55/R85 require immutable v3 expand/dual-read/shadow/canary, never in-place mutation |
| CodeGraph sovereignty | workspace access policy can merge caller-supplied sovereign actors into the trusted set | caller can only narrow server-issued authority; provider self-add yields zero bytes/calls |
| Tool artifact redaction | `attachPath` can hash/reference original bytes while hardcoding `is_redacted=true` | only transformed persisted bytes may be labeled redacted/provider-bound |
| canonical land | Governor/ledger/nonce/lease/fencing/write-set bindings already exist | deepen these owners; do not create CRES owner |
| Evidence Ledger | canonical owner exists; bounded event-type/window query and full chain verification are incomplete | harden owner, never add a counter store |
| EngineeringOutcome | already owns cost, tokens, elapsed and operator_effort; adverse cause coverage is incomplete | no AAEOS economy receipt |
| claim authority | RivalsClaimAuthority exists | comparative status is derived from it |
| reversibility | ReceiptReversibilityConsentGate exists but has no production caller | wire only for true reserved effects |
| signing | Ed25519 signer exists under Services/AtlasCode; general DecisionReceipt issuer is weaker | reuse/promote primitive, not a second signer |
| real-proof harness | phpunit forces SQLite :memory:, sync queue and disabled real providers; Forge live service identifies a simulate-only test double | PHPUnit may verify receipts but cannot produce REAL_OPERATION |
| predecessor operations | the three predecessor mode receipts are dry, carry no live effects and report the old hardcoded write truth | historical only; never P4 evidence |

### 2.1 Revalidation commands

    git branch --show-current
    git status --short --branch
    rg --files app/Services/Ai/Aaeos -g '*.php'
    rg -n 'human_in_engineering_loop|runtime_write_performed|assertShared' app/Services/Ai/Aaeos app/Console/Commands/AtlasAaeosCertifyCommand.php
    rg -n 'brainNextArgs|--scope|--max|atlas:task' app/Services/Ai/Aaeos app/Console/Commands/AtlasBrainNextCommand.php app/Console/Commands/AtlasBrainSeedCommand.php
    find docs/evidence/2026-07-23-aaeos-elite-deepening -maxdepth 1 -name 'PHASE-*' -print

Any material drift updates the residual ledger before code.

### 2.2 Survival map for the former §44–§68 audit

The v6 numbered catalog is historical, not a second plan. Its executable content survives exactly once:

| Former audit concern | Canonical location now |
|---|---|
| §44 presence checklist is not completeness | §0 and §10 hard conjunction |
| §45 disk forensics, including R4 source-only, archive done and certify 9.2 hints | §2 current disk truth |
| R16–R37 | §7.1 |
| proof vocabulary and measured scorecard | §6 |
| receipt/effect/schema truth | §4, §5.2, R65/R80/R85 |
| run/cycle parity and terminal-first UX | P0, P3 |
| exact phases/tests | §8 authorization manifests |
| dirty main, phase receipts and no silent failure | §9 |
| Quarantine/archive hold | §1.4, R12/R31, §11 |
| Brain/Seed/Task command truth | §2, R33–R35, P1a |
| CODEMAP/aliases/DI | §3, R26/R32/R73/R93, P3 |
| dry-write inventory and predecessor dry receipts | §2, P0, P4 |
| anti-“already done” | §0, §6, §10, LEDGER/SCOREBOARD |

This map proves retention of the obligations, not their completion. Only live phase evidence can close them.

## 3. Architecture and owners

### 3.1 Minimal architecture

    atlas:aaeos:run / atlas:aaeos:cycle
        → AaeosRunApplication
        → AaeosCycleRuntime
        → AaeosLiveDispatchGateway
        → existing AaeosModeLiveDispatcher port
            Dev handler       → SeniorEngineerLoopExecutor → KernelRunExecutor translator → AtlasDevExecutionService
            Forge handler     → ForgeObraRuntime commission/tick/completeObra
            Autônomos handler → productive native daemon entry → native journey ref
        → native effect owner performs pre-effect authority replay
        → native actuator acts
        → native actuator/settler records observed effect
        → AtlasEvidenceLedger
        → AAEOS scorecard/certify/cockpit project read models

AAEOS does not call EliteExecutorKernel directly. The native Dev and Forge owners create ExecutionOrder at their proper boundary. Autônomos carries an order only when Task Fabric already supplied a valid binding; AAEOS never synthesizes one. In particular, AAEOS does not coordinate Brain → Seed → Task → worker step-by-step: it may initiate or observe one existing native scheduler/daemon cycle and correlate the returned refs. Removing AAEOS must leave every direct mode journey executable.

### 3.2 Owner map

| Concern | Existing owner to reuse | Forbidden duplication |
|---|---|---|
| Dev journey and attempts | SeniorEngineerLoopExecutor; productive RunExecutor→KernelRunExecutor binding; DevPlanRunFacade→AtlasDevExecutionService binding | Dev executor, translator or retry loop inside AAEOS |
| Forge continuity/execution | ForgeObraRuntime; ForgeWorkPacketExecutionCycleService | flat/one-call Forge wrapper |
| Autônomos native cycle | AtlasSelfConstructionRuntimeDaemonCommand productive composition; RuntimeDaemonCycle; NativeActionExecutor; NativeWorkerProductionCallbacks | AAEOS-owned Brain/Seed/Task orchestrator or a fictitious composite facade |
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
| provider spawn governance / bypass (M-lever) | ProviderGovernanceCoverageLedger; ProviderGovernanceConsult; GovernanceConsultSkipCounter | second bypass meter or ungoverned muscle spawn |
| delegated learning / memory promotion | AtlasMemoryLearningPromotionService; AtlasHeldEvidenceMinerService; AtlasOpenBrainContextPackService | second memory promoter or evidence→memory bridge under AAEOS |
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
5. SETTLE: evidence binds authorization, observed effect and outcome. A mismatch gives zero capability credit and triggers refuse-next, revoke, rollback or compensation as applicable. Rollback/compensation is owned by AtlasTaskMergeActuator::prepareRevert(CanarySettlementRequest)→AuthorizedRevertAction (and revert() for a landed packet); AAEOS never actuates a revert itself. A release proven bad after a green settle is compensated through that owner with its own authorized action and evidence, not re-litigated as a fresh effect (R90).

Land-only observation is insufficient for an external irreversible effect. An effect cannot be blocked retroactively.

### 4.2 Trust rules

- ExecutionOrder is an untrusted proposal until the referenced decision event and authority binding are reloaded by the owner.
- Caller-declared action_kind, reversibility, source or minter are never authority.
- A declaration may raise the risk ceiling; it may never lower a server-resolved class.
- Trust derives from the emitting owner, canonical event type, causation chain, hashes and replay, not from a minter='observer' string.
- Authorization is replayed immediately before the effect, under the same relevant lock/lease where possible.
- Post-attestation cannot authorize what already happened.
- Text such as `commitAuthority=operator`, caller actor ids, caller public keys and `observerIdentity` arguments are not trust anchors. Operator events come from authenticated ingress; issuer keys resolve through the trusted existing keyring; effect observations are sealed by the native settler/KernelEvidenceAuthority.
- A child effect capability binds mode, native audience/deputy, journey root, order/task/delivery id, action kind, canonical target/scope, effect ceiling, budget and one-effect nonce. A standing mandate may span work; its child effect capability may not be replayed across work, mode, action or owner.

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

### 4.4 Crash and concurrency semantics

The protocol is a recoverable state relation, not five best-effort calls:

- per effect key, ACT is at-most-once;
- `ACTED` enters non-qualifying recovery state `effect_uncertain` until it becomes `SETTLED`, `COMPENSATED` or the absorbing adverse terminal `release_uncertain`; while uncertain, another ACT is forbidden. Evidence discovered after an absorbing terminal opens a separate incident/compensation edge and current-claim invalidation; it never rewrites terminal history;
- authorization consume and revoke/expire serialize on authority revision + effect nonce: revoke wins means zero effect; consume wins means one effect whose settlement remains mandatory;
- authorization receipt/event plus Git trailer/native durable artifact are the recovery journal; no second outbox or recovery store is created;
- fresh-process reconciliation may append one missing idempotent settlement for the observed SHA/write-set, but may not create a second commit/effect;
- journey and repair terminals are single-winner and absorbing; late siblings become evidence only;
- concurrent events form one acyclic causation DAG: every valid arrival permutation of causally incomparable events yields the identical canonical fold, while any reorder that violates a causality edge fails.

The crash matrix covers every boundary from authorization through provider/tool, sandbox, commit, observation, settlement, queue/lease/report and Forge cycle transition on the real durable backend.

### 4.5 Sovereignty reserve

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

A standing mandate is not an opaque receipt label. Its canonically signed payload binds subject principal, issuer/key, workspace and target scope, capability and version, permitted effect/risk/domain ceilings, budgets, release policy, delegation bounds, validity window, revision/head and revocation lineage. Issue, renew, narrow, supersede, revoke, expire and key rotation are immutable linked receipts/events, never in-place mutation. Subject/executor cannot issue, expand or declare current its own authority; issuer, executor, author, judge, governor and revoker satisfy the existing independence floor.

The native owner reloads and verifies that full payload at origination, claim/renewal, after a long provider call and immediately before sandbox/effect. Missing receipt, unsigned metadata, untrusted issuer key, stale revision, wrong audience, scope mismatch or unprovable revocation head means zero adoption/mutative/provider/tool calls. The existing DecisionReceipt owner and promoted Ed25519 primitive remain the only authority; no AAEOS signer or authority store is allowed. Because this changes signed semantics, the mutative target is `atlas.decide.v3`: v2 remains historical/read-only and returns `decision_receipt_upgrade_required` at a mutative boundary.

### 5.2 Minimal AAEOS cycle receipt v2

The cycle receipt is a projection and correlation envelope, not a second evidence truth. The target writer emits `atlas.aaeos.cycle_receipt.v2`; v1 remains dual-readable as `historical_unverified` and can never satisfy P2/P4.

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

The binding is deliberately non-circular. A canonical `receipt_core_hash` covers the persisted `receipt_core` while excluding `evidence_event_id` and `evidence_event_hash`; the Ledger `AAEOS_CYCLE_RECORDED` payload v2 stores both core and core hash. The Ledger envelope version remains independent. The final read projection may add the event id/hash, but those fields are not rehashed into the core. A journey manifest later binds the causal event DAG and terminal artifact. Duplicate-identical cycle/core writes are idempotent; divergent duplicates fail closed.

Schema rollout is expand → dual-read/shadow-verify → canary writer → mutative cutover → contract. A consumer census classifies each reader as current-authority, legacy-read-only or reject-unknown. Old workers block on an unsupported newer authority before provider/effect. Rollback disables the new writer; it never rehashes, resigns, deletes or backfills authority into historical bytes.

Removed from the target contract:

- human_in_engineering_loop;
- self-declared minter;
- caller-supplied capability proof;
- repeated cost/tokens/operator effort;
- fake worker or mutation claims from pack/intake/claim;
- quality tier by mode.

### 5.3 Native outcomes and economy

EngineeringOutcome remains the owner for cost, tokens, elapsed_ms and operator_effort. Unknown capture is unknown, never zero. AAEOS correlates and projects it.

Economy is a vector over the intent-to-treat cohort rooted at every authenticated/native origination episode before admission or commissioning (Dev intent, Forge commissioning attempt, Autônomos task origination), not a completion score and not an accepted-only sample:

- accepted_outcomes / originated_episodes, including pre-commission refusal/abandonment plus blocked, timeout, cancelled, failed, rollback, recovery and retry burn;
- total operator_active_seconds, tokens, cost and elapsed p50/p95 per commissioned journey, with per-accepted-outcome values only as a secondary view;
- revert, rework and late-failure rates;
- sovereign interventions by H-class;
- capture coverage and unknown/unattributed resource burn.

The cohort is a canonical left join from every native origination root in the window through optional commissioning to outcomes/costs; a root with no commission or EngineeringOutcome remains in the denominator. AAEOS must add zero provider calls, context builds, retries, workers or mutations versus a pair bound by intent/spec hash, mode, authority ceiling, provider/model, base SHA, risk/complexity, signed budget and bounded window. Fan-out consumes the signed root budget, deduplicates identical candidate hashes before provider use and is never rewarded by agent count. Context accounting separates unique base, useful causal delta tied to the prior failure/finding, required protocol retransmission, avoidable equivalent retransmission and unknown capture; metadata churn/reordering is no delta. Successor episodes inherit parent lineage and consumed burn; only authenticated sovereign authority may add budget and never erase consumption. Known deterministic blockers yield zero subsequent provider calls.

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

The rows above are evidence facets, not a total order: DRY_RECEIPT does not imply AUTOMATED_CHARACTERIZED, and SOURCE_WIRED does not imply PLANNED completeness. Runtime owners derive an allowed-claim set from the conjunction of the exact predicates for each facet. Removing a required fact strictly weakens/refuses a claim; duplicating or adding unrelated facts never upgrades it; contradictions veto the presentation label.

Every measured dimension carries:

- status: measured, unknown, not_applicable or blocked;
- numerator and denominator;
- sample_count;
- UTC half-open window;
- canonical source query and evidence refs;
- computation version;
- failure_reason when blocked.

No default numeric value substitutes for unknown. No composite certifies DONE.

The multiplier M is falsifiable only as a provenance-bearing vector, never a score: `governed_spawn_coverage = governed_provider_spawns / total_provider_spawns` at actual spawn grain, deduped by provider-execution ref, plus provider-proposal refuse-or-repair counts from canonical outcomes. The existing coverage ledger is reconciled against independently emitted provider-result/EngineeringOutcome egress; manager resolution is diagnostic, not the denominator. Empty/incomplete capture, swallowed writes or divergent counts are unknown/0, never 100%. These diagnostics reveal bypass and governance leverage; they cannot compensate for a failed hard gate or reward more provider calls.

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
| R44 | Spine can self-green from empty declarations (AaeosEngineeringSpine::assertShared(mode,[]) defaults to shared constants → ok:true with zero refs; AaeosCycleRuntime:75 calls it with []) | FOLDED into R66 (see R97): R66 closes R44 by construction at the strictly stronger settlement-receipt-readback bar; not separately closable |
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
| R52 | v6 land-only CRES is too late | pre-authorize → act → post-attest → settle plus crash-cutpoint reconciliation proven for code effects |
| R53 | ExecutionOrder proposal may carry caller-authored authority; provider can run before authoritative replay | active decision/authority event reloaded before provider/tool/sandbox |
| R54 | Ledger integrity/query/dedupe incomplete | recompute full event/envelope chain; single linear head/position under concurrent first writers; bounded query; divergent duplicate veto |
| R55 | EngineeringOutcome adverse causes/authority incomplete | all statuses authority-bound; adverse status requires precise failure_reason |
| R56 | Autônomos claim/already_done can be sold as worker/origination | distinct served, claimed, executed, landed, resolved refs; commit→queue→lease crash reconciliation; already_done gets no new-work credit |
| R57 | Forge terminal concurrency/exactly-once is not proved | unique cycle position + lock/CAS + resume one running cycle/provider lifecycle + single terminal winner + precise cause |
| R58 | v6 capability_proof can become second truth | status derived from hard gates/Rivals only; caller value ignored |
| R59 | standing mandate is a label without stale-resume proof | mutative unattended path binds active receipt id/hash/revision or remains blocked |
| R60 | planned ModeExecutor duplicates existing dispatcher port | no new interface; adapters removed after parity |
| R61 | plan_seal/session enums are labels conflicting with owners | use commissioning/authority hashes and canonical duration enum |
| R62 | P4 criteria accepted a first effect/exit or test receipt instead of a completed operator journey | non-testing real producer + durable backend + end-to-end artifact + independent fresh-process verifier; retries/review/repair remain visible |
| R63 | reversibility gate is orphan and DecisionReceipt signing is not global-strength | integrate existing gate only for reserved effects; promote Ed25519 with trusted keyring at Decision v3 owner |

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
| R69 | R49 economy is hand-waved and an accepted-only denominator hides failed/cancelled/recovery burn | reuse Ledger commissioning roots + EngineeringOutcome + AtlasMaestroCostAggregator + AaeosScorecardProjector | canonical ITT left join includes roots with no outcome and all blocked/timeout/cancel/failure/retry/rollback/recovery burn; accepted efficiency is secondary; unknown capture stays unknown; no new receipt field |
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
| R75 | refs/states from valid runs can be spliced or raced into fake REAL_OPERATION | reuse native root refs + AtlasEvidenceLedger correlation/causation | total journey state projection; one absorbing terminal; causal DAG canonical fold stable under concurrent permutation; only settled `real_operation_completed` qualifies; splice/omit/reorder/cycle/race goldens fail |
| R76 | OneShot counters can be caller-zeroed or hide technical approvals/controls as clarifications | reuse authenticated native ingresses, ProductIntent Court, existing operator-action events and Ledger | complete ingress/egress census and producer seals; typed request/action/control refs; no repeated Dev approval, no post-seal Forge work, Autônomos zero-touch; elective audit/control separated; label invariant under any internal attempt trace |
| R77 | standing mandate is neither fully signed nor provably current; absent/foreign receipt can pass | reuse/promote HumanDecisionReceiptSigner inside Decision v3 owner | trusted keyring, immutable lifecycle, audience/scope/effect/budget/nonce/head binding and SoD; revalidate after provider and under effect lock; absence/mismatch/stale/foreign means zero adoption/effect |
| R78 | AAEOS arrays can mint/discard native identity and real provider/tool/path boundaries are outside the manifest | reuse typed Dev/Forge ingress, productive native daemon, provider/tool/sandbox owners and AtlasSecurity path primitive | no authority remint/fallback; canonical path identical packet→lease→order→sandbox→write-set; server-owned command verdict; AAEOS receives refs only |
| R79 | rejected work and crash-restarted attempts lack owner-complete convergence | reuse Dev RepairOrchestrator/ReceiptStorage, Forge state/cycle journal, TaskServing/lease repair | same root/budget across reject→repair→re-review; prepared/observed attempts durable; late siblings evidence-only; crash resumes one cycle/claim/effect without duplicate |
| R80 | receipt↔Ledger binding is circular and replay lacks concurrent/DAG truth | reuse persisted AaeosCycleRuntime receipt core + AtlasEvidenceLedger | non-circular persisted core/hash; linear append head/position; full hash recomputation; causal DAG manifest; fresh process detects tamper/fork/splice/missing bytes |
| R81 | H1–H7 and cockpit can become a synchronous technical review queue or a hidden global halt | reuse Ledger decision events + existing cockpit/attention projections and TaskServing work claims | reserved effect alone blocks; technical incident contains/repairs autonomously; cockpit partitions read-only review from sovereign attention; while A waits, unrelated B is actually claimed, executed and settled; valid issued decision resumes A exactly once after replay; no new queue/wait |
| R82 | Spec Floor/authority roles allow self-composed or self-expanded authority | reuse SpecSourceIndependence, witness resolvers, SovereignSpecFloor, VerificationCourt and Decision roles | same independence floor all modes; subject/executor cannot issue/expand own authority; author/judge/governor/revoker conflicts fail before provider |

R75–R82 are hard, reuse-only blockers. None may be waived into DONE.

### 7.6 v10 cycle-4 survivors R83–R86

Cycle 4 used 13 lens-distinct specialist passes and a final anti-duplication judge. Root merged operator/authority/state/cockpit findings into R15/R45/R53/R57/R62/R70/R75–R82 and rejected new stores, state machines, queues, outboxes, retry owners and compliance organs. Four cross-cutting gaps could not be represented truthfully by an older residual.

| ID | New blocker | Existing owners reused | Hard done condition |
|---|---|---|---|
| R83 | crashes and real concurrency can split Git/Ledger settlement, queue/lease/report, Forge cycle/provider lifecycle, Dev repair and the first Ledger head | native Git trailer/artifact, AtlasEvidenceLedger, TaskServing lease/queue repositories, Forge cycle/state, Dev ReceiptStorage | deterministic fault injection at every cutpoint; restart converges to exactly one effect and one absorbing terminal or precise `release_uncertain`; Postgres unique constraints/locks prove real races; no second outbox/store |
| R84 | PHPUnit/fixtures/simulate-only services can manufacture “REAL_OPERATION” and certify their own receipts | native non-testing mode commands/runtimes produce; AtlasEvidenceLedger/artifact/Git/provider receipts persist; independent certifier verifies | producer runs outside PHPUnit on a controlled real workspace and durable DB with real provider/tool/effect where required; new process recomputes all truth; mutation testing and subtract-one invalidator matrix are hard vetoes; simulator/exit 0/test JSON never qualifies |
| R85 | authority, cycle receipt and Ledger payload semantics would be mutated in place across mixed-version consumers | DecisionReceipt v3; cycle receipt v2; cycle-recorded payload v2; current Ledger envelope; existing readers and Code Intelligence census | expand→dual-read/shadow→canary writer→mutative cutover→contract; legacy stays historical/read-only; unsupported worker fails before provider/effect; rollback preserves signed/event bytes; migration preflight, Postgres proof and consumer census green |
| R86 | AAEOS/provider/context/fan-out/retry amplification can be hidden or “optimized” by punishing useful repair | Ledger commissioning roots, EngineeringOutcome, Maestro cost aggregator, native planners/repair owners | ITT left join includes every root and all burn; AAEOS adds zero provider/context/retry/worker/mutation hops; useful retries never hurt OneShot/quality; identical no-delta retries stop before provider; signed root budget survives id changes/handoffs; fan-out deduped and bounded |

R83–R86 are hard blockers. They deepen existing durable facts and owners; they authorize no new runtime organ.

### 7.7 v10 cycle-4 consolidation + strategic reach R87–R97

Concurrent-authorship note: §7.6 (R83–R86) is the other author's cycle-4 set (crash-safety/exactly-once, real-proof harness, schema migration, ITT amplification) — independently re-verified against disk and kept. This §7.7 is this session's cycle-4 set, renumbered R87–R97 to remove the R83–R86 collision. Cycle-4 attacked v9 for accretion, executability and the strategic M-frontier, re-verified every claimed owner on disk (all present), REJECTED the over-fusion of R54/R66/R75/R80 (it would launder four distinct owner+phase done-conditions), and accepted only the genuinely-redundant R44 fold plus reuse-wires whose owners exist. AAEOS is repositioned as the mother block of governance/LAW (§0/§1.3): thin in muscle, central and non-bypassable in law; M is made falsifiable (R88).

| ID | Gap surpassing v9 | Existing owner reused / deleted | Hard done condition |
|---|---|---|---|
| R87 | the AiProviderManager governance/bypass owner — the operator-named structural M-lever — is absent from the §3.2 map; muscle may spawn a provider ungoverned | reuse ProviderGovernanceCoverageLedger + ProviderGovernanceConsult + GovernanceConsultSkipCounter (schema atlas.ai.governance.provider_coverage.v1) | §3.2 names the owner; every muscle provider spawn is recorded covered (governed) or bypass; no second bypass meter |
| R88 | M — the multiplier that is the plan's reason to exist — has no falsifiable instrument; v7–v9 deleted the N×M framing | reuse ProviderGovernanceCoverageLedger coverage rate + EngineeringOutcome | §6 emits governed_spawn_coverage = governed_provider_spawns / total_provider_spawns (unknown-not-zero; empty ledger = honest 0.0); M is governed reach + provider-proposal refuse/repair rate, never a vanity score |
| R89 | H4 authorizes delegated learning but names no owner; the memory-M seam is unfenced | reuse AtlasMemoryLearningPromotionService + AtlasHeldEvidenceMinerService + AtlasOpenBrainContextPackService | H4 + §3.2 name the delegated-learning owner (reversible, auditable, propose-only); §11 forbids a second memory promoter / evidence→memory bridge under AAEOS |
| R90 | §4 SETTLE names "compensation as applicable" with no owner; a landed-then-proven-bad release has no path | reuse AtlasTaskMergeActuator::prepareRevert(CanarySettlementRequest)→AuthorizedRevertAction (+ revert()) | a green-settled release later proven bad is compensated through that owner with its own authorized action + evidence; AAEOS never actuates a revert |
| R91 | Dev OneShot is blocked wherever the operator plan-approval gate becomes a repeated technical workflow action | reuse the initial ConfirmedDevRun authority once; keep AtlasDevPlanApprovalGate + AtlasDevProviderExecutionBlock as optional audit/compat projection during migration | both direct Dev and AAEOS-routed Dev reach provider/replan/repair with zero repeated ship/reject action; PlanVisible never gates a plan already inside the same signed intent episode |
| R92 | P2's six sub-slices need an explicit order because several edit AtlasEvidenceLedger.php | declare the ordered DAG over existing P2a–P2f | §8 P2 states P2a foundation first and all dependencies; same-file/same-owner commits never run concurrently; no new phase |
| R93 | AaeosHygieneLegacyAliases eagerly loads 40 canonical classes while current code census finds zero legacy-FQCN consumers | reuse R26 classmap/runtime proof; lazy sibling pattern only as a temporary compatibility fallback | after Composer/classmap/negative-resolution plus runtime-invocation census: delete the map+autoload entry if zero; otherwise make only observed aliases lazy with named expiry. Boot never eagerly loads AEOS; no unproved bulk deletion |
| R94 | the 2026 verification moat is not first-class: atlas:review:deep is unmentioned and its verdict UX can become a technical operator gate | reuse AtlasReviewDeepCommand (atlas.review.deep_packet.v1) + the R75 journey-manifest projection shared with atlas:cli:cockpit | verification artifact = observer-minted effect + proof + admission + journey manifest from one read model; review:deep is optional inspection, never required operator verdict; its duplicate risk derivation is deleted |
| R95 | reserved-decision events must not hide a real task-causal operator action in Autônomos | reuse R81/§4.5 Ledger escalation events and journey causation | DecisionDrafted/EscalationRequested alone are system events; a task-causal DecisionIssued is a sovereignty action and disqualifies that Autônomos journey from zero-touch P4, while a pre-issued/global mandate event outside task causation does not; no self-reported counter |
| R96 | R75's terminal-status enum has no stated boundary against R79 repair-continuation (is a repair a new journey?) | reuse AtlasRepairOrchestrator, Forge cycle owner, TaskServing give-back already named in R79 | every repair/replan/re-review (R79) is an intra-journey continuation appended to R75's single-root ordered manifest, never a new journey/root; a repaired journey has one root and one qualifying terminal state |
| R97 | duplicate/latent-duplicate accounting and over-fusion risk in the evidence-integrity family | reuse §6/§10 single fresh-process readback; §7 residual discipline | R44 folded into R66 (single stronger bar); R66 CONSUMES R80's non-circular readback rather than re-asserting it (R66 keeps only the spine-gate-site hole); NO-FUSE GUARD: theme-shared rows with distinct owner+phase+done-condition (R54/R66/R75/R80; R68/R82; R70/R78) stay distinct and independently closable |

R87–R97 reuse or delete existing owners only; R97 removes one row (R44) and forbids future laundering. None may be waived into DONE.

### 7.8 v11 cycle-3 completeness supplement R98–R100

The original cycle-3 record had nine lenses and therefore did not meet the requested thirteen-lens floor. Four independent supplement passes re-attacked the v8 baseline and then checked every finding against the current v10 tree. Most findings were already absorbed by R75–R97. Root accepted only the three current gaps below; this is a disclosed historical correction, not a fabricated claim that v9 already contained them.

| ID | Current gap exposed by the supplement | Existing owner reused / exact deletion | Hard done condition |
|---|---|---|---|
| R98 | the productive Autônomos composition is private inside `AtlasSelfConstructionRuntimeDaemonCommand`, while P1a names no callable path and forbids implicit files | extract exactly `app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemon.php` from the command's current `productiveCycle`/tick/run-once composition; both the command and AAEOS call it; delete the private duplicate composition | direct daemon and AAEOS-routed dispatch return the same native journey/cycle/task refs through the same service; no `Artisan::call`, Brain→Seed→Task choreography or second runtime owner; AAEOS ablation leaves direct execution green |
| R99 | the daily CLI makes the operator select live execution, provider enablement, worker step, seed batch and scope before productive work | reuse `AtlasAaeosRunCommand`, native mode/authority inputs and server-owned routing; remove productive `--live`, `--execute-provider`, `--run-worker-once`, `--max-seeds`, `--scope`; retain explicit `--dry-run`/audit/control | `atlas:aaeos:run <intent>` enters the governed productive native journey with no second technical action; Atlas derives provider/cadence/width/scope inside signed caps; removed flags fail with migration guidance and can never be required for OneShot |
| R100 | H1–H7 continuation proves only that unrelated work is runnable, so a global halt can masquerade as zero-wait | reuse R81 Ledger continuation plus TaskServing claim/execute/settle owners | two-work-item fault test: A persists H1–H7 and produces no reserved effect; before A's `DecisionIssued`, unrelated B is claimed, executed and settled; then A resumes exactly once under its original `action_hash`/`continuation_ref` |

R98 is a named extraction of current composition, not a new runtime. R99 deletes operator choreography. R100 strengthens R81 with an observable progress witness. None may be waived into DONE.

### 7.9 v12 cycle-5 survivors R101–R103

Cycle 5 used thirteen lens-distinct specialist passes over phase executability, OneShot UX, Dev, Forge, Autônomos, Ledger/PostgreSQL, security, schema rollout, real proof, routing/ablation, deletion census, efficiency and terminal/review, followed by an anti-duplication judge. Root rejected residual inflation: Forge progress, Autônomos liveness, Ledger append-only, keyring, technical OneShot names, terminal truth, provider coverage and budget findings deepen R33–R35/R53/R54/R62/R69/R76–R80/R83–R88/R91/R93–R100. Only three owner/phase/done-condition gaps survived.

| ID | New blocker | Existing owners reused | Hard done condition |
|---|---|---|---|
| R101 | P1b can authorize provider/tool/sandbox/ACT before Decision v3, trusted issuer keys, revocation head and mixed-version refusal exist | AtlasEvidenceLedger + DecisionReceipt owners + promoted Ed25519 primitive; no second signer/store | P1b before P2 is characterization/refusal-only; P2a Ledger expansion then P2b Decision v3/keyring/revocation and AWIS parity precede P1b authoritative replay/ACT; absent/v2/stale/unknown authority causes zero provider/tool/sandbox/mutation |
| R102 | AWIS mutative classification omits `autonomos`/unknown and TaskServing masks Autônomos as Dev, enabling a confused-deputy downgrade | AwisExecutionGatePort + AtlasWorkspaceIntelligenceExecutionGateService + AtlasTaskServingService + EngineeringExecutionSurfaceRegistry | mode is derived from signed native authority; Dev/Forge/Autônomos mutative surfaces are explicit; unknown fails closed; caller cannot relabel mode or downgrade to conversation; same identity is rechecked immediately before effect |
| R103 | internal owner tests can pass while canonical public entries route to the wrong executor, require a second operator action, or legacy consumers break on retirement | `bin/atlas`, AtlasCliDevCommand/efficient handler, native Dev binding, ForgeCommissioning/ForgeObraRuntime, R98 daemon seam and complete consumer/classmap/runtime census | fresh-process matrix proves `atlas dev`, `atlas forge`, direct Autônomos and routed `atlas:aaeos:run` preserve exact native mode/root/authority/outcome semantics with zero second technical action and with AAEOS absent for direct paths; no legacy executor/alias/adapter/review owner is deleted until all production/config/reflection/doc consumers are migrated and cold-process negative resolution is green |

Cycle-5 merge requirements are binding even without new IDs:

- R54/R80/R83/R85: versioned Ledger v2 envelope, full canonical hash basis, transactional first-head serialization, unique position and PostgreSQL runtime-role `UPDATE/DELETE` refusal; old bytes remain `legacy_unverified`, never rehashed.
- R62/R79/R84: existing Forge supervisor/runtime drives a sealed multi-packet Obra through tick→Court→repair→milestone→`completeObra`; crash resumes one cycle. The simulate-only service cannot qualify until replaced/deepened in that same native chain.
- R76/R95/R99: census and migrate reachable technical `OneShot*`/copy-paste/manual-next semantics; live concepts become tick/attempt/dispatch with no attempt-count implication; historical schemas remain read-only.
- R69/R75/R86: ITT begins at authenticated intent ingress; successor episodes carry parent burn; only authenticated sovereign authority may add budget; direct-vs-routed pairing binds intent/spec/mode/authority/provider/base/risk/window.
- R87/R88: provider coverage measures actual spawns at one grain, reconciled against independent provider-result/EngineeringOutcome egress; write loss or mismatch is `unknown`, never 100%; the duplicate skip counter becomes historical then is deleted.
- R81/R91/R94/R99: human accept/reject cannot mint passed engineering truth; scheduled landing verdicts and cockpit next commands become history/H1–H7 attention only; the read-only cockpit may fail open for display but never qualify P4.
- R93/R103: retain PipelineRunExecutor, aliases, OrgState and OutcomeRecorder until every named consumer/schema migration is authorized and proven; safe isolated self-graders may be deleted exactly.

No cycle-5 survivor creates a fourth executor, new scheduler, new Ledger, outbox, authority store, metrics store or terminal surface.

### 7.10 v13 cycle-6 convergence — zero new residual IDs

Cycle 6 applied thirteen separately labeled lenses: formal state machines, property/model checking, Byzantine independence, failure taxonomy, proof lattice, tenant/workspace isolation, secrets/privacy, supply-chain/sandbox, incident recovery, phase DAG, exact execution profiles, compatibility/deletion and OneShot/public acceptance. The anti-duplication judge found five real blockers but rejected R104+: each has the same owner, phase and done condition as an existing residual.

- R55+R85: `EngineeringOutcome` becomes immutable v3 with stable `failure_reason_code` plus precise detail for every adverse status; v2 remains historical/read-only; producer/consumer census and expand→dual-read/shadow→canary→contract are mandatory.
- R54+R80+R83+R85: authenticated tenant constrains chain key, predecessor, event-id/scope/correlation/latest/outcome queries and replay; same identifiers across tenants never link, read, dedupe or qualify.
- R53+R78+R101: CodeGraph actor/workspace privacy comes from signed server authority/config; caller options can only intersect/narrow, never append sovereign actors.
- R78+R84+R101: tool `attachPath` labels redacted only when the persisted referenced bytes were actually transformed by AtlasSecurity; raw/untransformable artifacts are refused or explicitly non-provider-bound/non-qualifying.
- R62+R84+R85+R103: exact non-test PostgreSQL producer and independently authenticated read-only verifier roles, commands, environment variables and negative privilege tests are phase gates; missing profile fails rather than skips.
- R75/R80/R83/R96: `effect_uncertain` is a non-qualifying recovery state, or `release_uncertain` is an absorbing adverse terminal whose later discovery opens a new incident/compensation edge; terminal history is never rewritten. Valid permutations of causally incomparable events canonicalize identically; causality-violating reorder fails.
- R23/R65/R84: proof is an allowed-claim set derived from conjunctive predicates, not a misleading total-order scalar; removing any required fact weakens/refuses and unrelated duplication never upgrades.

The cycle also requires Decision rollout checkpoints and moves no authority/effect boundary earlier. Zero new Ledger, privacy service, identity store, credential store, incident module or proof metric is authorized.

### 7.11 v14 cycle-7 native-journey convergence — zero new residual IDs

Cycle 7 applied thirteen lenses to full Dev, Forge and Autônomos journeys; direct/routed parity; 0..N repairs; crash cutpoints; PostgreSQL races; authority TOCTOU; budget across restart; real producers/certifier; operator census; scheduler cold-start; and freeze/late invalidation. The anti-duplication judge rejected every R104+ proposal and made six existing-residual strengthenings binding:

- R56/R78/R83/R98: Autônomos persists observer-minted canonical land, independent EngineeringOutcome and release/canary refs before exactly one TaskServing success report/resolve; land-before-report crash reconciles one report, while report-without-proof can never resolve.
- R57/R62/R66/R75/R79/R80/R83/R84: existing Forge supervisor drives the sealed Obra through tick→Court→repair→dependency-safe milestone→`completeObra`; certification is Ledger-resolved and bound to this commissioning, complete DAG/outcomes, landed SHA and canary.
- R53/R77–R79/R91/R96/R101: SeniorEngineerLoopExecutor owns one Dev journey; RepairOrchestrator owns 0..N attempts; every attempt retains DevIntent/ConfirmedDevRun, Decision v3 revision and monotonic budget, re-enters Court and never emits routine `human_action_required`.
- R62/R84/R85/R103: public producers and existing certifier use distinct OS/boot/app/DB identities and privileges; strict terminal result, not exit 0, qualifies; certifier recomputes a fixed cutoff with SELECT-only DB and no provider/tool/workspace/Ledger-write capability.
- R33–R35/R56/R83/R98/R100: existing scheduled surface consumes the scheduler manifest and cold-starts from durable server facts/known empty queue, replenishes a causally new Brain→Seed→Task root and resumes once across kill without `--facts`, preseed, operator command or AAEOS dependency.
- R75/R80/R83/R84/R90: global freeze binds tenant/chain cutoff, three mode manifests, code/workspace SHAs, environment/DB identities and verifier result. Later canary failure, revocation, compensation, fork/tamper or artifact drift invalidates the current DONE projection without rewriting historical terminals.

No new scheduler, worker, reporter, repair loop, verifier, freeze store, queue or receipt owner is authorized.

### 7.12 v15 cycle-8 operator-experience convergence — zero new residual IDs

Cycle 8 applied thirteen lenses to cognitive load, Dev live intent, Forge planning boundary, Autônomos zero-touch, daily entries, cockpit/inbox, H1–H7 UX, audit/control, elapsed time, n=1/fan-out, memory/learning, public help/API and claim ambition. The judge rejected every R104+ proposal and bound six strengthenings to existing owners:

- R79/R83/R91/R103: once a Dev or Forge root/authority is durably acknowledged, killing the operator's terminal cannot stop the journey; it recovers to one terminal result under the same root/budget. Later attach is audit; `atlas continue` is only for operator-initiated pause/cancel recovery.
- R76/R99/R103: clarification is a fail-closed enum bound to the exact ProductIntent Court objection/episode; technical choices are forbidden. Missing productive intent returns non-zero `intent_required`, never synthetic work. Help/completion/migration guidance and bare transcripts teach one intent with no technical second action.
- R45/R77/R81/R89/R95/R100: one versioned H1–H7 payload binds reserved class, task causation, issuer/actor, action hash, authority gap, scope/effect, continuation and decision refs. Draft/escalation/learning/attention are projections, never operator actions or authority; only signed DecisionIssued resumes once.
- R19/R29/R55/R69/R85/R86: EngineeringOutcome v3 derives elapsed/operator effort from durable server events; missing capture is null+unknown, never zero. OneShot remains invariant to elapsed/internal cycles and presenters retain unknowns in ITT/capture coverage.
- R69/R79/R86: n=1 is default. Fan-out requires server-observed decomposable width and signed root-budget headroom; child/handoff burn stays on the parent, candidate hashes dedupe before provider use and useful repair is never penalized.
- R23/R65/R84/R88/R94/R97: scorecard/certify/cockpit/review present a conjunctive, scoped, cutoff-bound, stale-aware allowed-claim set. Missing/unknown/contradictory facts weaken/refuse; duplicated unrelated facts never upgrade; REAL_OPERATION, OneShot, SUSTAINED and COMPARATIVE remain independent.

Zero new notification, learning, attention, fan-out, marketing or claim service is authorized.

### 7.13 v16 cycle-9 adversarial security convergence — zero new residual IDs

Cycle 9 applied thirteen abuse lenses to tenant/confused-deputy identity, delegated capability replay, secret egress, filesystem races, command/environment injection, provider-result forgery, Ledger tamper/truncation, PostgreSQL role/restore integrity, executable/dependency supply chain, resource exhaustion, instruction/memory poisoning, multi-engine dirty-main ownership and incident/compensation recovery. The anti-dup judge rejected every R104+ proposal and merged the security obligations into existing owners:

- R52–R54/R59/R70/R75/R77/R78/R83/R84/R86/R101/R102: authenticated tenant/principal/mode/producer are signed and fail-unknown; child authority validates the complete acyclic ancestor chain, cascades revocation and atomically consumes parent nonce/budget. Workspace identity binds root, device/inode, tenant, workspace id and base SHA; operation-time no-follow checks plus base-blob/exact-delta observation defeat symlink, hardlink, index and foreign-hunk races.
- R53/R63/R77/R78/R84/R87/R101: provider, tool and process boundaries accept structured argv only; canonical executable realpath/version/hash plus interpreter/script and installed dependency materialization are rechecked immediately pre-exec under a clean allowlisted environment. Secret refs are signed names, resolved only at the final process boundary, and the provider-egress census covers prompt/context/argv/cwd/env/stdin/stdout/stderr/exceptions/artifacts/Ledger/API without recording raw values.
- R54/R62/R66/R68/R75/R78/R80/R82/R83–R85/R87/R88/R101/R103: provider consultation, observer-minted spawn, result and EngineeringOutcome sets must be equal; provider text cannot certify its own success. Ledger v2 has one exact full-envelope hash and verifies an independently authenticated cutoff/head/position/count, refusing empty, prefix/suffix-truncated, forked, spliced, stale or anchor-missing histories. PostgreSQL proof asserts actual session/current role and grants, forbids role escalation, and includes a real dump/restore refusal drill.
- R53/R69/R77/R79/R83/R86/R87/R101: one signed root ceiling atomically reserves and reconciles tokens, cost, wall/context and concurrent spawns across children, retries, handoffs and restart. Governance absence/error is `unknown` with zero mutative/qualifying spawn; useful repair is allowed until the root ceiling is honestly exhausted, then one precise adverse terminal is emitted.
- R53/R77/R78/R82/R85/R89/R101: instruction provenance and precedence are server-owned and signed. Conversation turns, compactions, retrieval, memory, provider handoffs, tool output and artifacts remain cited data; promotion preserves origin/content hash, signer/reviewer, supersession head and `instruction_allowed=false`, so echo, encoding, stale memory or self-citation cannot mint authority.
- R52/R53/R57/R70/R75/R78/R79/R83/R103: AOBG blackboard claims remain advisory and fail-open for coordination, never execution authority. The native lease, allowed write-set, base/index/worktree and exact preimage→postimage delta are recomputed under the existing integration/commit lock; overlapping or unattributed dirty content refuses while disjoint work proceeds.
- R23/R65/R75/R77/R80/R83/R84/R90/R94/R97: incident handling first revokes/inhibits authority and descendants, then appends original effect, compensation attempt, late settlements and residual uncertainty to the same causal Ledger fold. Compensation never rewrites history or resurrects DONE; current claims remain stale/false until new independent certification.

No new security gateway, budget meter, prompt-policy service, ownership store, incident store, signer, Ledger or verifier is authorized.

## 8. Single implementation plan

Every phase begins with branch/status, current hash, dirty ownership, residual revalidation and RED characterization. Every commit is scoped. No phase may absorb the next phase. The production and test path lists below are authorization manifests, not examples: `*`, “if needed” and unnamed native owners are forbidden. If a RED proves that an unlisted path must change, execution stops and this MASTER is amended before that path is edited.

Evidence writes are also closed-manifest: every slice may update only this MASTER, LEDGER, SCOREBOARD and its exact generated receipt under `docs/evidence/2026-07-23-aaeos-elite-deepening/`: `PHASE-P0.json`, `PHASE-P1A.json`, `PHASE-P1B1.json`, `PHASE-P1B2.json`, `PHASE-P1B3.json`, `PHASE-P2A1.json`, `PHASE-P2A2.json`, `PHASE-P2B-EXPAND.json`, `PHASE-P2B-SHADOW.json`, `PHASE-P2B-CANARY.json`, `PHASE-P2B-CUTOVER.json`, `PHASE-P2B-CONTRACT.json`, `PHASE-P2C.json`, `PHASE-P2D.json`, `PHASE-P2E.json`, `PHASE-P2F.json`, `PHASE-P3A.json`, `PHASE-P3B.json` and the four P4 receipts already listed. Creating any other evidence artifact requires a MASTER amendment. These generated receipts are evidence, never normative satellites.

### P0 — truth before capability

Objective: remove false success, unify the daily port and produce an honest read-only projection. P0 does not fix Brain execution or effect authority.

Production paths:

- app/Console/Commands/AtlasAaeosRunCommand.php
- app/Console/Commands/AtlasAaeosCycleCommand.php
- app/Console/Commands/AtlasAaeosScorecardCommand.php
- app/Console/Commands/AtlasAaeosCertifyCommand.php
- app/Console/Commands/AtlasCliCockpitCommand.php (truth presentation only)
- app/Console/Commands/AtlasAaeosRouterCommand.php
- app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
- app/Services/Ai/Aaeos/Control/AaeosIntentCompiler.php
- app/Services/Ai/Aaeos/Control/AaeosAdmissionPolicy.php
- app/Services/Ai/Aaeos/Control/AaeosAdmissionVerdict.php
- app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
- app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php (delete; R71 census already zero)
- app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php
- app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php (bounded read API only; chain hardening stays P2)
- docs/engineering-knowledge-base/atlas-cli-daily-map.md
- docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md
- docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md
- docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P0.json (generated)

Test paths:

- tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php (existing; extend)
- tests/Feature/Ai/Aaeos/AaeosGodSotaCertificationTest.php (existing; invert false-success assertions)
- tests/Feature/Ai/Aaeos/AtlasAaeosRunCommandTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosRunCycleParityTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosLedgerMeasurementReaderTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosAdmissionTaxonomyTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosHumanLoopFieldRetirementTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosIrreversibilitySuspicionCharacterizationTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php (existing; dry-write and empty-Spine assertions)

P0 exit:

- run/cycle share the existing `AaeosCycleRuntime` as the one application use case and parity matrix; no `AaeosRunApplication` wrapper is created;
- dry run performs zero runtime writes;
- neither command calls AaeosCycleOutcomeRecorder in dry mode; dispatch_failed exits non-zero in both ports;
- runtime_write_performed is derived;
- prepared/claimed/mutated are distinct;
- zero authoritative `human_in_engineering_loop` readers; P0 freezes new writes and inventories remaining dispatcher writes for exact removal in P1a, rather than editing unlisted paths;
- invalid technical input returns technical repair/block, never sovereign halt;
- regex/hints emit suspicion only;
- zero-sample measures are unknown/null;
- certify, cockpit, router and the active daily map cannot emit/teach GOD_SOTA or a numeric composite without measured evidence;
- P0 receipt and current SCOREBOARD are durable.

Commit boundary: one truth/port commit; one measured-reader/presenter commit if the diff would mix owners.

### P1a — native dispatch, no new executor

Objective: deepen the existing dispatcher port, remove the duplicate adapter stack and fix native transport semantics.

Production paths:

- bin/atlas
- config/atlas_dev.php
- app/Console/Commands/AtlasCliDevCommand.php
- app/Services/Ai/Cli/AtlasCliDevEfficientHandler.php
- app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
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
- app/Services/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionService.php
- app/Http/Controllers/AtlasDev/Support/KernelRunExecutor.php
- app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopExecutor.php
- app/Providers/AtlasDevServiceProvider.php
- app/Services/Ai/Programming/Forge/Execution/ForgeCommissioning.php
- app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php
- app/Services/Ai/Programming/Forge/Execution/ForgeObraSupervisor.php
- app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php
- app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php
- app/Console/Commands/AtlasForgeLiveExecuteCommand.php
- app/Services/Ai/SelfConstruction/ContinuousRuntime/AtlasSelfConstructionContinuousRuntimeCycleRunner.php
- app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycle.php
- app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemon.php (NEW; exact extraction of command-private composition, not a new runtime owner)
- app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionNativeActionExecutor.php
- app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSchedulerManifest.php
- app/Services/Ai/SelfConstruction/TaskServing/AtlasTaskBrainReplenisher.php
- app/Services/Ai/SelfConstruction/AtlasTaskServingStack.php
- app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerProductionCallbacks.php
- app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php
- app/Console/Commands/AtlasSelfConstructionRuntimeDaemonCommand.php
- routes/console.php

Required behavior:

- Dev accepts native DevIntent/ConfirmedDevRun lineage, preserves the productive SeniorEngineerLoopExecutor→KernelRunExecutor→AtlasDevExecutionService chain and its current provider binding; it never injects Kernel or owns retry directly.
- Forge constructs strict ForgeCommissioning in its native owner, rejects unknown/malformed packet data, calls ForgeObraRuntime and returns the Obra ref; P1a does not enable provider execution before P1b authority replay is green.
- the existing Forge live command/service remains explicitly characterization/fixture-only until authority and durability are green; the existing `ForgeObraSupervisor`/runtime becomes the single production forward driver for tick→Court→repair→milestone→`completeObra`, never a new scheduler.
- Autônomos invokes `AtlasSelfConstructionRuntimeDaemon` through the exact extracted typed seam shared with `AtlasSelfConstructionRuntimeDaemonCommand`, not Artisan and not a step-by-step AAEOS orchestration; it returns native journey/cycle/task refs. The extraction moves the command-private composition and deletes that duplication; it may not create another runtime.
- P1a does not make the daily CLI mutative before Decision v3. It adds structural/public-entry parity and deprecated-flag migration only; P2f later makes `atlas:aaeos:run <intent>` productive under server-owned signed caps. Until then, provider/effect paths are refusal-only.
- `bin/atlas dev` reaches the live intent session when taskless and consumes initial intent authority once when tasked; `bin/atlas forge` reaches strict ForgeCommissioning before any efficient/default short-circuit. Explicit mode identity cannot be changed by config defaults.
- brain scope is positional with a documented default;
- seed uses only real signature fields;
- task next is task_claimed, never worker_executed;
- already_done is deduplicated/refused, never new origination;
- handlers return native refs and precise failure causes.
- synthetic session/intake packs, `recommended_flow`, `next_commands` and `provider_opt_in_noted` are deleted after native parity; the operator receives a result/ref, not a worklist.

Test paths:

- tests/Unit/Ai/Aaeos/Control/AaeosOperateDispatchTest.php (existing; extend)
- tests/Unit/Ai/Aaeos/Control/AaeosNativeDevDispatchContractTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosNativeForgeDispatchContractTest.php (NEW)
- tests/Unit/Ai/Aaeos/Control/AaeosNativeAutonomosDispatchContractTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosDirectModeAblationTest.php (NEW)
- tests/Unit/Ai/Programming/Forge/Execution/ForgeObraRuntimeContractTest.php (existing; extend)
- tests/Unit/Ai/Programming/AtlasForgeLiveExecutionServiceTest.php (existing; invert simulate-only production claim)
- tests/Unit/Ai/SelfConstruction/ContinuousRuntime/AtlasSelfConstructionContinuousRuntimeCycleRunnerTest.php (existing; extend)
- tests/Unit/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycleTest.php (existing; extend)
- tests/Feature/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonParityTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosDailyIntentOnlyCommissioningTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosPublicEntryModeParityTest.php (NEW)
- tests/Feature/Ai/Programming/Forge/ForgeObraSupervisorForwardProgressTest.php (NEW)
- tests/Feature/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSchedulerIntegrationTest.php (NEW)
- tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycleTest.php (existing; extend)

P1a exit: `AaeosCycleRuntime` no longer consumes the four Adapters; existing typed dispatchers/native owners are structurally connected; adapters are deleted only after compile/runtime parity; R33–R35/R43/R60/R78/R98/R103 structural portions green; public direct modes pass fresh-process AAEOS ablation; provider/tool/sandbox/mutation remain disabled pending R101.

### P1b — effect authority for code

Objective: prove the section 4 protocol for workspace mutation and canonical code release without creating an AAEOS authority owner. This section is a manifest, not its chronological activation: before P2a+P2b+R102 are green, every P1b path is characterization/refusal-only and must produce zero provider/tool/sandbox/mutation. The binding execution DAG appears after P2f.

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
- app/Services/Engineering/CodeGraph/CodeGraphWorkspaceAccessPolicy.php
- app/Services/Engineering/CodeGraph/CodeGraphWorkspacePrivacy.php

Exit: a caller-authored order cannot cause context, provider, sandbox, tool or mutation until the referenced authoritative decision is reloaded and bound. Dev, Forge and Autônomos supply the real decision event/ref from typed native lineage; `EngineeringModeExecutionOrderFactory` may not synthesize `*-decision-*` fallbacks. CodeGraph principal/workspace/privacy comes only from signed server authority and configured policy; caller `trusted/sovereign` options may intersect/narrow but never append, and foreign/unknown identity yields zero sensitive bytes and zero downstream provider call.

Slice P1b.2 — native code actuator:

- app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php
- app/Services/Ai/SelfConstruction/Governance/AtlasTaskMergeActuator.php
- app/Services/Ai/EngineeringKernel/AuthorizedMergeAction.php
- app/Services/Ai/EngineeringKernel/AuthorizedRevertAction.php
- app/Services/Ai/EngineeringKernel/CanarySettlementRequest.php
- app/Services/Ai/EngineeringKernel/KernelEvidenceAuthority.php
- app/Services/Ai/EngineeringKernel/Adapters/AgentExecutionProviderPortAdapter.php
- app/Services/Ai/Governance/ProviderGovernanceCoverageLedger.php
- app/Services/Ai/Governance/ProviderGovernanceConsult.php
- app/Services/Ai/Governance/GovernanceConsultSkipCounter.php (migrate skip_reason into CoverageLedger, retain historical read, then delete second writer)
- app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionNativeActionExecutor.php
- app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerCommandPlanRunner.php
- app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php
- app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionHermeticSandboxApplyService.php
- app/Services/Ai/Runtime/AiToolProcessRunner.php
- app/Services/Tools/AtlasToolEvidenceStore.php
- app/Models/AtlasToolArtifact.php
- app/Support/AtlasSecurity.php

Exit:

- declared read_only cannot downgrade an observed write;
- changed paths/SHA/tool action determine observed effect;
- authorization is replayed before commit under the relevant lock;
- provider/model/tool argv/cwd/env/timeout and canonical repo-relative paths match the signed server-owned authorization at every boundary;
- the signed basis explicitly binds provider/model-family allowlist, tool id/version and argv schema, cwd/workspace identity, environment-name allowlist with secret refs rather than secret values, timeout, canonical realpaths/target, effect budget and nonce;
- only structured argv reaches a canonical absolute executable whose realpath, version, SHA-256, interpreter/script digest, lockfile and installed dependency/autoload materialization are rechecked immediately before spawn; shell/response-file/option smuggling and loader/control environment variables are denied by default;
- workspace mutation rechecks root device/inode, tenant/workspace id, base SHA, no-follow target ancestry, index/worktree and the authorized base-blob/exact patch under the existing lock; symlink/hardlink swaps, foreign hunks or unattributed dirty entries refuse before staging/effect;
- secret refs are signed names and resolve only at the final process boundary under a mandatory clean-baseline allowlist; raw values never enter authorization, receipts or provider-bound evidence, and rotation/revocation forces fresh resolution;
- `commitAuthority=operator` or a caller actor/observer string never authorizes effect;
- landed and settlement events bind order, decision, lease/fencing, candidate, write-set and outcome;
- mismatch receives zero claim credit and triggers the existing rollback/settlement path;
- external effects remain unsupported and blocked.
- `attachPath` may set `is_redacted=true` only for a canonical in-root, symlink-safe redacted copy whose stored hash/size/preview bind the transformed bytes; raw or untransformable artifacts are refused or explicitly unredacted, non-provider-bound and non-qualifying.
- provider coverage records observer-minted actual-spawn grain and proves set equality across governance consult→spawn→provider result→EngineeringOutcome; provider output cannot self-certify success, and write loss/mismatch becomes `unknown` with zero qualifying credit. Manager resolution is diagnostic, never a spawn denominator.

Slice P1b.3 — AAEOS projection:

- app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
- app/Services/Ai/Aaeos/Control/AaeosAdmissionPolicy.php
- app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php

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
- tests/Feature/Ai/Aaeos/AaeosAuthorityLaunderingTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosProviderToolBoundaryInjectionTest.php (NEW)
- tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerCommandPlanRunnerTest.php (existing; extend)
- tests/Unit/Ai/Brain2/AtlasNativeWorkerCommandPlanRunnerHardeningTest.php (existing; extend)
- tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/AgentExecutionProviderPortServiceTest.php (existing; extend)
- tests/Unit/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionHermeticSandboxApplyServiceTest.php (existing; extend)
- tests/Unit/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionNativeActionExecutorTest.php (existing; extend)
- tests/Unit/Ai/Governance/ProviderGovernanceCoverageLedgerTest.php (existing; extend)
- tests/Unit/Ai/Governance/ProviderGovernanceConsultTest.php (existing; extend)
- tests/Unit/Ai/Cognition/AcosProgram/Multx05GovernanceConsultSkipCounterTest.php (existing; extend)
- tests/Unit/CodeGraph/CodeGraphWorkspaceAccessPolicyTest.php (existing; extend caller-widening negatives)
- tests/Feature/Tools/AtlasToolEvidenceStoreArtifactRedactionTest.php (NEW)
- tests/Feature/Ai/Runtime/AiToolProcessRunnerAuthorityBoundaryTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosProviderSpawnSetEqualityTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosFilesystemOperationIdentityTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosExecutableMaterializationAttestationTest.php (NEW)

No AaeosActionEffectClassifier, SovereigntyPort, generic action registry or second ledger may be created.

### P2 — shared authority, evidence and durability

P2a — evidence/outcome integrity:

- Production paths:
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php
  - app/Models/AtlasLedgerEvent.php
  - app/Services/Ai/Kernel/Evidence/LedgerEventType.php
  - app/Services/Ai/EngineeringKernel/EngineeringOutcome.php
  - config/database.php (guarded P2/P4 producer and read-only verifier connections only)
  - app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
  - database/migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php (NEW; must run before every dependent P2 migration)
- harden AtlasEvidenceLedger canonical event/envelope/chain verification;
- add event-type + half-open UTC window query and cycle-id idempotency/divergence semantics;
- implement the non-circular receipt-core/event binding and ordered journey manifest from R75/R80;
- assign one transactional chain position/head under concurrent first writers and recompute event hash, predecessor and causal DAG rather than trusting caller time;
- emit `atlas.ledger_event.v2` for new rows with a canonical full-envelope hash basis covering tenant/principal/receipt/trace/emitter/schema/time/position/predecessor/payload; v1 bytes remain dual-readable `legacy_unverified` and are never updated, rehashed or resigned;
- verify that exact envelope against an independently authenticated tenant cutoff/head/position/count and anchor; empty, prefix/suffix-truncated, forked, spliced, stale, missing-anchor or alternative predecessor-omitting histories fail rather than becoming a shorter valid chain;
- serialize even the empty-chain first append with an existing PostgreSQL transaction/advisory lock and unique `(tenant_id, chain_key_hash, chain_position)`; install a PostgreSQL runtime-role guard that refuses `UPDATE`/`DELETE` after the migration/backfill window, without a head table or outbox;
- bind every EngineeringOutcome status to authority;
- expand `atlas.engineering_outcome.v3` before any adverse writer cutover; require stable `failure_reason_code` plus precise `failure_reason` for adverse terminal outcomes; v2 bytes remain historical/read-only and are never inferred/backfilled;
- derive elapsed/operator effort from durable server event timestamps and capture state: missing/incomplete is `null` + `unknown`, actual measured zero alone is `0`, and ITT/presenters retain unknowns plus coverage instead of imputing instant/free work;
- complete the EngineeringOutcome producer/consumer census, dual-read/shadow/canary/rollback/contract rollout before contracting v2 writers;
- preserve precise causes through Forge and AAEOS.
- require authenticated tenant at every append and read; constrain predecessor, eventById, scope, correlation, latest, outcome lookup, replay and dedupe by tenant, and prove identical A/B identifiers cannot cross-link or satisfy proof;
- Test paths:
  - tests/Unit/Ai/Kernel/EvidenceLedgerTest.php (existing; extend)
  - tests/Unit/Ai/EngineeringKernel/TypedEngineeringContractTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosJourneyManifestIntegrityTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosFreshProcessJourneyReplayTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosPostgresDurabilityContractTest.php (NEW; guarded real PostgreSQL profile; missing required `ATLAS_TEST_PG_*` fails the phase instead of skipping)
  - tests/Feature/Ai/Aaeos/AaeosCanonicalP2SchemaUpgradeTest.php (NEW; fresh + pre-P2 snapshot + mixed-version + rollback matrix)
  - tests/Feature/Ai/Aaeos/AaeosLedgerTenantIsolationTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosEngineeringOutcomeSchemaRolloutTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosJourneyStateMachinePropertyTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosJourneyManifestDagPropertyTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosLedgerTruncationCutoffTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosPostgresRestoreIdentityTest.php (NEW)
  - tests/Unit/Ai/EngineeringKernel/EngineeringOutcomeAdverseReasonTest.php (NEW)

P2a.1 runs Ledger v2/tenant/role expansion and P2a.2 runs EngineeringOutcome v3 expand→dual-read→shadow before its canary writer. The required PostgreSQL contract command uses `ATLAS_ALLOW_LIVE_DB_TESTS=1` plus `ATLAS_TEST_PG_HOST`, `ATLAS_TEST_PG_PORT`, `ATLAS_TEST_PG_DATABASE`, `ATLAS_TEST_PG_USERNAME`, `ATLAS_TEST_PG_PASSWORD`; database must match the guarded `atlas_test_` prefix. Missing variables, SQLite, skipped tests, zero tests or a non-PostgreSQL backend fails the phase. Setup/migrate/test/teardown commands and queried backend/user/application identities are recorded in PHASE-P2A1; no secret value is recorded. The contract asserts `session_user`, `current_user`, active role, application/backend identity and effective grants, forbids `SET ROLE`, ownership, superuser and `BYPASSRLS`, then performs a real dump→fresh-restore→replay drill through a separate ephemeral setup role; the verifier is never broadened and stale, truncated or wrong-identity restoration refuses.

P2b — independence and sovereignty receipts:

- Production paths:
  - config/atlas.php (Decision writer rollout enum only)
  - config/atlas_code_signing.php (trusted issuer ids/current+prior public keys/status; never a second authority store)
  - app/Services/Ai/AtlasDecideService.php
  - app/Services/Ai/AtlasDecide/AiDecisionReceiptRefreshService.php
  - app/Services/Ai/ValueObjects/OperationalDecision.php
  - app/Services/Ai/Kernel/Decision/DecisionReceipt.php
  - app/Services/Ai/Kernel/Decision/DecisionReceiptHash.php
  - app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php
  - app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php
  - app/Services/Ai/Kernel/Evidence/LedgerProjectionRegistry.php
  - app/Console/Commands/AtlasCliCockpitCommand.php
  - app/Services/Ai/Memory/AtlasMemoryLearningPromotionService.php
  - app/Services/Ai/Compounding/AtlasHeldEvidenceMinerService.php
  - app/Services/Ai/AtlasOpenBrainContextPackService.php
  - app/Services/Ai/Context/AiConversationContextBuilder.php
  - app/Services/Ai/Context/AiContextPackBuilder.php
  - app/Services/Ai/Kernel/Decision/Reversibility/ReceiptReversibilityConsentGate.php
  - app/Services/AtlasCode/HumanDecisionReceiptSigner.php
  - app/Services/Ai/EngineeringKernel/Spec/SpecSourceIndependence.php
  - app/Services/Ai/EngineeringKernel/Spec/SelfComposedWitnessResolver.php
  - app/Services/Ai/EngineeringKernel/Spec/AdvisorWitnessResolver.php
  - app/Services/Ai/EngineeringKernel/Spec/SovereignSpecFloor.php
  - app/Services/Ai/EngineeringKernel/VerificationCourtAcceptanceGate.php
  - app/Services/Ai/EngineeringKernel/RoleEvidenceReceipt.php
  - app/Services/Ai/Programming/AtlasDev/Schemas/Components/ProviderLock.php
  - app/Services/Ai/AiWorker.php
  - app/Http/Resources/AiDecisionResource.php
  - app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php
  - app/Services/Ai/ExecutionAuthority/AwisExecutionGatePort.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceExecutionGateService.php
  - app/Services/Ai/EngineeringKernel/Coverage/EngineeringExecutionSurfaceRegistry.php
  - app/Services/Ai/SelfConstruction/AtlasTaskServingService.php
- use authenticated tenant_id, principal_id, mode, capability_id/version, issuer_key_id and producer path from trusted issuance; missing or placeholder identities such as `default`/`system` refuse every authority-bearing boundary;
- prevent the same capability from minting author + judge + governor;
- reuse/promote the existing Ed25519 signer/verifier into the Decision owner;
- sign every authority-bearing payload field listed in §5.1 and fail closed when receipt/revocation head is absent, stale or mismatched;
- resolve issuer_key_id through the trusted keyring; bind model-family from trusted issuance; enforce the Decision v3 dual-read/canary/contract rollout and mixed-worker block-before-effect matrix;
- `AtlasDecideService` and the refresh writer issue canonical `receipt_v3`; immutable `receipt_v2` remains a historical read alias only. Runtime guard dispatches by schema and boundary (`read_projection|provider|tool|sandbox|effect`): missing/unknown/newer/v2 at a mutative boundary fails before work;
- writer rollout is `legacy|shadow|canary|v3`, starts legacy, vetoes on any shadow contradiction, rolls back the writer without changing persisted v2/v3 bytes, and contracts only after old-worker drain;
- child authority equals the intersection of the current parent ceiling and server-resolved action; it binds tenant/mode/deputy/owner/root/target/action/nonce, validates the complete acyclic signed ancestor chain at mint and immediately pre-effect, atomically consumes the parent budget/nonce across siblings, and cascades any ancestor revoke/supersede/expire;
- AWIS receives the signed native mode unchanged; `dev|forge|autonomos` mutative surfaces are explicit, unknown is fail-closed, and TaskServing cannot present Autônomos as Dev or downgrade it to conversation;
- wire the existing ReceiptReversibilityConsentGate only for true H3/unrecoverable effects;
- derive scoped ephemeral capabilities mechanically inside an active authority ceiling;
- make instruction provenance an authority field with signed precedence: system/operator/Decision instructions are distinct from conversation, compaction, retrieval, memory, provider and tool/artifact data. Unknown/foreign sources refuse provider/effect, and memory promotion/recall preserves origin/content hash, signer/reviewer, supersession and `instruction_allowed=false` instead of upgrading quoted or echoed data.

P2b is five serial checkpoints over the same owners: EXPAND, SHADOW, CANARY, CUTOVER and CONTRACT. Each has its exact receipt and scoped commit. P1b.1 may begin only after CUTOVER is green and all active mutative consumers understand/refuse v3 correctly; CONTRACT waits for measured old-worker drain and can occur later, but is mandatory before P4. Rollback changes only writer selection and never rewrites authority bytes.
- Test paths:
  - tests/Unit/Ai/Kernel/DecisionReceiptIssuerTest.php (existing; extend)
  - tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php (existing; extend)
  - tests/Unit/Ai/Kernel/Decision/Reversibility/ReceiptReversibilityConsentGateTest.php (existing; extend)
  - tests/Unit/AtlasCode/HumanDecisionReceiptSignerTest.php (existing; extend)
  - tests/Unit/Ai/EngineeringKernel/Spec/SpecAdversaryContractTest.php (existing; extend)
  - tests/Unit/Ai/EngineeringKernel/Spec/SovereignSpecFloorTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosStandingMandateAuthorityTest.php (NEW)
  - tests/Feature/Architecture/DecisionReceiptDeterminismTest.php (existing; extend)
  - tests/Feature/Ai/EngineeringKernel/PreLandSeamTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosSeparationOfDutiesTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosReceiptConsumerCensusTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosMixedVersionWorkerCompatibilityTest.php (NEW)
  - tests/Feature/Ai/AtlasDecide/AtlasDecideGatewayDecisionReceiptTest.php (existing; extend)
  - tests/Unit/Ai/Brain2/AiDecisionReceiptRefreshServiceHardeningTest.php (existing; extend)
  - tests/Unit/Ai/Programming/AtlasForgeRuntimeDispatchServiceTest.php (existing; extend child-intersection/cascade cases)
  - tests/Feature/Ai/SelfConstruction/AutonomosAwisGateTest.php (existing; extend)
  - tests/Feature/Ai/EngineeringKernel/MutativeSurfaceAwisInvariantTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosAwisModeIdentityParityTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosDecisionReceiptSchemaRolloutTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosAuthorityAncestorChainTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosInstructionProvenanceTest.php (NEW)

P2c — unattended durability:

- Production paths:
  - app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketBuilder.php
  - app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneClaimLeaseRepository.php
  - app/Services/Ai/SelfConstruction/AtlasTaskServingService.php
  - app/Services/Ai/SelfConstruction/AtlasTaskServingStack.php
  - app/Services/Ai/SelfConstruction/AtlasTaskServingSwitch.php
  - app/Services/Ai/SelfConstruction/TaskServing/AtlasTaskBrainReplenisher.php
  - app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemon.php (created in P1a)
  - app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSchedulerManifest.php
  - routes/console.php
  - app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php
  - app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerOutcomeMapper.php
  - app/Services/Ai/Programming/AtlasDev/Execution/DevPlanRunFacade.php
  - app/Services/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionService.php
  - app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopExecutor.php
  - app/Services/Ai/Programming/AtlasDev/Repair/RepairOrchestrator.php
  - app/Services/Ai/Programming/AtlasDev/Repair/DevRepairLoopService.php (signal compatibility only; never a second executor; delete/absorb after census)
  - app/Services/Ai/Programming/AtlasDev/Persistence/ReceiptStorage.php
  - app/Services/Ai/Programming/DurableExecution/DurableExecutionPreflight.php
  - app/Http/Controllers/AtlasDev/Support/KernelRunExecutor.php
  - app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php
  - app/Services/Ai/Programming/Forge/Execution/ForgeObraSupervisor.php
  - app/Services/Ai/Programming/Forge/Execution/ForgeTickBudget.php
  - app/Services/Ai/Programming/Forge/ForgeMultiAgentSchedulerService.php
  - app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php
  - app/Services/Ai/Programming/Forge/ForgeScopeReservationService.php
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
  - app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php
  - database/migrations/2026_07_23_231000_harden_ai_forge_execution_atomicity.php (NEW; after Ledger/Decision expansion)
- add a RED stale-resume/revocation test first;
- bind the exact authority ref/hash/revision from native origination through packet and lease without remint;
- revalidate at origination, claim, renewal and pre-effect boundary;
- normalize worker retry/repair signals into a TaskServing-owned transition; unknown outcome remains invalid and never silently gives back;
- make Dev's existing RepairOrchestrator the retry owner through the productive KernelRunExecutor/AtlasDevExecutionService chain; persist prepared/observed attempts in existing ReceiptStorage; SeniorEngineerLoopExecutor owns the journey;
- eliminate routine `human_action_required`, `needs_review` and operator-next exits: 0..N technical failures route from the exact failure capsule through RepairOrchestrator, back through the same Court, under one DevIntent/ConfirmedDevRun + Decision v3 revision/root/budget until accepted or precisely exhausted;
- derive remaining retry/tick budget from the canonical signed journey manifest; caller snapshots, new tick ids, handoffs or successor episodes cannot reset consumed burn, and only an authenticated sovereign authority event may add incremental budget;
- atomically reserve and reconcile cumulative root tokens, cost, wall/context and active-spawn capacity under the existing lease/effect lock across siblings, retries, handoffs and restarts; governance unavailable/error means `unknown` and zero mutative/qualifying spawn, while exhaustion yields one precise `resource_budget_unavailable|resource_budget_exhausted` adverse terminal rather than a retry storm;
- default to one candidate/worker; fan-out requires server-observed disjoint/decomposable width plus signed budget headroom, binds every child to parent consumed burn, and deduplicates semantically equivalent candidate hashes before provider use;
- after a Dev root or sealed Forge Obra is acknowledged, client/terminal disconnect is not cancellation: fresh-process recovery continues to one terminal result without `--yes`, ship/reject, status answer or `atlas continue`; later attach is read-only audit;
- reconcile commit→queue→lease/report and lease-file→derived-registry crash splits before accepting another claim/effect;
- resume the single running Forge cycle and deterministic provider execution id before selecting another packet;
- make the existing Forge supervisor drive sealed work through tick→Court→repair/re-review→advanceMilestone→`completeObra`; its signed tick budget applies across restart, and it never asks the operator to continue;
- resolve the Forge certification ref through Ledger and bind it to this commissioning/root, complete dependency DAG, terminal packet EngineeringOutcomes, landed SHA and canary; generic, foreign, stale or spliced `kind=certification` never completes the Obra;
- make the existing scheduler manifest consumed by the existing scheduled surface; cold start derives facts from durable native owners, replenishes a bounded empty queue through AtlasTaskBrainReplenisher, resumes one lease/journey after kill, and never requires an operator-supplied `--facts` file;
- for Autônomos, persist canonical land + independent EngineeringOutcome + release/canary refs before TaskServing receives exactly one success report/resolve. Crash after land reconciles the same report; report-before-proof, missing receipt or failed Court routes repair/give-back and never resolves;
- harden Forge cycle position and terminal transitions with unique constraint + transaction/lock/CAS;
- prove one terminal winner and divergent replay conflict.
- Test paths:
  - tests/Unit/Ai/SelfConstruction/AtlasTaskServingServiceTest.php (existing; extend)
  - tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycleTest.php (existing; extend)
  - tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerOutcomeMapperTest.php (existing; extend)
  - tests/Unit/Ai/Programming/AtlasDev/Repair/RepairOrchestratorTest.php (existing; extend)
  - tests/Feature/Ai/Programming/AtlasDev/Repair/DevRepairLoopServiceTest.php (existing; extend)
  - tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php (existing; extend)
  - tests/Feature/Ai/Programming/AtlasDev/SeniorEngineerLoopCrashResumeTest.php (NEW)
  - tests/Feature/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleServiceTest.php (existing; extend)
  - tests/Feature/Ai/Programming/Forge/ForgeLongHorizonStateServiceTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosAuthorityLineageResumeTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosNativeRepairContinuationTest.php (NEW)
  - tests/Feature/Ai/AtlasTaskServingResolveLoopE2ETest.php (existing; extend)
  - tests/Feature/Ai/AtlasTaskServingLeaseOwnershipTest.php (existing; extend)
  - tests/Unit/Ai/SelfConstruction/AgentControlPlaneClaimLeaseRegistryRebuildTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosTaskLeaseCrashRecoveryTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosEffectProtocolCrashCutpointModelTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosAutonomosLandBeforeReportCrashTest.php (NEW)
  - tests/Feature/Ai/Programming/Forge/ForgeObraCertificationBindingTest.php (NEW)
  - tests/Feature/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSchedulerIntegrationTest.php (created in P1a; extend restart/empty-queue proof)
  - tests/Feature/Ai/Aaeos/AaeosPublicClientDetachContinuationTest.php (NEW; direct+routed Dev/Forge)
  - tests/Feature/Ai/Aaeos/AaeosSignedFanoutBudgetInvariantTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosRootResourceBudgetConcurrencyTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosMultiEngineDirtyMainIsolationTest.php (NEW)
  - tests/Feature/Ai/Aaeos/ForgeWorkPacketExecutionCycleMigrationTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosLedgerJourneyMigrationTest.php (NEW)

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
  - app/Services/Ai/SelfConstruction/AtlasTaskServingService.php
  - app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php
- persist the reserved-effect event and continuation ref; project only through existing attention/cockpit readers;
- use one versioned H1–H7 Ledger payload binding reserved class, task causation, authenticated issuer/actor, action hash, authority gap, affected scope/effect, continuation ref, decision ref/hash and timestamps. DecisionDrafted/Escalation/attention/learning proposals are system projections, never operator actions or authority;
- replay a later valid DecisionIssued before continuation; unrelated work makes observed claim→execute→settle progress rather than merely remaining runnable.
- H4 operational learning remains propose-only/reversible through the existing promotion/miner/context-pack owners; a scope-expanding promotion becomes the asynchronous reserved continuation.
- Test path: tests/Feature/Ai/Aaeos/AaeosSovereignContinuationTest.php (NEW; two-work-item A/B proof: B settles while A is reserved, then A resumes exactly once).
- Additional test path: tests/Feature/Ai/Aaeos/AaeosCockpitAttentionPartitionTest.php (NEW).
- Additional test paths: tests/Feature/Ai/Compounding/AtlasHeldEvidenceMinerServiceTest.php and tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php (existing; extend).

P2f — operator-experience truth and removal of technical human loops:

- Production paths:
  - bin/atlas
  - app/Services/Engineering/EngineeringRunOperatorActionService.php
  - app/Services/Ai/Product/ProductIntentClarificationContract.php
  - app/Services/Ai/Product/ProductIntentCourt.php
  - app/Services/Ai/EngineeringKernel/Spec/AtlasSpecGateAdapter.php
  - app/Services/Ai/Programming/AtlasDev/PlanVisible/AtlasDevPlanApprovalGate.php
  - app/Services/Ai/Programming/AtlasDev/PlanVisible/AtlasDevProviderExecutionBlock.php
  - app/Console/Commands/AtlasCliDevPlanCommand.php
  - app/Console/Commands/AtlasCliDevCommand.php
  - app/Services/Ai/Cli/AtlasCliDevEfficientHandler.php
  - app/Console/Commands/AtlasCliContinueCommand.php
  - app/Services/Ai/Cli/AtlasCliSessionService.php
  - app/Console/Commands/AtlasAaeosRunCommand.php
  - app/Console/Commands/AtlasCliHelpCommand.php
  - app/Console/Commands/AtlasApiDescribeCommand.php
  - app/Services/Ai/Programming/Forge/ForgeContinuationPackBuilder.php
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleCanon.php
  - app/Services/Ai/Programming/Forge/Intelligence/ForgeFailureIntelligenceService.php
  - app/Services/Ai/Programming/Forge/Intelligence/ForgeObraScopeGuardService.php
  - app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketBuilder.php
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
- instrument the complete native ingress/request census with authenticated producer seals; classify Atlas-requested work separately from elective operator control;
- ProductIntent clarification uses a closed fail-unknown type enum bound to the exact Court objection and commissioning episode; implementation/provider/test/review/repair/budget/release/effect questions are invalid, never relabeled as generic clarification;
- consume Dev's initial authority once; PlanVisible remains optional audit and a replan never asks ship/reject again;
- plain `atlas dev` enters its live intent session; `atlas dev <intent>` starts productive Dev from the initial authority without `--yes`; `--plan-only` is elective audit. `needs_review`, `escalate_forge` and crash resume continue agentically; `atlas continue` remains only for operator-initiated pause/cancel recovery;
- convert every non-H1–H7 Forge human hint/review into native Court/repair/continuation; commissioning is the last routine operator boundary;
- reject Autônomos credit for operator_intake/default operator ids, reviewed human exceptions or a task-causal sovereign decision;
- operator `accept/reject` cannot set an engineering run to passed/failed; technical terminal truth comes only from Court + EngineeringOutcome. Retain cancel and true H1–H7 authority actions, with historical legacy decisions read-only;
- derive OneShot as a monotonic fold over operator events, metamorphically invariant under 0/1/100 internal attempts.
- make daily commissioning intent-first and productive by default under signed caps; provider, cadence, batch width, scope and internal execution mechanics are server-owned, never operator prerequisites.
- productive missing intent returns non-zero `intent_required` and creates no root; `atlas help`, per-command help, machine API description/completion and deprecated-flag migration guidance all conform to the same one-intent productive contract and expose no technical next step.
- Test paths:
  - tests/Unit/Ai/Product/ProductIntentCourtTest.php (existing; extend)
  - tests/Unit/Ai/Programming/AtlasDev/PlanVisible/AtlasDevPlanApprovalGateTest.php (existing; invert repeated approval)
  - tests/Feature/Ai/Programming/AtlasDev/AtlasDevProviderExecutionBlockTest.php (existing; extend)
  - tests/Feature/Ai/Aaeos/AaeosOperatorIngressCaptureCoverageTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosOneShotOperatorEvidenceTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosModeHumanLoopAbsenceTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosDailyIntentOnlyCommissioningTest.php (NEW)
  - tests/Feature/Ai/Programming/AtlasDev/AtlasCliDevDefaultPathTest.php (existing; extend real invocation)
  - tests/Feature/Ai/Programming/AtlasDev/Cli/AtlasCliDevEfficientCommandTest.php (existing; invert `--yes`/operator-next semantics)
  - tests/Feature/AtlasCliContinueCommandTest.php (existing; elective-only continuation)
  - tests/Feature/Engineering/EngineeringRunOperatorActionServiceTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosTechnicalOneShotReachabilityCensusTest.php (NEW; reports exact reachable rename/delete amendment; no bulk edit)
  - tests/Feature/Ai/Aaeos/AaeosPublicHelpAndCompletionParityTest.php (NEW)
  - tests/Feature/Ai/Aaeos/AaeosClarificationClosedTaxonomyTest.php (NEW)

Binding execution DAG: P0 → P1a structural parity/refusal-only → P2a.1 Ledger v2 tenant-safe expand + PostgreSQL roles → P2a.2 EngineeringOutcome v3 expand/dual-read/shadow → P2b EXPAND→SHADOW→CANARY→CUTOVER for Decision v3/keyring/revocation + R102 AWIS parity → P1b.1 CodeGraph/pre-provider authoritative replay → P2c native lineage/crash/forward-progress durability → P1b.2 tool-artifact redaction + native ACT/settlement → P1b.3 AAEOS projection → P2d Spine settlement refs → P2e async continuation (after P2c, so B can actually settle) → P2f complete operator census/technical-loop removal → P2b CONTRACT after old-worker drain → P3 consumer-proven deletion → P4 exact producer-role journeys → separate verifier-role certification. Slices sharing AtlasEvidenceLedger or another owner never run concurrently. Each checkpoint is a separate scoped commit and receipt; no context/provider/tool/sandbox/effect path activates before its authority/schema/role gate is green.

### P3 — deletion and canonical alignment

Production/document paths:

- composer.json
- config/atlas.php (active `atlas:loop:*` emitters and compatibility only)
- routes/console.php
- app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
- app/Services/Ai/Aaeos/Control/AaeosTriHygieneScorecardProjector.php (delete)
- app/Console/Commands/AtlasTriHygieneScorecardCommand.php (delete)
- app/Services/Ai/Compat/AaeosHygieneLegacyAliases.php
- app/Services/Ai/Aaeos/Control/AaeosModeToDualCoreRoute.php
- app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
- app/Services/Ai/Aaeos/Control/AaeosOrgStateProjector.php (delete after zero-reader census)
- app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php (delete after zero-reader census)
- app/Services/Ai/SelfConstruction/Maestro/Cost/AtlasMaestroCostAggregator.php
- app/Console/Commands/AtlasAaeosCycleCommand.php (delete or thin deprecated alias after runtime census)
- app/Console/Commands/AtlasAaeosRunCommand.php (remove productive technical flags after native parity)
- app/Console/Commands/AtlasCliCockpitCommand.php
- app/Console/Commands/AtlasTaskLandingReviewPublishCommand.php
- app/Services/Ai/Mobile/InboxActionRegistry.php
- app/Services/Ai/SelfConstruction/AtlasTaskLandingReviewPublisher.php
- app/Console/Commands/AtlasTaskReviewDecideCommand.php
- app/Console/Commands/AtlasReviewDeepCommand.php
- app/Services/Engineering/EngineeringReviewService.php
- app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php (retain; deletion not authorized until R103 census amendment)
- app/Http/Controllers/AtlasDev/Support/PipelineRun/BestOfNSection.php (retain with legacy executor pending R103)
- app/Http/Controllers/AtlasDev/Support/PipelineRun/DeterministicPatchSection.php (retain pending R103)
- app/Http/Controllers/AtlasDev/Support/PipelineRun/GovernanceSection.php (retain pending R103)
- app/Http/Controllers/AtlasDev/Support/PipelineRun/ProviderExecutionSection.php (retain pending R103)
- app/Http/Controllers/AtlasDev/Support/PipelineRun/ProviderResultSupport.php (retain pending R103)
- app/Http/Controllers/AtlasDev/Support/PipelineRun/RepairProjectionSection.php (retain pending R103)
- app/Http/Controllers/AtlasDev/Support/PipelineRun/ResolverSupport.php (retain pending R103)
- app/Http/Controllers/AtlasDev/Support/PipelineRun/WorkspaceGitSupport.php (retain pending R103)
- app/Services/Ai/Aaeos/README.md
- app/Console/Commands/AtlasAaeosRouterCommand.php
- app/Console/Commands/AtlasCliHelpCommand.php
- app/Console/Commands/AtlasApiDescribeCommand.php
- app/Services/Ai/Programming/AtlasWeeklyEngineeringReportService.php
- app/Services/Ai/Programming/AtlasFableFinalReportService.php
- app/Services/Ai/Programming/AtlasFableFinalCaptureService.php
- app/Services/Ai/CODEMAP.md
- docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
- docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
- docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
- docs/engineering-knowledge-base/atlas-terminal-first-focus.md
- docs/engineering-knowledge-base/atlas-cli-daily-map.md
- docs/loop-soak-run-profile.md (delete/tombstone active instructions after backlink census)
- docs/loop-soak-runbook.md (delete/tombstone active instructions after backlink census)
- docs/loop-task-class-discovery.md (delete/tombstone active instructions after backlink census)

Required behavior:

- delete the two LOC/file-existence hygiene graders; retain only live scoring cores proven by the P1 architecture guard;
- delete the self-declared OrgState projector and control-plane LearningCandidate recorder only after standing reader/event census proves zero; EngineeringOutcome/Ledger and native learning owners retain truth;
- migrate `AaeosCycleRuntime` and every command consumer off the four adapters before their P1a deletion; compilation plus fresh-process dispatch parity is required, not a comment-level census;
- after native parity, remove synthetic dispatcher packs/worklists; converge `atlas:aaeos:cycle` onto `atlas:aaeos:run` as a temporary logic-free alias only when usage telemetry requires it;
- after the same parity, remove productive `--live`, `--execute-provider`, `--run-worker-once`, `--max-seeds` and `--scope` choreography from `atlas:aaeos:run`; preserve explicit `--dry-run` as elective audit and reject removed flags with migration guidance;
- P3a performs the complete R103 production/config/test/reflection/docs consumer census for PipelineRunExecutor. The family is retained in v12. Only after the MASTER is amended with every consumer path may P3b port those consumers to KernelRunExecutor/AtlasDevExecutionService and exact-delete the family; four characterization tests are not enough;
- keep compatibility aliases unless classmap/runtime parity proves a named alias dead; no bulk alias deletion is authorized;
- for `AaeosHygieneLegacyAliases`, migrate the eager Composer files entry and CODEMAP, run `composer dump-autoload`, then prove cold-process negative resolution before deletion; historical FQCN evidence remains bytes-preserved;
- 17 phases and `human_review` are historical/governance material, not the daily operate path;
- replace invented plan_seal/session labels with native commissioning/authority hashes and canonical enums;
- terminal-first: the existing `atlas:cli:cockpit` consumes read-only proof/economy refs through its current projection owner; no new shell or authority;
- public help, daily map, command descriptions/completion and machine API catalog conform to the same public-entry matrix; productive entries cannot be absent, null-versioned or silently skipped by `--check`;
- landing review/cockpit becomes optional read-only inspection: stop scheduled creation of required approve/reject items, migrate Inbox action handlers, and ensure human accept/reject cannot set engineering truth. Court rejection returns to agents; no qualified landing waits for an operator verdict. The cockpit partitions technical history from actionable H1–H7 attention and shows Court verdict, findings, executed-command receipts, evidence refs, rejection→repair chain, artifact hash and freshness; it emits no technical `next_commands`;
- comparative Rivals hardening remains HORIZON R50 and is not a P3 change; the presenter only shows a pre-existing current valid claim or `not_claimed`;
- active docs/config no longer teach dead `atlas:loop:*`; historical evidence is preserved;
- Quarantine path/import count remains zero.

Test paths:

- tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php (existing; extend with field-reader census)
- tests/Feature/Ai/Aaeos/AaeosAeosPartitionGuardTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosEconomyIntentToTreatProjectionTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosCanonicalDocsAndAliasTest.php (NEW)
- tests/Feature/Ai/AtlasCliCockpitCommandTest.php (existing; extend)
- tests/Feature/Ai/TaskLandingReviewCockpitTest.php (existing; invert mandatory approval semantics)
- tests/Feature/Ai/AtlasCliInboxOperatorActionsTest.php (existing; extend)
- tests/Feature/Ai/Aaeos/AaeosOneShotCockpitNonBlockingTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosVerificationCockpitProjectionTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosLegacyDispatchPackConsumerCensusTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosSelfDeclaredOrgStateAbsenceTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosDeadLoopSurfaceAbsenceTest.php (NEW)
- tests/Feature/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHttpSmokeTest.php (port then delete/rename)
- tests/Feature/Ai/Programming/AtlasDev/PipelineRunExecutorAwisGateTest.php (port then delete/rename)
- tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHermesProviderTest.php (port then delete/rename)
- tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php (port then delete/rename)

P3 does not broaden runtime capability.

### P4 — real-journey gauntlet, OneShot UX proof and freeze

Preconditions: P0–P3 receipts green; all automated/source portions of R33/R34/R51–R103 green; ops preflight explicit. R5/R25/R36/R62/R75/R76/R84/R103 require the live P4 journeys themselves and are therefore P4 exit conditions, not circular preconditions.

P4 gives Atlas the time required to reach a governed result. It does not impose a one-pass or speed target. Every rejection loops internally to the correct planning, implementation, verification or repair stage until accepted or terminated with an explicit mandate/budget/no-progress cause. A safely blocked/failed/cancelled journey is honest evidence, but it does not qualify that mode as REAL_OPERATION and keeps P4 incomplete.

Dev proof:

- one native DevIntent/ConfirmedDevRun root and authority lineage bound to every plan, replan, run and repair attempt;
- SeniorEngineerLoopExecutor journey → DevPlanRunFacade attempts → AtlasDevExecutionService → ExecutionOrder → Kernel → EngineeringOutcome;
- zero or more natural rejection/repair cycles are allowed; a separate fault-injected journey proves rejection→agentic repair/replan→re-review under the same root without requiring the qualifying journey to manufacture failure;
- accepted artifact and canonical journey fresh-read.

Forge proof:

- strict ForgeCommissioning → commissioned Obra → full DAG/milestones → `ForgeLongHorizonStateService::completeObra`;
- a separate fault-injected Obra proves rejected cycle→repair child/attempt→re-review→blocker resolution→continued Obra; the qualifying natural Obra may have zero or more repairs;
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
- a separate fault-injected task proves rejection/repair-or-give-back/new-claim/reverification; the qualifying natural task may have zero or more repairs;
- scoped canonical land;
- resolved state and Ledger readback;
- Kernel ref only if the packet genuinely carried an order.
- zero per-task operator requests, actions, clarifications or synchronous waits from seed to terminal.

Every mode requires a completed REAL_OPERATION journey with:

- journey root event/ref/hash and a correlation id derived from native intent/commissioning/mandate;
- `journey_terminal_status=real_operation_completed`; blocked_before_effect, failed_after_effect, authority_exhausted and cancelled do not qualify;
- operator request/action refs with canonical types and hashes, capture_coverage=1.0 and derived OneShot counters; self-reported zeros are ignored;
- versioned native producer census hash plus set-equality proof over every authenticated operator ingress and Atlas→operator request egress reachable in that mode; missing/unsealed producer makes OneShot unknown;
- ordered attempt refs `{kind,native_id,parent_ref,event_id,event_hash,outcome,failure_reason}`; engineering/review/repair counters are folds of this sequence and never failure by themselves;
- receipt_core_hash and ordered journey_manifest_hash;
- authoritative decision/authorization ref and hash;
- observed effect event ref and hash;
- downstream artifact ref, SHA-256 and bytes where applicable;
- fresh-process read from a new app/container and DB connection with no prior object/cache; recompute receipt core, event chain/causation/dedupe, journey manifest and terminal artifact bytes;
- exact failure_reason for any adverse result.

P4 execution/test paths:

- bin/atlas (public direct Dev/Forge producer ingress)
- app/Console/Commands/AtlasCliDevCommand.php
- app/Services/Ai/Cli/AtlasCliDevEfficientHandler.php
- app/Console/Commands/AtlasAaeosRunCommand.php (public routed producer ingress)
- app/Console/Commands/AtlasDevSeniorLoopRunCommand.php (real producer; existing)
- app/Console/Commands/AtlasForgeLiveExecuteCommand.php (real producer after P1/P2; existing)
- app/Console/Commands/AtlasSelfConstructionRuntimeDaemonCommand.php (real producer; existing)
- app/Console/Commands/AtlasAaeosCertifyCommand.php (independent verifier; existing)
- app/Console/Commands/AtlasCliCockpitCommand.php (fresh terminal readback; existing)
- tests/Feature/Ai/Aaeos/AaeosDevRealJourneyTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosForgeRealObraJourneyTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosAutonomosRealJourneyTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosOneShotOperatorEvidenceTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosFreshProcessJourneyReplayTest.php (created in P2; run live profile)
- tests/Feature/Ai/Aaeos/AaeosPublicOperatorSurfaceRealJourneyTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosP4CertifierReadOnlyBoundaryTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosP4RuntimeProfileRejectionTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosP4NonTerminalExitZeroRejectionTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosP4ProviderToolProvenanceTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosP4IndependentVerifierPrivilegeTest.php (NEW)
- tests/Feature/Ai/Aaeos/AaeosCertificationInvalidatorMatrixTest.php (created for §9.3; run live profile)
- docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P4-DEV.json (generated)
- docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P4-FORGE.json (generated)
- docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P4-AUTONOMOS.json (generated)
- docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P4-FREEZE.json (generated)

The three producer journeys run with `APP_ENV` outside testing, a controlled disposable real workspace, durable PostgreSQL, at least one real governed provider spawn causally bound to each root, the real tool/effect path, and canonical Git/artifact observation. Dev is entered through `bin/atlas dev <intent>`, Forge through `bin/atlas forge <intent>`, the routed matrix through `atlas:aaeos:run <intent>`, and Autônomos through the armed scheduler/Brain/daemon without harness-supplied `--facts`. Internal native commands remain observable producer seams but cannot substitute for the public-entry proof. All use strict terminal contracts; exit 0 alone never qualifies.

In the producer profile, plan-only, blocked, partial, unknown, failed, missing-failure-reason and unresolved states exit non-zero intrinsically; no `--strict`/operator flag can turn an invalid run into success. `atlas:aaeos:certify --profile=p4 --journey=<ref> --cutoff=<ledger-head> --evidence-dir=<dir> --json` is the existing read-only verifier target: it never invokes a runtime, provider or effect, never appends Ledger, and fails on absent/nonterminal/stale/same-process evidence.

PHPUnit, SQLite `:memory:`, fixtures, fake providers and simulate-only services may test readers/invalidators but may not create, copy or promote qualifying receipts. A second OS process with a new boot identity, code SHA and read-only PostgreSQL credential runs a strictly read-only certifier against a terminal cutoff/snapshot; it has no provider, tool, workspace-write or Ledger-append capability. Cockpit is optional fail-open presentation and never a verifier. The real command line, environment attestation, workspace base/result SHA, DB/backend/session identity hash, producer PID/boot ref, provider/tool receipt and exit code are recorded.

The exact P4 DB profile is resolved through `ATLAS_P4_PG_PRODUCER_URL` and `ATLAS_P4_PG_VERIFIER_URL` in `config/database.php`; secrets are never copied into receipts. Setup refuses a database outside the `atlas_p4_` prefix, creates distinct PostgreSQL users/application names, migrates with the producer role, grants only required runtime rights, makes Ledger append-only for that role, and enforces `default_transaction_read_only=on` plus SELECT-only for the verifier. Before certification, negative assertions prove verifier DML/DDL/Ledger append fail, `SET ROLE`/owner/superuser/`BYPASSRLS` are absent, session/current identities match the attested roles, and the certifier has no provider/tool/workspace capability. A separate ephemeral setup role performs a real dump into a fresh database; the unchanged read-only verifier must replay the exact authenticated cutoff/head/position/count, while stale/truncated/wrong-role restores refuse. Teardown revokes all ephemeral roles. Missing URLs, same user/session/application identity, excessive grants, skipped assertions or teardown failure keeps P4 incomplete.

The invalidator matrix is not subtract-one only: it also replaces, splices, truncates, reorders and replays fixture labels, roots, modes, workspaces, attempt refs, provider/tool refs, instruction provenance, dependency/executable digests, environment attestations, Ledger cutoff/head/position/count and stale/same-process receipts. Every mutant returns non-zero with a precise failure_reason and causes zero additional effect.

P4 exit status is `aaeos_mt_real_journey_verified` only when all three mode journeys qualify. The separate derived UX label `operator_experience=oneshot` requires one commissioning episode, complete event capture and zero routine technical request/action; any allowed intent clarification is typed and enumerated, while Autônomos remains strict zero-touch. Sustained and comparative remain `not_claimed` unless their separate evidence actually exists.

Freeze:

- append one global freeze event binding authenticated tenant/chain cutoff, code SHA, workspace base/result SHAs, DB snapshot/backend identities, all three mode receipt+manifest hashes, operator-census hashes and verifier boot/role/result;
- version target contracts and record canonical hashes;
- archive predecessor receipts as historical;
- retain zero Quarantine/ACDE imports;
- recompute current claim through every event after the freeze cutoff: open effect/release uncertainty, canary failure, revocation, compensation/revert, fork/tamper or artifact drift makes current DONE false/stale until re-certified, without rewriting historical terminals;
- on incident, append revoke/inhibit before compensation, block descendant/new effects, retain the original observed effect and record compensation/late-settlement/residual-uncertainty edges; even a successful revert cannot restore DONE without a new independent certification;
- set SCOREBOARD from that current replay, not prose or frozen booleans.

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

### 9.3 Causal RED, mutation and invalidators

Each implementation slice records the exact RED command/exit/failure fingerprint and code+test hashes before change, then the GREEN command/exit and hashes after change. A RED that fails for another reason, passes, skips, runs zero tests, lacks its subprocess or is rewritten after capture invalidates the slice.

Reuse `MutationTestingAdapter`, `QualityFoundryMutationCoverageRunner` and the current Infection configuration on touched critical predicates. Any surviving semantic mutant in authority, effect ceiling, terminal status, capture coverage, core/event/manifest hash or proof qualification is a hard veto regardless of aggregate MSI. P4's certifier reruns a subtract-one invalidator matrix over root/causation/event hash, authority, attempt order, manifest, artifact bytes/hash, capture coverage and terminal status; each mutation produces non-zero exit, a precise reason and zero additional effect.

Verification paths:

- app/Services/Ai/Programming/AtlasDev/Mutation/MutationTestingAdapter.php
- app/Services/Ai/EngineeringKernel/QualityFoundry/QualityFoundryMutationCoverageRunner.php
- tests/Feature/Ai/Programming/AtlasDev/Mutation/MutationAntiGamingTest.php (existing; extend)
- tests/Unit/Ai/EngineeringKernel/QualityFoundryMutationCoverageRunnerTest.php (existing; extend)
- tests/Feature/Ai/Aaeos/AaeosCertificationInvalidatorMatrixTest.php (NEW)

### 9.4 Required architecture regressions

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
2. R1–R103 are closed, accepted as explicitly non-applicable, or assigned to a named horizon only where this plan already marks HORIZON.
3. R43, R46 and R51–R103 are closed; none may be waived as debt (R44 is folded into R66, not separately closable — R97).
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
14. `bin/atlas dev`, `bin/atlas forge`, direct Autônomos and routed `atlas:aaeos:run` are each proven in a fresh process to preserve native mode/root/authority/outcome identity; internal commands alone cannot satisfy this.
15. Forge's existing supervisor reaches `completeObra` without post-seal operator work, and the existing Autônomos scheduler/daemon cold-starts from durable server facts without harness `--facts` or technical next commands.
16. no legacy adapter/alias/executor/review owner is deleted before complete production/config/reflection/docs consumer parity; no reachable production class/event/status uses OneShot to mean a technical attempt.
17. the global freeze event matches the current code/artifacts and every Ledger event through the current head has been replayed; no open uncertainty, post-freeze invalidation, compensation or drift leaves a green current DONE projection.

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
| 3 | v8 reuse/delete survivors | 13 total lens-distinct passes: original 9 (native Dev, Forge, Autônomos, durability, security/authority, enterprise architecture, SOTA claims, plan logic, OneShot falsification) plus disclosed post-v10 supplements for proof/testing, owner-minimalism/human-loop abuse, direct-path deletion census and causal Autônomos progress | original judgment produced R75–R82/v9; supplement found five current candidates, judged two duplicates and accepted only exact native seam, intent-only daily CLI and observed sovereign continuation progress as R98–R100 | v9; completeness correction integrated in v11 |
| 4 | v9 | two independent adversarial panels: 13 lens-distinct executable/UX/recovery/security/DB/proof/efficiency/formal/terminal/schema/minimalism/governance passes + anti-dup judge, and a 14-lens anti-accretion/strategic panel + adversary; all owners rechecked on disk | accepted R83–R97: crash/Postgres convergence, real producer vs verifier, versioned rollout, ITT/no-amplification, provider-governance M, memory seam, compensation, P2 DAG, lazy aliases and one optional verification read model; merged operator/state/authority findings into R75–R82; corrected R91/R94 so neither direct Dev nor review UX requires a technical operator verdict | v10 |
| 5 | v11 | 13 lens-distinct passes: phase executability; OneShot/operator UX; Autônomos topology; Ledger/PostgreSQL; Forge; Dev; authority/security; schema rollout; REAL_OPERATION; routing/ablation; deletion census; efficiency/context anti-Goodhart; terminal/cockpit — plus final anti-dup judge | accepted only R101–R103; merged Ledger/Forge/Autônomos/OneShot/keyring/terminal/provider/budget findings into existing residuals; rejected new scheduler, store, outbox, router, executor, metrics owner and unsafe bulk deletions | v12 |
| 6 | v12 | 13 lenses: formal state/terminal; property/model; Byzantine independence; failure taxonomy; proof lattice; tenant/workspace isolation; secrets/privacy; supply chain/sandbox; incident recovery; phase DAG; exact PG/live profiles; compatibility/deletion; OneShot/public acceptance — plus anti-dup judge | accepted five hard strengthenings into R53–R55/R62/R78/R80/R83–R85/R101–R103; rejected all proposed R104+ and all new privacy/identity/credential/incident/proof stores | v13 |
| 7 | v13 | 13 lenses: complete Dev, Forge, Autônomos; direct/routed parity; 0..N repair; crash cutpoints; PostgreSQL races; authority TOCTOU; budget across restart; real producer/certifier; operator census; scheduler cold-start; freeze/late invalidation — plus anti-dup judge | accepted six journey/recovery/freeze strengthenings into existing residuals; rejected every R104+ and any new scheduler/worker/reporter/repair/verifier/freeze store | v14 |
| 8 | v14 | 13 lenses: cognitive load, Dev live intent, Forge planning boundary, Autônomos zero-touch, daily entry, cockpit/inbox, H1–H7 UX, elective controls, elapsed status, n=1/fan-out, learning, public help/API, ambition/claims — plus anti-dup judge | accepted six operator-experience/anti-Goodhart strengthenings into existing residuals; rejected every R104+ and new notification/learning/attention/fan-out/marketing/claim owner | v15 |
| 9 | v15 | 13 lenses: tenant/confused deputy; capability replay/revocation; secret egress; filesystem races; command/env injection; provider forgery; Ledger tamper/truncation; PostgreSQL role/restore; executable/dependency supply chain; resource exhaustion; instruction/memory poisoning; multi-engine dirty-main; incident/compensation — plus anti-dup judge | accepted seven security clusters into existing authority/evidence/resource/context/commit/recovery residuals; rejected every R104+ and new security gateway, budget/prompt/ownership/incident store, signer, Ledger or verifier | v16 |
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
| v10 | cycle-4 R83–R97: crash/concurrency convergence on existing facts; REAL_OPERATION produced outside tests and independently verified; Decision/cycle/Ledger payload version rollout; ITT/no-amplification efficiency; governed provider reach M; fenced learning/compensation; direct Dev OneShot; ordered P2; lazy compatibility; optional unified verification projection; residual dedupe/no-fuse guard. Zero new runtime organs |
| v11 | disclosed cycle-3 completeness correction to thirteen lenses; R98 names the exact shared native Autônomos seam, R99 removes operator-owned execution choreography from the daily CLI, and R100 proves real unrelated progress during H1–H7 reservation. OneShot remains operator experience only; no internal pass/time cap |
| v12 | cycle-5 executable hardening: R101 forbids ACT before Decision v3/keyring/revocation; R102 closes AWIS mode-identity downgrade; R103 binds public-entry and consumer-complete retirement parity. Forge forward progress, live Autônomos scheduling, Ledger v2 append-only, provider coverage, budget carry-forward and terminal truth deepen existing owners. Zero new runtime organs |
| v13 | cycle-6 convergence with zero new residual IDs: EngineeringOutcome v3 rollout, tenant-safe Ledger, caller-narrow-only CodeGraph sovereignty, truthful artifact redaction and exact PostgreSQL producer/verifier roles deepen existing owners; formal terminal/permutation/claim-set semantics and serial rollout receipts added |
| v14 | cycle-7 convergence with zero new residual IDs: Autônomos land-before-report, Forge Ledger-bound certification/forward driver, Dev single-root repair, strict producer/verifier separation, durable scheduler cold-start and globally invalidatable freeze deepen native owners |
| v15 | cycle-8 convergence with zero new residual IDs: detached continuation, closed clarification/missing-intent contract, one causal H1–H7 schema, honest unknown elapsed/effort, signed n=1/fan-out budget and stale-aware conjunctive claims make OneShot executable at the operator surface |
| v16 | cycle-9 adversarial security convergence with zero new residual IDs: tenant and transitive capability truth, operation-time filesystem/process integrity, observer-minted provider evidence, authenticated Ledger cutoffs, PostgreSQL restore/role proof, cumulative root budgets, instruction provenance, exact dirty-main deltas and revoke-before-compensate incident folds deepen existing owners |

## 14. Handoff

Current state after this document-only version:

- P0–P4 remain NOT_STARTED.
- No production code was authorized or changed by this plan round.
- The next action is another absolute audit cycle, not automatic execution.
- EXECUTE P0 authorizes only section 8 P0.

Operator decision after all ten cycles: EXECUTE P0 or another absolute round.
