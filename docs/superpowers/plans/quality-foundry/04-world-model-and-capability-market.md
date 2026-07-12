# Engineering World Model and Capability Market Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan packet-by-packet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every execution a frozen, provenance-rich Engineering World Model snapshot and let Atlas Decide clear the best eligible capability by proven quality before time or cost.

**Architecture:** Keep `AtlasSoftwareTwinRuntimeService` as the façade over AURG, Code World Model and `WorldModelGraphRanker`. Add one internal `CapabilityMarketClearingService` to Atlas Decide; reuse provider, outcome, experiment and ledger owners instead of creating a market runtime, duplicate graph or duplicate outcome store.

**Tech Stack:** PHP 8.4+, Laravel 13, PostgreSQL 16, existing Software Twin/AURG/Code World Model models and services, Atlas Decide, canonical events/outcomes, PHPUnit.

## Source contract and dependencies

- Master: `docs/superpowers/plans/2026-07-09-atlas-quality-foundry-world-10x.md`, sections 3.2, 8, 9 and 15.
- Predecessor: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`, order hashes, provider route, evidence and outcome contracts.
- Depends on plan 01 for typed `ExecutionOrder`/outcome and canonical receipts; plan 02 for quality dispositions; plan 03 for ProductIntent/spec snapshot consumption.
- Produces frozen world snapshots and selected capability receipts consumed by Verification, modes, causal learning and Rivals.

## Global constraints

- One Engineering World Model: deepen the Software Twin/AURG/Code World Model stack; no second graph or world-model table family.
- Every fact has provenance, workspace, temporal validity, freshness, calibrated confidence, consumer and prediction→observed linkage.
- Unknown or stale remains unknown/stale. Simulation and prediction are not observed evidence.
- Each `ExecutionOrder` freezes a snapshot hash; later facts cannot rewrite the order.
- Capability selection order is fixed: eligibility/authority → risk fit → proven quality → evidence/calibration → diversity → availability → time → cost.
- Cheaper or faster but lower-quality capability never wins. Cost and time are still measured.
- Same-model uplift and cross-provider generalization require preregistered causal evidence; routing data alone cannot issue a claim.
- Dev, Forge and Autônomos consume the same world/market contracts.
- Only Rivals issues comparative claims. Atlas Decide routes; it does not claim superiority.
- No runtime v3, duplicate ledger, duplicate outcomes, duplicate world model, human specialist dependency or shell.
- Preserve concurrent WIP on local `main`; no push, deploy or cutover authorization.

## Existing owners to deepen

- Façade: `app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php`.
- AURG: `app/Services/Ai/Reality/AtlasRealityGraph*` plus `AtlasAurgNode`/`AtlasAurgEdge`.
- Code world model: `AiCodebaseWorldModel`, node/edge models and Autonomous Engineering world-model services.
- Ranking: `app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php`.
- Decide: `app/Services/Ai/AtlasDecide/**`, including provider key resolution, cost/outcome routing and live outcome feedback.
- Evidence/outcomes: canonical ledger, `AiRunOutcome`, experiment receipts and Rivals evidence readers.

## World snapshot contract

A frozen snapshot includes schema/version/hash; workspace/base commit; code symbols/contracts; deploy/runtime topology; flags/config hashes; incidents; ownership and active claims; outcomes; performance/security/docs/decision facts; tool/provider versions; fact provenance; `valid_from`/`valid_to`; observed-at/freshness; confidence/calibration ref; unknown/stale sets; consumer; and prior prediction→observed links.

`CapabilityMarketClearingService` is internal to Atlas Decide and exposes one seam:

```php
CapabilityMarketClearingService::clear(CapabilityMarketRequest $request): CapabilityMarketDecision;
```

The request contains order/snapshot/authority hashes, risk/depth, required capabilities, eligible routes, diversity and experiment constraints. The decision contains selected route/candidate set, rejected alternatives with reason codes, evidence/calibration refs, availability, estimated time/cost, exploration/preregistration ref, decision hash and `claim_eligible=false`.

## Allowed subsystem and file families

- `app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php`
- `app/Services/Ai/Reality/**`
- `app/Services/Ai/AutonomousEngineering/WorldModel/**`
- `app/Services/Ai/AtlasDecide/**`
- Existing world-model, AURG, snapshot, engineering run and outcome models/tables.
- Additive migrations on those existing owner tables only, with workspace/temporal/hash indexes justified by tests.
- Minimal Kernel order adapter to consume the snapshot/market-decision hashes.
- Matching tests under `tests/{Unit,Feature}/{Ai,Reality}/` for Software Twin, AURG, world model, Atlas Decide and Kernel correlation.
- Canonical owner docs for Software Twin/AURG/Atlas Decide contracts.

For every packet, attach RED and GREEN focused/neighboring outputs, canonical evidence refs, an explicit migration decision, owner-doc delta (or `docs_unchanged` with reason), local commit, remaining blockers and the named next packet.

---

### Packet 1: Unified fact schema and frozen Software Twin snapshot

**Finding/hypothesis:** Existing graphs can answer different slices, but an execution may freeze a snapshot that omits provenance, temporal validity or a relevant operational fact. A façade-level schema can unify references without copying graphs.

**Owner:** `AtlasSoftwareTwinRuntimeService`; underlying AURG and Code World Model remain authoritative for their facts.

**Allowed files:** Software Twin façade, current AURG/world-model value objects/readers, existing snapshot model/table and focused tests.

- [x] Write RED tests for cross-workspace leakage, missing provenance, missing validity/freshness, duplicate conflicting fact, unsupported fact type, absent consumer and non-deterministic snapshot hash.
- [x] Add RED fixtures covering code, contract, deploy/runtime, flag, incident, ownership, outcome, performance, security, docs, decisions, concurrent work and tool/provider versions.
- [ ] Define a normalized reference envelope at the façade; keep source payloads in existing owners and include only provider-safe refs/hashes in the snapshot.
- [x] Resolve facts as-of workspace/base commit and observation time; preserve conflict/unknown rather than picking a favorable fact.
- [ ] Freeze a deterministic snapshot and pass its hash/ref into ProductIntent/spec/order without mutating prior snapshots.
- [ ] Run Software Twin, AURG temporal/query, world-model workspace and snapshot hash tests.

**GREEN acceptance:** all required fact families are representable; workspace/time scoping is exact; unknown/conflict is explicit; the same as-of query hashes identically; later ingestion does not rewrite a frozen snapshot.

**Migration/data ownership:** reuse `atlas_aurg_*`, `ai_codebase_world_models*` and `atlas_software_twin_snapshots`. Add only proven missing temporal/workspace/hash fields or indexes; no generalized fact table.

**Failure and rollback:** snapshot-builder failure or required stale fact yields `held` with missing/stale reason. Existing graph readers remain intact behind the façade.

**Local commit:** `feat: freeze provenance-rich software twin snapshots`.

---

### Packet 2: Freshness, confidence calibration and prediction→observed loop

**Finding/hypothesis:** Confidence without observed calibration becomes certainty theater. Each prediction must later meet an observed outcome, and misses must lower confidence.

**Owner:** Software Twin/AURG temporal owners plus existing outcome writer; no learning promotion in this packet.

**Allowed files:** Reality/world-model services, Software Twin, existing outcome/temporal services and focused tests.

- [ ] Write RED tests for stale fact treated as current, prediction treated as evidence, missing observed counterpart, contradictory outcomes, confidence that never decays and simulation promoted to observed.
- [ ] Define freshness policy per fact family with explicit `fresh|stale|unknown|conflicted`; no global favorable default.
- [ ] Record prediction receipt with snapshot/order hash, expected observation and due window; join only to a matching real outcome/release hash.
- [ ] Update calibration counters/intervals from observed results; wrong or unobserved predictions reduce or withhold confidence.
- [ ] Expose calibration and unresolved predictions through the Software Twin snapshot; do not rewrite original predictions.
- [ ] Run temporal as-of, delayed/contradictory outcome, calibration and replay tests.

**GREEN acceptance:** stale remains stale, prediction never passes as observation, calibration changes in the correct direction, contradictory outcomes block favorable confidence and replay is deterministic.

**Migration/data ownership:** prediction/observation events live in `atlas_ledger_events`; existing snapshots/outcomes store refs/projections. No prediction ledger.

**Failure and rollback:** disable calibration updates while continuing to expose prior data as stale; never reset confidence to a favorable default.

**Local commit:** `feat: calibrate engineering world predictions`.

---

### Packet 3: Capability eligibility and quality-first market clearing

**Finding/hypothesis:** Current routing can optimize convenience/cost before proven risk-fit quality. A deterministic clearing service inside Atlas Decide can reject ineligible routes first and explain every decision.

**Owner:** new internal `AtlasDecide\CapabilityMarketClearingService`; existing provider registry/outcome feedback remains source data.

**Allowed files:** `app/Services/Ai/AtlasDecide/**`, existing provider registry adapters, minimal Kernel order consumer and focused tests.

- [x] Write RED table tests for expired/revoked authority, disallowed provider/tool, risk mismatch, uncalibrated evidence, unavailable route, homogeneous R4/R5 verifier set and cheaper-but-worse candidate.
- [x] Implement immutable request/decision types and the exact lexicographic selection order fixed above.
- [x] Require quality evidence to match capability, risk, stack, model/provider version and relevant observation window; missing evidence is unknown, not zero loss.
- [x] Select a candidate set when topology/diversity requires it; never choose an ineligible route for exploration.
- [x] Persist a decision receipt with rejected alternatives/reasons and pass its hash into `ExecutionOrder`.
- [ ] Run deterministic replay, permutation/property, cost-outcome router, provider key resolver and Kernel order-correlation tests.

**GREEN acceptance:** authority/risk ineligibility always wins over score; proven quality wins before time/cost; route ordering is deterministic; replay yields the same decision hash; claims remain false/ineligible.

**Migration/data ownership:** use existing provider/model registries, outcome evidence and canonical events. Do not add a market ledger or provider score table.

**Failure and rollback:** if no route is eligible, return durable `held` with reason codes. Never silently use a bare provider, a human specialist or a lower quality threshold.

**Local commit:** `feat: clear capabilities by proven quality`.

---

### Packet 4: N×M capability matrix and causal route lifecycle

**Finding/hypothesis:** Atlas cannot know whether it amplifies models or absorbs a new provider from unpaired production routing. The matrix must preregister comparable arms and keep experimentation separate from claims.

**Owner:** Atlas Decide experiment routing; Rivals later adjudicates comparative evidence.

**Allowed files:** AtlasDecide experiment/routing services, existing experiment receipts, provider adapters, fixtures/tests. Rivals files are read-only unless an agreed receiving contract is missing and active WIP is reconciled.

The matrix crosses efficient/intermediate/frontier models with bare/Atlas/competitor/full-power harnesses and tests:

- same-model Atlas uplift;
- cross-provider generalization;
- efficient+Atlas versus frontier bare;
- new-model absorption without a code fork.

- [ ] Write RED tests for unequal snapshots/resources, missing preregistration, post-result arm mutation, best-run cherry-pick, provider-specific fork, absent bare control and route promotion before outcome.
- [ ] Create experiment assignments before execution and bind order, snapshot, model/provider/harness versions, resources, metric and observation window.
- [ ] Route new capabilities through `shadow → limited_traffic → causal_evaluation → promoted|revoked`; shadow is read-only and cannot mutate twice.
- [ ] Keep intent-to-treat failures/timeouts/refusals/rollbacks in the denominator and forward evidence to Rivals without issuing a claim.
- [ ] Revoke routing promotion after material late regression; preserve the original decision/assignment events.
- [ ] Run paired fixture, assignment replay, provider-absorption, late-regression and no-claim tests.

**GREEN acceptance:** every compared arm is frozen/equivalent, new providers plug in through existing ports, route promotion requires causal outcome evidence, and no Atlas Decide service emits a comparative claim.

**Migration/data ownership:** reuse experiment refs, ledger events and outcomes; no N×M result database is added.

**Failure and rollback:** disable the route, drain in-flight work and return to the last proven route; do not delete experiment failures or rewrite assignments.

**Local commit:** `feat: govern capability market experiments`.

---

### Packet 5: Cross-mode world/market parity and operational resilience

**Finding/hypothesis:** Different mode adapters may freeze different facts or route differently for equivalent orders, undermining the shared factory.

**Owner:** existing mode adapters consuming Software Twin and Atlas Decide.

**Allowed files:** cross-mode fixtures, Software Twin/Atlas Decide adapters and focused tests; no mode-specific market implementation.

- [ ] Write RED parity/recovery tests that fail when equivalent modes freeze different facts, select different eligible routes or reconcile to different terminal decisions.
- [ ] Run equivalent R0/R3/R5 requests through Dev, Forge and Autônomos and assert identical snapshot and market-decision hashes for identical as-of facts/authority.
- [ ] Inject stale graph, graph conflict, outcome lag, provider outage, revoked authority, calibration loss and crash between decision/order persistence.
- [ ] Assert every mode returns the same hold/retry/refusal semantics and no mode bypasses the frozen snapshot or selected route.
- [ ] Reconcile an interrupted decision idempotently without duplicate provider invocation or event.
- [ ] Run cross-mode parity, architecture bypass, ledger replay and neighboring Kernel tests.

**GREEN acceptance:** equivalent facts lead to equivalent decisions across modes; failure/restart is deterministic; no cross-workspace leak or mode-specific quality bar exists.

**Migration/data ownership:** no migration. The packet validates existing snapshot, market-decision, order and event records.

**Failure and rollback:** disable market enforcement for all modes into observe-only; do not allow one mode to retain a divergent production route.

**Local commit:** `test: prove world model and market parity`.

---

### Packet 6: Rollout gates and honest capability state

**Finding/hypothesis:** A working world model/market is not evidence of multiplier or world leadership. Readiness must expose operational and evidence gaps explicitly.

**Owner:** existing readiness/status projections; Rivals reads evidence and decides claims.

**Allowed files:** Software Twin/Atlas Decide readiness projections, canonical docs and focused tests.

- [ ] Define readiness checks for fact-family coverage, workspace isolation, temporal freshness, calibration, snapshot determinism, no unknown critical fact, quality-first selection, route replay and no claim writer.
- [ ] Add RED state tests proving green readiness cannot set `multiplier_proven`, `world_leading` or `world_10x_quality_proven`.
- [ ] Roll out as read-only snapshot comparison, market shadow, limited sandbox traffic and governed mode enablement after parity.
- [ ] Produce a manifest containing real test/receipt/evidence refs and unresolved unknown/stale facts.

**GREEN acceptance:** readiness is truthful and operational; comparative state remains `world_10x_quality_proof_pending` until Rivals evidence exists.

**Migration/data ownership:** no migration. Readiness is a projection over existing snapshot, decision, experiment and outcome evidence.

**Failure and rollback:** unknown/stale critical facts or quality/calibration regression disables promotion and returns to the last proven route; no cutover or claim occurs.

**Local commit:** `feat: expose honest world and capability readiness`.

## Verification and rollout

Each packet runs focused and neighboring tests, focused Pint/PHPStan, architecture validation, docs health and:

```bash
git diff --check -- <packet-files>
```

World snapshots begin read-only. Market decisions begin shadow-only. Limited sandbox traffic requires deterministic parity and successful rollback/revocation tests. Production routing/cutover needs separate authority.

## Honest states and blockers

- World-model and capability-market component readiness creates no new official delivery state; it is evidence inside the highest already-proven master state.
- Cross-workspace leakage, stale/unknown critical fact, uncalibrated confidence, prediction-as-evidence, non-deterministic snapshot/decision, cheap-worse selection, absent eligible route, provider-specific fork or external claim issuance blocks rollout.
- The correct comparative state without Rivals evidence is `world_10x_quality_proof_pending`.
- This plan authorizes no push, deploy, production cutover or claim.
