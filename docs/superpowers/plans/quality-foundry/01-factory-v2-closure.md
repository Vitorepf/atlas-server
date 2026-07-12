# Factory v2 Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan packet-by-packet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two residual P0 findings, prove the real vertical Engineering Kernel, and reach an honest `cutover_ready` decision without performing cutover.

**Architecture:** Deepen the existing `App\Services\Ai\EngineeringKernel` and the existing Forge and Self-Construction owners. This plan finishes the predecessor; it does not create a runtime v3, a second executor, a second ledger, a second outcome store, or a shell.

**Tech Stack:** PHP 8.4+, Laravel 13, PostgreSQL 16, SQLite `:memory:` for hermetic tests, Atlas Evidence Ledger, `atlas_ledger_events`, PHPUnit, Pint, PHPStan.

## Source contract and dependencies

- Master: `docs/superpowers/plans/2026-07-09-atlas-quality-foundry-world-10x.md`, especially sections 3, 4, 15, 16 and 18.
- Predecessor: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`, especially P0-13, P0-19 and Tasks 8–15.
- Entry state: `implemented_not_cutover_ready`; P0-13 and P0-19 must be revalidated on the live HEAD before any edit.
- This plan is a hard dependency for plans 02–08. Later plans may deepen behavior only after the Kernel contracts and evidence chain here are stable.

## Global constraints

- Work on local `main` under the 2026-07-09 operator override; preserve every concurrent tracked and untracked change.
- Bootstrap, placement, Context Pack and blackboard checks are required before each packet. Never steal a conflicting claim.
- Dev, Forge and Autônomos use the same Kernel and the same quality bar.
- All 22 quality roles always receive a disposition; depth scales with risk, membership does not.
- Only Rivals may issue or revoke comparative claims.
- No human specialist is a runtime dependency.
- Do not create runtime v3, a shell, a duplicate ledger, a duplicate outcome store or a duplicate world model.
- Cost and time are measured; quality, authority, integrity and evidence govern.
- Never infer a temporal, causal, comparative, `world_*` or 10× state from implementation or green tests.
- No push, deploy, production cutover, destructive migration or v1 removal is authorized by this plan.
- Tests must use SQLite `:memory:` or a dedicated ephemeral PostgreSQL database. Never run destructive database commands and never use a shared `vendor` symlink.

## Existing owners to deepen

- Kernel contracts and orchestration: `app/Services/Ai/EngineeringKernel/**`.
- Canonical evidence: `app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php` and `atlas_ledger_events`.
- Forge durable state and continuation: `app/Services/Ai/Programming/Forge/**` plus existing Forge tables.
- Autônomos native execution: `app/Services/Ai/SelfConstruction/NativeWorker/**`, `app/Services/Ai/SelfConstruction/RuntimeDaemon/**`, and `AtlasTaskServingService`.
- Release authority: existing Merge Governor services plus `AuthorizedMergeAction` and `MergeActuator`.
- Outcomes: `AiRunOutcome`, `AiTemporalCertification`, their existing writers, and canonical ledger events.
- Coverage: `EngineeringExecutionCoverage` and `EngineeringExecutionSurfaceRegistry`.

## Fixed interfaces

```php
EliteExecutorKernel::execute(ExecutionOrder $order): EngineeringOutcome;
EliteExecutorKernel::observeOutcome(OutcomeObservation $observation): OutcomeLearningReceipt;
```

`ExecutionOrder` carries the schema; run/delivery IDs; mode; R0–R5 risk; C0–C5 complexity; duration; topology; ProductIntent/spec/world-snapshot hashes; workspace/base commit; allowed and forbidden scope; authority/DecisionReceipt; operator contract; 22-role roster/depth; provider/model route; tool permissions; evidence/release/outcome policies; experiment ref; idempotency key; and `budget_posture=unbounded_quality_first`.

`EngineeringOutcome` admits only `released`, `completed_read_only`, `held`, `blocked`, `refused`, `reverted`, or `release_uncertain`; it includes correlated hashes, 22 dispositions, evidence and effect receipts, cost/time, uncertainty, observation schedule, and defaults `claim_eligible=false`.

## Allowed subsystem and file families

- `app/Services/Ai/EngineeringKernel/**`
- `app/Services/Ai/Programming/Forge/**`
- `app/Services/Ai/SelfConstruction/{NativeWorker,RuntimeDaemon,NativeImplementation,Governance,MergeGovernor,Readiness}/**`
- `app/Services/Ai/SelfConstruction/AtlasTaskServingService.php`
- `app/Console/Commands/AtlasSelfConstructionRuntimeDaemonCommand.php`
- `app/Models/{AiRunOutcome,AiTemporalCertification}.php`
- Existing Engineering Run, Forge, Self-Construction, outcome and temporal models only.
- At most one additive migration for `atlas_task_scope_reservations`, and additive migrations for proven missing fields on existing owner tables.
- Matching tests under `tests/{Unit,Feature}/Ai/{EngineeringKernel,Programming/Forge,SelfConstruction,Aemor}/` and architecture tests under `tests/Feature/Architecture/`.
- Canonical owner docs required by the changed contracts; do not rewrite unrelated docs.

Anything outside these families requires a new placement result and an explicit scope amendment before editing.

For every packet, attach the RED output, GREEN focused/neighboring output, canonical receipt/evidence refs, migration decision, owner-doc delta (or `docs_unchanged` with reason), local commit, remaining blockers and named next packet. A packet cannot advance on narrative status alone.

---

### Packet 1: Revalidate and close P0-13 durable reservations

**Finding/hypothesis:** Forge can still finish or continue against a stale or non-transactional reservation. A canonical active-scope reservation with lease expiry, fencing and idempotency will make crash recovery safe without creating a second event ledger.

**Owner:** Forge long-horizon runtime; the transactional owner is `atlas_task_scope_reservations` only if live schema inspection proves no equivalent table.

**Allowed files:** `app/Services/Ai/Programming/Forge/**`; one additive `database/migrations/*_create_atlas_task_scope_reservations_table.php`; matching Forge tests.

**Migration/data ownership:** create the one master-authorized `atlas_task_scope_reservations` table only after live schema inspection confirms no equivalent. Its contract is UUID primary key; `run_id`; canonical scope/path; mode; lease owner/token; authority hash; state; monotonically increasing fencing token/version; baseline hash; `lease_expires_at`; `released_at`; timestamps; partial unique active-scope constraint. Ledger events reference the row and token; they do not replace transactional state.

- [x] Write RED tests for two simultaneous acquires, renewal by a stale token, expiry/takeover, an old worker settling after takeover, retry with the same idempotency key, crash before/after persistence, and replay reconstruction.
- [ ] Run the focused reservation and long-horizon suites; expected RED is duplicate ownership, stale-token acceptance, or premature completion on the current implementation.
- [x] Implement atomic acquire/renew/release/takeover and compare-and-swap fencing in the existing Forge repository/service. Do not add a parallel Forge cycle.
- [x] Emit canonical reservation receipts into `atlas_ledger_events` only after the database transaction commits.
- [x] Make Forge completion require a live reservation, final Kernel outcome and successful settlement; an expired lease yields `held` or retry, never success.
- [ ] Run focused tests, the neighboring Forge cycle/continuation suites, migration fresh/rollback on an isolated database, architecture validation, Pint and PHPStan for touched paths.

**GREEN acceptance:** exactly one active owner; stale workers perform zero effects; retries return the prior receipt; recovery resumes or releases without duplicate work; P0-13 is `CLOSED` with evidence.

**Failure and rollback:** migration failure leaves all feature flags in observe/disabled state. Runtime rollback disables acquisition and drains current leases; it does not delete rows or reactivate a v1 executor. The schema remains additive and N−1 compatible.

**Local commit:** `fix: close Forge reservation and continuation gaps`.

---

### Packet 2: Revalidate and close P0-19 native Autônomos apply

**Finding/hypothesis:** Productive Autônomos paths may still begin in dry mode, depend on test callbacks, or report a task resolved after apply/provider failure. Routing the daemon through the existing `ProviderPort`, isolated workspace and native worker cycle closes the gap.

**Owner:** `AtlasNativeWorkerClaimExecuteReportCycle` and `AtlasSelfConstructionRuntimeDaemonCycle`; task serving remains queue ownership, not workcell execution.

**Allowed files:** Self-Construction NativeWorker, RuntimeDaemon, NativeImplementation and task-serving families listed above; `EngineeringKernel/ProviderPort.php` and existing adapters only when a real seam is missing; matching tests.

- [ ] Write RED tests proving that productive mode cannot default to `dryRun=true`, cannot inject a test callback, cannot self-certify, and cannot mark resolution after provider/apply/ledger failure.
- [ ] Add RED daemon tests for provider timeout/down, process kill between claim/apply/report, restart, duplicate delivery, and fallback-provider exhaustion.
- [ ] Run native worker and daemon suites; expected RED is a callback/dry path reaching a favorable terminal state or a failed apply consuming the task.
- [ ] Route provider invocation through `ProviderPort`, materialize a hermetic sandbox, apply only inside that sandbox, and send evidence to the independent gate.
- [ ] Persist claim/execution/apply/report receipts with one idempotency key and reconcile incomplete chains after restart.
- [ ] Map total provider unavailability to durable pause/retry. Map apply or ledger failure to `held|blocked|release_uncertain`, never resolved.
- [ ] Run focused and neighboring task-serving/governance suites, process restart tests, architecture bypass guard, Pint and PHPStan.

**GREEN acceptance:** no productive callback, no dry default, no self-verification, no direct provider bypass, no lost task after failure, safe kill/restart, and P0-19 `CLOSED`.

**Migration/data ownership:** no migration. Reuse task, engineering run, reservation, canonical event and outcome owners.

**Failure and rollback:** stop/drain the daemon and leave tasks claimable after lease expiry. Preserve sandboxes/evidence for reconciliation. Never fall back to an external human session or a v1 execution path.

**Local commit:** `fix: complete native Autonomos apply execution`.

---

### Packet 3: Materialize the typed Kernel contract and read-only vertical

**Finding/hypothesis:** The shallow Kernel cannot prove the full delivery chain until `ExecutionOrder`, `EngineeringOutcome`, outcome observation, hash correlation and replay are typed and fail-closed.

**Owner:** existing `EliteExecutorKernel`; `AcceptanceBundle` is deepened rather than replaced.

**Allowed files:** `app/Services/Ai/EngineeringKernel/**`, canonical ledger adapter, and focused Kernel tests.

- [ ] Write RED constructor/serialization tests for every fixed `ExecutionOrder` field, invalid mode/risk/topology, missing authority/scope/hashes, and non-`unbounded_quality_first` posture.
- [ ] Write RED outcome tests for the seven legal statuses, all 22 dispositions, default `claim_eligible=false`, missing evidence, and deterministic canonical hashes.
- [ ] Add RED replay tests: the same order/idempotency key returns the same receipt chain; a changed hash is refused.
- [ ] Implement immutable typed value objects and deterministic serialization; keep legacy readers only behind the v1 translator.
- [ ] Implement a read-only vertical through Kernel → evidence → outcome with zero provider, filesystem or release mutation.
- [ ] Run focused Kernel unit tests, `AcceptanceGateContractTest`, coverage tests, architecture validation, Pint and PHPStan.

**GREEN acceptance:** a read-only order produces `completed_read_only` only with correlated order/evidence/outcome hashes and 22 dispositions; missing or stale data holds or blocks; replay is deterministic.

**Migration/data ownership:** no new table. Use `atlas_engineering_runs`, `atlas_ledger_events`, `AiEngineeringCompanyRoleRun`, `ai_run_outcomes` and existing receipt stores.

**Failure and rollback:** disable the v2 entry flag while preserving emitted receipts. The translator remains read-compatible; no dual writer is introduced.

**Local commit:** `feat: add typed engineering kernel execution contract`.

---

### Packet 4: Prove provider → sandbox → evidence → release → outcome

**Finding/hypothesis:** Green component tests do not prove the Kernel vertical. A fixture repository E2E must correlate real provider, workspace delta, independent verification, Governor authorization, actuator effect, canary, rollback and outcome.

**Owner:** `EliteExecutorKernel`, existing ports, `AuthorizedMergeAction`, Merge Governor, `MergeActuator`, canonical Evidence Ledger and outcome writer.

**Allowed files:** EngineeringKernel family, existing provider/sandbox/release adapters, existing outcome writer, fixture repositories and Kernel E2E tests. Production provider calls are never required for the default suite; a governed explicit smoke profile may exercise one.

- [ ] Write RED vertical slices for provider refusal/timeout, sandbox escape, no-op delta, failed test/repair replay, verifier disagreement, ledger down before act, canary failure, revert failure, and crash between act and settle.
- [ ] Run each slice separately and record the exact missing receipt or false terminal state.
- [ ] Connect `AiProviderManager` through `ProviderPort`; execute in an isolated sandbox; collect commands, assertions, hashes, repair and regression evidence.
- [ ] Require independent acceptance before the Governor issues `AuthorizedMergeAction`; revalidate nonce, scope, base/tree, lease and fencing at act time.
- [ ] Implement `prepare → authorize → act → canary → settle`; uncertainty after an effect becomes `release_uncertain` and triggers reconciliation/quarantine.
- [ ] Correlate provider, workspace, acceptance, release/canary/revert and outcome receipts in `atlas_ledger_events` and coverage v2.
- [ ] Run fixture E2E, replay/reconciliation, neighboring gate/actuator/outcome suites, architecture bypass guard, Pint and PHPStan.

**GREEN acceptance:** the fixture write run lands only after all receipts; canary happens before terminal success; injected failure causes zero unauthorized effect or a truthful revert/uncertain state; coverage contains every correlation hash.

**Migration/data ownership:** no parallel store. Any additive column must belong to an existing owner table, be nullable/default-safe for N−1, and have migration compatibility tests.

**Failure and rollback:** flags can stop new orders and drain in-flight orders. Rollback redeploys N−1 and/or executes an authorized revert; it never reactivates v1 execution.

**Local commit:** one per vertical slice, ending with `feat: prove engineering kernel vertical execution`.

---

### Packet 5: Adapt the three modes and close compatibility/readiness

**Finding/hypothesis:** `cutover_ready` requires every mutative Dev, Forge and Autônomos surface to route through the same Kernel with writers v2-only, but it does not require executing cutover.

**Owner:** existing mode adapters plus Kernel coverage. This packet proves minimum parity; plan 06 deepens product dominance and long-horizon behavior.

**Allowed files:** existing Kernel adapters, AtlasDev/Forge/SelfConstruction integration seams, coverage registry, compatibility translators, readiness projections and matching tests.

- [ ] Extend the static mutative-surface census and write RED tests for every direct provider, filesystem, Git, release or deploy bypass.
- [ ] Write RED parity tests proving each mode produces the same order fields, 22 dispositions, evidence floor, Governor path and terminal-status semantics for an equivalent fixture.
- [ ] Route all existing mode entrypoints through Kernel; v1 façade only translates request/response and writes v2.
- [ ] Verify there is one provider invocation and one mutation per idempotency key; shadow remains replay/read-only.
- [ ] Produce Kernel, Dev, Forge and Autônomos readiness manifests from live receipts, not config intent.
- [ ] Run mode-focused tests, coverage enforce at 100%, full architecture/readiness/docs-health gates, migration status and N−1 compatibility checks.

**GREEN acceptance:** zero known bypass, 100% mutative coverage, same Kernel/bar, writers v2-only, four green readiness manifests, rollback exercised, and outcome instrumentation active.

**Migration/data ownership:** no new table. Compatibility fields, if already required by Packet 4, belong only to existing engineering run/outcome owners and remain N−1 compatible.

**Failure and rollback:** any uncovered surface or manifest failure keeps `implemented_not_cutover_ready`. Disable the affected mode, stop/drain/quarantine, and preserve v2 data; no dual executor and no v1 fallback.

**Local commit:** `feat: route all engineering modes through kernel v2`.

---

### Packet 6: Decide `cutover_ready`, start clocks honestly, and guard v1 removal

**Finding/hypothesis:** Readiness, temporal observation and comparative proof are independent states; implementation must not collapse them.

**Owner:** readiness projections, temporal certification and Rivals readers. Rivals remains the sole claim authority.

**Allowed files:** existing readiness, temporal and outcome projections; architecture/docs tests; no v1 deletion in this packet.

- [ ] Write RED state-machine tests showing that green implementation cannot imply `cutover_ready`, elapsed windows, `quality_foundry_ready`, `multiplier_proven`, `world_leading`, or `world_10x_quality_proven`.
- [ ] Require P0-13/P0-19 closed, vertical E2E, 100% coverage, four manifests, N−1 migration compatibility, exercised rollback and active outcome writers for `cutover_ready`.
- [ ] Emit observation schedules for 0h, 24h, 7d, 30d, 90d and 150d; do not synthesize elapsed observations.
- [ ] Add a reachability/usage gate for eventual v1 removal: zero observed use, rollback window closed, replay/export verified and N−1 no longer needed.
- [ ] Run state/readiness/temporal tests and final repository gates. Record blockers verbatim.

**GREEN acceptance:** the highest state equals the evidence actually present. Before operator-authorized cutover and elapsed windows, the expected ceiling is `cutover_ready` or lower, with temporal and comparative states explicitly pending.

**Migration/data ownership:** no migration and no v1 deletion. This packet reads existing readiness, temporal, outcome and usage projections.

**Failure and rollback:** contradictory or missing outcome revokes downstream eligibility and returns to the last proven state. No schema or code removal occurs under this packet.

**Local commit:** `feat: enforce honest factory readiness states`.

## Verification matrix

Per packet, run the exact focused test class, its neighboring directory, then:

```bash
/opt/homebrew/bin/php artisan atlas:ai:architecture-validate --json
/opt/homebrew/bin/php artisan atlas:ai:architecture-readiness --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json
git diff --check -- <packet-files>
```

Before any `cutover_ready` claim, also run the full suite, focused Pint/PHPStan, migration status, coverage v2 enforcement, DecisionReceipt replay, Evidence Ledger replay and all four readiness manifests. A pre-existing unrelated failure is recorded with exact command/output and remains a blocker when it intersects an acceptance condition.

## Honest terminal states and blockers

- Normal implementation state: `implemented_not_cutover_ready`.
- Readiness state: `cutover_ready`, only after Packet 6 acceptance.
- Time states: `observed_24h`, `observed_7d`, `observed_30d`, `observed_90d`, `observed_150d`, only after real windows.
- P0 partial/open, coverage below 100%, simulated provider/apply, missing ledger/evidence, unexercised rollback, migration incompatibility, external-session dependency, or any bypass keeps the plan blocked.
- This plan grants no push, deploy, production cutover, temporal certification, comparative claim or v1 removal authority.
