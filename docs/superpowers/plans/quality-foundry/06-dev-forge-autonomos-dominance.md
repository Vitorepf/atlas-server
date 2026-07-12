# Dev, Forge, and Autonomos Dominance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan packet-by-packet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Dev, Forge and Autônomos dominant operating regimes of one engineering factory: same Kernel and quality bar, different operator-presence and duration contracts, no mode-specific shortcut.

**Architecture:** Create the fixed Dev façade and Forge runtime interfaces over existing AtlasDev/Forge owners; keep Autônomos productive work inside existing Brain→Task Fabric→native worker→Kernel services called by the daemon. Surfaces are adapters, not alternate executors.

**Tech Stack:** PHP 8.4+, Laravel 13, existing AtlasDev, Forge and Self-Construction services/tables, Engineering Kernel, Workcells, Governor, canonical ledger/outcomes, PHPUnit.

## Source contract and dependencies

- Master: `docs/superpowers/plans/2026-07-09-atlas-quality-foundry-world-10x.md`, sections 1, 3.1, 4.4, 11, 15 and 16.
- Predecessor: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`, Tasks 9–13 and mode audits.
- Depends on plans 01–05 for Kernel, Constitution, Product/Spec/Workcell, World/Market and Verification/Release/Outcome contracts.
- Produces mode-parity evidence and operational regimes consumed by causal learning and Rivals trials.

## Global constraints

- Dev, Forge and Autônomos share exactly the same Product/Spec/Workcell/Kernel/Verification/Governor/Outcome path.
- Mode never defines risk: every mode may execute any constitutionally allowed R0–R5 order.
- All 22 roles always disposition; depth scales by risk.
- Dev is not a fast-patch lane. Forge is not a separate quality tier. Autônomos is not an external terminal session.
- No human specialist dependency. Operator input is limited to the explicit mode contract: Dev intent/authority; Forge commissioning/control; Autônomos no ordinary operator.
- No runtime v3, shell/IDE, duplicate executor, ledger, outcome store, world model or claim engine.
- Cost/time/operator effort are measured; quality governs.
- Only Rivals issues/revokes comparative claims. No mode may claim dominance from its own telemetry.
- Local `main` override preserves concurrent WIP; do not wipe source changes or steal blackboard claims.
- No push, deploy, production cutover or long-running soak is authorized by this plan.

## Fixed public interfaces

```php
AtlasDevExecutionService::plan(DevIntent $intent): DevPlan;
AtlasDevExecutionService::run(ConfirmedDevRun $run): DevRunResult;

ForgeObraRuntime::commission(ForgeCommissioning $commissioning): ForgeObraSnapshot;
ForgeObraRuntime::tick(ForgeObraId $obra, ForgeTickBudget $budget): ForgeTickResult;
ForgeObraRuntime::control(ForgeObraId $obra, ForgeControlCommand $command): ForgeObraSnapshot;
```

`ForgeObraRuntime::snapshot(ForgeObraId $obra): ForgeObraSnapshot` remains a required read operation from the predecessor.

Autônomos has no new public productive façade. CLI remains control/diagnostic; `AtlasSelfConstructionRuntimeDaemonCycle` calls an internal service that advances one idempotent cycle through the existing Brain/Muscle owners.

## Existing owners to deepen

- Dev: `app/Services/Ai/Programming/AtlasDev/**`, current HTTP/CLI/Mission/Desktop/Senior Loop adapters and safe workspace pipeline.
- Forge: `app/Services/Ai/Programming/Forge/**`, `AiForgeIntake`, existing long-horizon/cycle/supervisor/job/reaper tables and services.
- Autônomos: `app/Services/Ai/SelfConstruction/**`, Brain commands/services, Task Fabric, task serving, native worker, daemon, Governor and outcome/learning bridges.
- Shared: Engineering Kernel, Product/Spec Courts, Agentic Workcell, Atlas Decide, Software Twin, Verification Court, Merge Governor and outcomes from plans 01–05.

## Allowed subsystem and file families

- `app/Services/Ai/Programming/AtlasDev/**`
- Existing AtlasDev controllers/commands/adapters only to delegate to the façade; no business logic retained in surfaces.
- `app/Services/Ai/Programming/Forge/**`
- Existing Forge models/tables and additive recovery fields/indexes only.
- `app/Services/Ai/SelfConstruction/{Brain,TaskFabric,TaskQueue,NativeWorker,RuntimeDaemon,Governance,MergeGovernor,Autonomy,UnattendedRuntime,Completion}/**`
- `app/Services/Ai/SelfConstruction/AtlasTaskServingService.php`
- Brain/task/daemon commands only as thin control/diagnostic adapters.
- Minimal shared adapters under existing Kernel/Workcell/Decide owners; no mode-specific copy.
- Matching tests under `tests/{Unit,Feature}/Ai/{Programming/AtlasDev,Programming/Forge,SelfConstruction,EngineeringKernel}/` plus surface/architecture tests.
- Existing mode owner docs/readiness manifests.

For every packet, attach RED and GREEN focused/neighboring outputs, canonical receipt/evidence refs, an explicit migration decision, owner-doc delta (or `docs_unchanged` with reason), local commit, remaining blockers and the named next packet.

---

### Packet 1: Shared mode contract and no-bypass baseline

**Finding/hypothesis:** Mode surfaces can drift before the new façades exist. A shared contract test and mutative-surface census will prevent work from deepening a bypass.

**Owner:** Engineering Kernel coverage plus existing mode adapters.

**Allowed files:** cross-mode contract fixtures, coverage registry and architecture tests; production mode code only to close a confirmed bypass.

- [x] Enumerate every Dev/Forge/Autônomos mutative surface and write RED architecture tests for direct provider, filesystem, Git, verification, release/deploy or outcome success outside shared ports.
- [x] Build one equivalent fixture per R0/R3/R5 and assert identical ProductIntent/spec/world/market/order hashes, 22-role roster/depth, evidence floor, Governor path and outcome semantics.
- [x] Record operator presence, duration and topology as explicit fields rather than implicit mode quality policy.
- [x] Close confirmed bypasses by delegating to the existing shared owners; do not create an interim mode kernel. Evidence: Forge and Autônomos null-Kernel fallbacks now fail closed; Dev remains delegated to `EliteExecutorKernelDevAdapter`; no interim mode kernel was introduced and the architecture bypass regression suite passes.
- [x] Run coverage/architecture and all current mode adapter contract tests. Evidence: the complete current mode/Kernel adapter command was executed; Kernel/contract slices and corrected provider-manager/AWIS/retrieval fixtures pass, while AtlasDev executor/critic fixtures still have 7 `needs_review` behavioral divergences, and architecture validation remains blocked by the missing `atlas_engineering_runs` projection table.

**GREEN acceptance:** coverage is 100% for known mutative surfaces; equivalent orders differ only in explicit mode/operator/duration/topology fields; no quality bypass survives.

**Migration/data ownership:** no new table. Coverage uses `atlas_ledger_events` and existing engineering run IDs.

**Failure and rollback:** any uncovered surface keeps all mode rollout in observe/sandbox; do not exempt the surface.

**Local commit:** `test: lock shared engineering mode contract`.

---

### Packet 2: Atlas Dev conversation-first façade

**Finding/hypothesis:** Dev surfaces can duplicate planning/run logic, mutate source WIP or hand off based on complexity. One façade can keep operator interaction conversational while Atlas owns execution/recovery.

**Owner:** new `AtlasDevExecutionService` inside existing AtlasDev family; current surfaces become adapters.

**Allowed files:** AtlasDev services/types/controllers/commands, shared Kernel adapters and focused tests.

- [x] Write RED façade tests for invalid/unconfirmed intent, stale authority, R5, interactive/durable selection, ownership overlap, source WIP, provider/retry failure and idempotent Forge handoff. Evidence: `AtlasDevExecutionServiceTest` covers invalid authority, R5, duration/topology, provider failure and idempotent handoff; `ScopeGuardTest` covers ownership overlap and content-hash WIP preservation; `AtlasDevSurfaceArchitectureContractTest` covers facade boundaries.
- [x] Implement `DevIntent`, `DevPlan`, `ConfirmedDevRun`, `DevRunResult` as immutable types bound to ProductIntent/spec/world/authority hashes.
- [x] Make HTTP, CLI, Desktop, Mission and Senior Loop call `plan/run`; prohibit direct provider/workspace/release logic in adapters. Evidence: `AtlasDevServiceProvider` binds `RunExecutor` to `KernelRunExecutor` for HTTP/CLI/Desktop/Senior Loop, while `AtlasDevMissionAdapter::executeViaDevFacade` binds Mission/WorkOrder to `DevPlanRunFacade::plan` then `run`; `AtlasDevSurfaceArchitectureContractTest` enforces the shared facade and absence of provider/process ownership.
- [x] Preserve checkout source byte-for-byte until authorized integration; retries reset only isolated sandboxes and integration is serial. Evidence: `ExecutionOrder` hard-gates mutative Forge orders to sandbox + read-only source + deterministic workspace lock; `AtlasSelfConstructionHermeticSandboxApplyService` clones with `--no-local --no-hardlinks` and validates source/base/manifest identity; `ForgeWorkPacketKernelExecutionTest` proves retries use distinct attempt-scoped idempotency keys while preserving checkout binding; `ForgeEliteKernelExecutionAdapter` serializes integration per workspace.
- [x] Keep any technical complexity in Dev when duration/topology fit; hand off to Forge only for duration/topology and make the handoff idempotent. Evidence: `AtlasDevExecutionServiceTest` now rejects Forge routing for `interactive/single`, permits only durable regimes with `workcell/DAG/portfolio`, and proves a stable `handoff.idempotency_key` across repeated runs; 11 tests / 37 assertions pass.
- [x] Measure operator questions, minutes, overrides, cancellations, handoffs and active time without using them to lower quality. Evidence: `AtlasDevOperatorInteractionTelemetry` emits `atlas.dev.operator_interaction_telemetry.v1`, aggregates persisted plan metadata events, returns `pending_data` instead of fabricated zeros, and exposes no quality/release fields; 3 focused tests pass.
- [x] Run façade, WIP tracked/untracked/rename/delete/binary/concurrent-change, surface parity, R5, retry/recovery and architecture tests. Evidence: Dev façade/scope/telemetry/Mission/E2E/architecture suite passed 48 tests / 215 assertions; `PipelineRunExecutorTest::test_repair_retry_restores_operator_wip_baseline_instead_of_checking_out_allowed_files` passed the tracked, untracked, rename, delete and binary WIP matrix; Hermes scope, anti-gaming recovery and Forge retry tests also passed.

**GREEN acceptance:** conversation-first R0–R5 works through one façade; no GET mutation, WIP loss, direct provider or duplicate release; Forge handoff occurs only by explicit contract and is idempotent.

**Migration/data ownership:** reuse engineering runs/operator actions/outcomes and Dev artifacts. Add fields only to existing owners for façade/order/handoff hashes.

**Failure and rollback:** disable Dev run while retaining read-only planning; preserve WIP/sandboxes/evidence; never route to a fast-patch executor.

**Local commit:** `feat: unify Atlas Dev execution facade`.

---

### Packet 3: Forge commissioning and durable Obra runtime

**Finding/hypothesis:** Long-running Forge work needs a single resumable runtime with frozen commissioning, durable DAG, leases/fencing and Kernel-per-packet execution; duplicate cycles or simulation advancing production break durability.

**Owner:** new `ForgeObraRuntime` façade over existing Forge intake/long-horizon/cycle/supervisor owners; `AiForgeIntake` remains canonical identity.

**Allowed files:** Forge family/models/tables and shared adapters; no second event/outcome ledger.

- [x] Write RED type/contract tests for commissioning missing authority/release/interruption policy, duplicate commissioning, non-idempotent tick/control/snapshot, simulation satisfying real state and two canonical cycles. Evidence: `ForgeObraRuntimeContractTest`, `ForgeObraRuntimeTest` and focused `ForgeWorkPacketExecutionCycleServiceTest` cover frozen commissioning, duplicate commissioning, replayed controls, snapshot reload, simulation isolation, cycle replay and productive next-packet progression.
- [x] Implement immutable Forge types and freeze commissioning into one authority-bound Obra identity.
- [x] Persist a DAG of resumable packets; dependencies advance only from real Kernel outcomes. Snapshot is rebuilt from canonical events. Evidence: Forge packets/cycles persist dependency and canonical cycle state; `ForgeMultiAgentSchedulerServiceTest` proves topological ordering; `ForgeWorkPacketExecutionCycleServiceTest::test_select_packet_does_not_release_dependency_before_productive_completion` blocks dependency release until productive completion; runtime tests replay snapshot hashes after reload and canonical cycle projections remain stable.
- [ ] Run supervisor/jobs/reaper with leases, heartbeat and fencing; provider start/poll/cancel/heartbeat goes through shared ports.
- Runtime evidence update (2026-07-12): `ForgeObraRuntime::tick` now persists `providerStart` before every real Kernel execution and polls the terminal cycle with the same fencing token; lifecycle start replay, heartbeat and fenced cancel are covered by `ForgeObraRuntimeTest`. `ForgeObraSupervisor` now renews the persisted provider lifecycle with that same fence after renewing the scope lease; the crash-after-start supervisor fixture passes. The item remains open until a real external provider adapter is exercised through shared ports.
- [x] Execute each packet through Product/Spec/Workcell/Kernel; workers never touch main and integration is serial. Evidence: `ForgeObraRuntime` now delegates productive packets to `ForgeWorkPacketExecutionCycleService::executeRealCycle` and `ForgeWorkPacketExecutionPort`; the Kernel order carries Forge authority, sandbox/read-only source and workspace lock; `ForgeEliteKernelExecutionAdapter` serializes integration; architecture and Obra runtime suites pass.
- [x] Support pause/drain/cancel/orphan recovery at every stage with zero duplicate effect. Evidence: `ForgeObraRuntimeTest` proves active `drain` blocks new ticks, `cancel`/`drain` controls replay with identical state hashes, and orphan recovery transitions a running cycle once while replay returns `no_running_cycle`; `ForgeScopeReservationServiceTest` covers lease expiry/reap, fencing takeover and crash replay.
- [x] Run Obras of 1, 3 and 10 packets in fixtures; inject crash at commissioning, lease, provider, verification, integration, release and outcome boundaries. Evidence: `ForgeObraRuntimeTest::test_packet_scale_fixture_materializes_one_cycle_per_packet_without_duplicate_effect` executes real cycles through the shared `ForgeWorkPacketExecutionPort` at 1/3/10 packets with unique cycle and packet identities; the same runtime suite covers commissioning replay, provider crash-after-start, supervisor lease/provider-heartbeat recovery and orphan recovery; `ForgeWorkPacketExecutionCycleServiceTest` covers verification-gate refusal, missing/expired lease, terminal write failure, release settlement and terminal outcome replay; `ForgeWorkPacketKernelExecutionTest` proves integration interruption after shared-port order capture. Combined Forge battery: 46 tests / 254 assertions green.

**GREEN acceptance:** one commissioning suffices, no routine operator confirmation, snapshots replay, crash recovery resumes safely, simulation stays separate, and every real packet has a Kernel outcome.

**Migration/data ownership:** extend `ai_forge_long_horizon_states`, `ai_forge_work_packet_execution_cycles` and existing Forge tables only for proven DAG/lease/fence/hash fields; use canonical events/outcomes.

**Failure and rollback:** pause/drain, let leases expire, reconcile uncertain packet and redeploy N−1 if separately authorized. Never fall back to a second cycle or v1 executor.

**Local commit:** `feat: add durable Forge Obra runtime facade`.

---

### Packet 4: Autônomos internal 24/7 productive cycle

**Finding/hypothesis:** Autonomy is false if a human terminal session is an ordinary worker, if dry work burns targets, or if the daemon can commit directly. The live Brain+Muscle cycle must be internal, durable and authority-bounded.

**Owner:** existing Self-Construction Brain, Proposal Arena, Task Fabric, native worker, daemon, Governor and outcome/learning services.

**Allowed files:** SelfConstruction families listed above and their tests; do not revive ACDE/`atlas:loop:*` or create an Autonomos façade.

Canonical cycle:

```text
Brain → Proposal Arena → Product/Spec Courts → atomic reservation
→ Task Fabric → native workcell → Kernel → Governor
→ release/canary → outcome → causal learning → Brain
```

- [x] Write RED tests proving no external session counts as worker, no direct commit, no dry result burns target, no `served` target enters done-set, no bad post-commit verdict settles and no Constitution/claim/kill-switch self-modification. Evidence: `AgentControlPlaneMultiAgentLoopProbeRunnerTest` excludes explicit `runtime_owner=external_session`; `AtlasNativeWorkerProductionCallbacksTest` rejects direct commit; `AtlasBrainSeedDryRunDoneSetCommandTest` and `AtlasBrainDoneSetServedDoesNotBurnTest` protect done-set semantics; `AtlasTaskServingServiceTest` keeps lease open after bad post-commit outcome; kill-switch and proposal/claim policy suites pass with self-modification and auto-apply false.
- [x] Require 2–3 proposals ranked by leverage, simplification, recurrence, verifiability and blast radius; refuse proposals lacking finding/baseline/delta/rollback/test. Evidence: `AtlasTaskBrainReplenisherTest::test_autonomos_proposal_competition_selects_one_quality_complete_candidate_before_enqueue` proves a three-candidate competition and quality-contract gate; `AtlasExternalBrainProposalSelectionLoopTest::test_ranking_explicitly_penalizes_blast_radius_and_rewards_recurrence_and_verifiability` proves the explicit ranking dimensions.
- [x] Make `next→seed`, scope reservation, worker cycle, report and restart idempotent; more than 500 live tasks remain visible. Evidence: focused battery passes 83 tests / 292 assertions across `AtlasBrainSeedDryRunDoneSetCommandTest`, `AtlasNativeWorkerClaimExecuteReportCycleTest`, `AtlasSelfConstructionNativeActionExecutorTest`, `AtlasSelfConstructionRuntimeDaemonCycleTest` (including duplicate/replay withholding) and `TaskQueueRegistryIndexStoreTest::test_cap_registry_never_hides_live_entries_above_hard_cap`; the selector dependency is a pure Wilson read-side verdict with no merge authority.
- [x] Call real provider via shared port, use isolated workcell/Kernel and keep primary+fallback provider; total outage pauses/retries without corruption. Evidence: the native executor/claim-cycle/typed-order/hermetic-sandbox/architecture battery passed 57 tests / 210 assertions; `AtlasSelfConstructionNativeActionExecutorTest` covers ProviderPort, sandbox apply, timeout/down, fallback exhaustion, restart and lease recovery, while `EngineeringKernelBypassRegressionTest` prevents direct mutative bypass.
- [x] Enforce existing authority with nonce/replay/expiry/revocation; out-of-envelope work replans/quarantines and the daemon continues another eligible task. Evidence: claim leases now issue a distinct `authority_nonce`, bind it into `lease_integrity_hash`, expose `authority_revoked`, and revoke it on release/expiry; lease/daemon replay tests cover duplicate action withholding and restart recovery; `AtlasNativeWorkerOutcomeMapperTest` rejects out-of-envelope changes as poison and emits `quarantine_and_replan`; `AtlasSelfConstructionRuntimeDaemonCycleTest` proves one failed action does not prevent another eligible action from running.
- [x] Allow autoexperiments only on reversible routing/memory/policy; code returns as a governed task and never promotes directly. Evidence: `CausalLearningGate` emits `emit_code_task` for code changes; `CausalLearningPromotionService` only accepts reversible `routing`, `memory_policy` or `operational_policy` candidates with fresh verdict, expiry, bound evidence and rollback; `AtlasQualityFoundryLongitudinalCompoundingProofTest` and `CausalLearningPromotionTest` prove no direct code promotion, scoped routing apply/revoke, late-regression rollback and claim ineligibility.
- [ ] Run zero-human-session, queue >500, dry rotation, provider outage, kill/restart, authority replay, direct-commit and continuous-cycle fixtures.

**GREEN acceptance:** steady-state cycles with zero human session, zero direct commit/scope collision/lost task, safe kill/restart and truthful holds; Constitution, claim engine and kill switch remain immutable to runtime.

**Migration/data ownership:** reuse task queue, reservation, engineering run, canonical ledger and outcomes. Add no Autonomos event/outcome ledger.

**Failure and rollback:** stop/drain/quarantine daemon, expire reservations and preserve claimable tasks. Never enlist a human session or switch to dead Loop/ACDE.

**Local commit:** `feat: close the native Autonomos engineering cycle`.

---

### Packet 5: Mode-specific recovery and operator contract tests

**Finding/hypothesis:** Shared quality does not imply shared operator behavior. Each mode needs explicit control/recovery semantics without introducing quality divergence.

**Owner:** Dev façade, Forge runtime, Autônomos daemon control plane.

**Allowed files:** the three mode families and cross-mode fixtures.

- [ ] Write RED operator/recovery contract tests that fail when a mode asks for forbidden routine input, lowers quality, loses state or returns a different shared failure semantic.
- [ ] Dev: test confirm/cancel/override/handoff, retries without operator supervision, and preserved WIP after every failure.
- [ ] Forge: test commissioning, pause/drain/cancel/snapshot/orphan recovery at every DAG boundary and no prompt for routine decisions.
- [x] Autônomos: test zero ordinary operator, stop/go kill switch, provider exhaustion, poisoned task quarantine and continuation of unrelated eligible work. Evidence: native claim-cycle and runtime-daemon suites cover zero-human execution, provider exhaustion/timeout, stop/pause/resume, poison/scope quarantine signals, replay withholding and isolated continuation; `AgentRuntimeRegistryQuarantineRepositoryTest` and `AgentDispatchPlannerBatchPlannerTest` prove quarantined workers/task families are excluded from dispatch.
- [x] For each mode, inject ledger/Governor/verifier/canary/outcome outage and assert the shared Kernel terminal semantics. Evidence: `QualityFoundryCrossModeFailureMatrixTest` covers the shared ledger, Governor, provider, verifier, canary, revert, outcome and process-kill boundaries with equivalent fail-closed terminal semantics across Dev, Forge and Autônomos.
- [ ] Assert mode control commands cannot alter Constitution, claim authority, evidence floor or risk depth.
- [ ] Run focused mode recovery suites and shared receipt-chain replay.

**GREEN acceptance:** operator presence matches the mode contract while quality/evidence/release semantics stay identical; every failure is resumable or truthfully terminal.

**Migration/data ownership:** no migration. The packet exercises existing mode control, lease, event and outcome records.

**Failure and rollback:** mode kill switch means stop/drain/quarantine, never v1 fallback or quality reduction.

**Local commit:** `test: prove engineering mode recovery contracts`.

---

### Packet 6: Dominance evidence, canary readiness and soak initiation contract

**Finding/hypothesis:** Feature completeness is not dominance. Each mode needs real workload evidence and separate readiness, soak and comparative states.

**Owner:** existing readiness/outcome projections; Rivals adjudicates comparison.

**Allowed files:** readiness manifests, outcome adapters, cross-mode performance/operator-effort fixtures and docs. No production cutover.

- [ ] Write RED mode-readiness tests that fail on missing canary/crash/WIP/zero-human evidence or any synthesized soak/dominance claim.
- [ ] Dev readiness: low/mixed/R5 sandbox canaries, surface parity, no WIP loss, measured operator effort.
- [ ] Forge readiness: real fixture Obras of 1/3/10 packets, crash boundaries, zero duplicate effect and a defined 24h soak start receipt.
- [ ] Autônomos readiness: staging/limited fixture cycle, >500 visible tasks, zero-human proof, dry rotation, restart and a defined 24h/7d soak start receipt.
- [ ] Verify all modes use one Kernel/bar and produce comparable quality-loss inputs; cost/time/operator effort remain secondary metrics.
- [x] State-test that canary/readiness cannot imply elapsed soak, `quality_foundry_ready`, `multiplier_proven`, `world_leading` or 10×. Evidence: `QualityFoundryModeReadinessManifestServiceTest::test_readiness_flags_cannot_synthesize_soak_or_world_claims` and `QualityFoundryReadinessStateMachineTest::test_green_implementation_cannot_jump_to_cutover_soak_or_claims` keep all comparative claim flags false.
- [x] Produce three mode manifests with live test/evidence refs and blockers. Evidence: `atlas:engineering:quality-foundry-manifests --json` emits live Kernel/Dev/Forge/Autônomos manifests; the three mode manifests contain executed test commands, exit/output/duration receipt metadata, SHA-256 test refs and explicit blockers, while comparative/soak claims remain false.

**GREEN acceptance:** each mode is implementation/canary ready at its proven scope; no soak is marked elapsed and no dominance claim is issued.

**Migration/data ownership:** no migration. Mode manifests project existing run, operator-action, release and outcome evidence.

**Failure and rollback:** failed mode canary disables/drains only that mode while keeping the shared Kernel; any shared-bar failure blocks all modes.

**Local commit:** `feat: expose Dev Forge and Autonomos readiness`.

## Verification and rollout

Per packet run focused and neighboring mode suites, shared Kernel/architecture coverage, focused Pint/PHPStan, docs health and:

```bash
git diff --check -- <packet-files>
```

Rollout requires separately authorized sandbox/staging canaries. Dev, Forge and Autônomos are enabled by mode flags over the same Kernel; never dual-execute a mutation.

## Honest states and blockers

- Mode implementation/readiness is not dominance, causal multiplier, elapsed soak or world leadership.
- WIP loss, mode-specific bar, direct provider/Git/release, external-session dependency, duplicate Forge cycle/effect, hidden live tasks, dry target burn, claim/Constitution self-modification, missing receipt/outcome or unsafe restart is a blocker.
- Temporal observation must actually elapse; comparative state remains `world_10x_quality_proof_pending` until Rivals proof.
- This plan authorizes no push, deploy, production cutover or claim.
