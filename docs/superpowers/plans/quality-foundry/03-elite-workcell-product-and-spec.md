# Elite Workcell, Product Intent, and Spec Courts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan packet-by-packet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn product intent, specification and elite workcell execution into one fail-closed path that produces a frozen `ExecutionOrder` for the existing Kernel.

**Architecture:** Deepen `AtlasProductTruthCompilerService`, `AiIntentRouter`, `AtlasAgenticWorkcellRuntimeService`, `AtlasRealEngineeringCompanyRuntimeService` and the existing `SpecAdversary`. Add only `ProductIntentCourt` and its typed case/verdict contract; do not add a second Spec Court or a second workcell runtime.

**Tech Stack:** PHP 8.4+, Laravel 13, existing Product, Mission, Engineering Company, Agentic Workcell and Engineering Kernel services, canonical ledgers, PHPUnit.

## Source contract and dependencies

- Master: `docs/superpowers/plans/2026-07-09-atlas-quality-foundry-world-10x.md`, sections 2, 3, 6, 7 and 15.
- Predecessor: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`, Kernel `SpecAdversary`, Workcell and mode contracts.
- Depends on plan 01 for typed Kernel/order/outcome and plan 02 for canonical 22-role dispositions/evidence independence.
- Consumes a frozen world-snapshot ref from the current Software Twin/AURG owners; plan 04 deepens freshness/calibration but does not change the Court contract.
- Produces adjudicated ProductIntent, frozen spec and admitted workcell inputs for plans 04–06.

## Global constraints

- Dev, Forge and Autônomos use identical Product/Spec/Workcell gates; mode affects operator presence and duration, not truth or quality.
- All 22 roles participate; workcell topology changes ordering/depth, never membership.
- No human specialist dependency. Missing domain/UX/security evidence is a machine-readable block or bounded clarification to the operator who owns intent/authority, not a hidden human review lane.
- Builder, verifier and final certifier have independent contexts. R4/R5 use different model families when available; otherwise hold.
- Candidates use isolated sandboxes; integration is serial; the judge sees spec/artifact/evidence, not the author's defense.
- No runtime v3, second Product Truth compiler, second Spec Court, second workcell runtime, second ledger, duplicate world model or shell.
- Cost/time are measured after eligibility and quality; cheaper-but-worse never wins.
- Claims remain Rivals-only. A frozen spec or certified workcell is not a comparative or temporal claim.
- Preserve concurrent WIP on local `main`; no push, deploy or cutover authorization.

## Existing owners to deepen

- Product truth: `app/Services/Ai/Product/AtlasProductTruthCompilerService.php`.
- Intent routing: `app/Services/Ai/AiIntentRouter.php` and existing Intent Envelope types.
- Spec adversary: `app/Services/Ai/EngineeringKernel/Spec/SpecAdversary.php`, `SovereignSpecFloor`, `AtlasSpecGateAdapter` and existing witnesses/oracles.
- Workcells: `app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php` and certification service/models.
- Company roster/runtime: `app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php` and role-run owners.
- Kernel: `ExecutionOrder`, `EliteExecutorKernel`, `AcceptanceBundle`; this plan feeds them and does not fork them.

## Fixed public interface

```php
ProductIntentCourt::adjudicate(ProductIntentCase $case): ProductIntentVerdict;
```

`ProductIntentVerdict` contains schema/status; problem; user; value; metric and observation window; source/provenance refs; constraints; non-goals; hypotheses; uncertainties; alternatives; falsifiers; side effects; acceptance; release/outcome policy; world-snapshot ref/hash; canonical ProductIntent hash; and blocking reasons.

The existing Spec interface remains:

```php
SpecAdversary::contest(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): SpecVerdict;
```

The ProductIntent hash and frozen spec hash flow unchanged into `ExecutionOrder`.

## Allowed subsystem and file families

- `app/Services/Ai/Product/**`
- `app/Services/Ai/AiIntentRouter.php`
- `app/Services/Ai/EngineeringKernel/Spec/**`
- `app/Services/Ai/AgenticWorkcell/**`
- `app/Services/Ai/EngineeringCompany/**`
- Minimal Kernel order/acceptance adapters needed to consume frozen hashes; do not broaden Kernel internals.
- Existing Product, Workcell and Engineering Company models/tables; additive fields only when proven necessary.
- Matching tests under `tests/{Unit,Feature}/Ai/{Product,EngineeringKernel/Spec,AgenticWorkcell,EngineeringCompany}/` and cross-mode fixtures.
- Canonical Product/Kernel owner docs touched by the finalized contracts.

For every packet, attach RED and GREEN focused/neighboring outputs, canonical evidence refs, an explicit migration decision, owner-doc delta (or `docs_unchanged` with reason), local commit, remaining blockers and the named next packet.

---

### Packet 1: ProductIntent typed case/verdict and Product Truth integration

**Finding/hypothesis:** Product Truth currently compiles useful structure, but a delivery can proceed without a single adjudicated verdict binding problem, user, value, measurement, uncertainties and release/outcome expectations.

**Owner:** new `ProductIntentCourt` inside the existing Product service family; `AtlasProductTruthCompilerService` remains the compiler and source extractor.

**Allowed files:** Product family, existing intent router/envelope adapters and focused tests. No new persistence table.

- [ ] Write RED value-object tests for missing problem/user/value, missing metric/window, absent source provenance, contradictory constraint/acceptance, unbounded side effect, missing falsifier, stale world snapshot and non-deterministic hash.
- [ ] Write RED tests showing mode cannot change the verdict for an equivalent case and operator wording cannot bypass required facts.
- [ ] Implement immutable `ProductIntentCase` and `ProductIntentVerdict` in the Product family, with deterministic canonical hashing and explicit `admitted|revise|refused|held` status.
- [ ] Adapt `AtlasProductTruthCompilerService` output into the case rather than replacing its compiler or maintaining a second truth document.
- [ ] Record `unit.frozen` only for admitted ProductIntent and include source/world hashes; replay of identical input is idempotent.
- [ ] Run focused Product tests, neighboring Mission hash/IntentRouter tests, replay and cross-mode parity tests.

**GREEN acceptance:** contradiction, absent metric/window, unsupported certainty or stale/missing required world facts block; an admitted verdict is deterministic and carries every fixed field.

**Migration/data ownership:** ProductIntent is stored as a canonical artifact/receipt referenced by `atlas_ledger_events` and Engineering Run records. Do not add a ProductIntent event ledger.

**Failure and rollback:** disable Court enforcement only into observe mode; orders without an admitted verdict remain held. Existing compiler output remains readable.

**Local commit:** `feat: adjudicate canonical product intent`.

---

### Packet 2: Falsification probes and bounded clarification

**Finding/hypothesis:** A plausible product narrative can freeze despite ambiguity, metric gaming or untested alternatives. Deterministic and model-assisted falsification must only raise objections; it cannot self-admit.

**Owner:** ProductIntentCourt plus existing Product Truth/Intent Envelope. No separate research court.

**Allowed files:** Product family, current world/context readers, focused tests.

- [ ] Write RED cases for vanity metric, contradictory sources, proxy-user mismatch, impossible observation window, hidden non-goal, irreversible side effect, privacy/security omission and alternative that dominates the proposed solution.
- [ ] Add deterministic probes for required fields/contradictions and independent provider-assisted probes for domain/UX/product uncertainty.
- [ ] Ensure provider probes can only add `revise|refused|held` evidence; they never grant admission.
- [ ] Route genuine operator-owned ambiguity to one bounded clarification contract. Runtime specialist review is never required.
- [ ] Hash each probe input/output and bind accepted resolutions to a new ProductIntent version; silent drift is impossible.
- [ ] Run golden corpus, adversarial mutation, provider-unavailable and replay tests.

**GREEN acceptance:** every seeded contradiction or gaming case is caught; provider outage preserves deterministic blocking rules; a changed answer creates a new intent version/hash.

**Migration/data ownership:** no migration. Probe and resolution receipts use the existing ProductIntent artifact and canonical events.

**Failure and rollback:** provider-assisted probe outage yields `held` only where the risk policy requires it; otherwise the deterministic floor decides. No human specialist fallback.

**Local commit:** `feat: falsify product intent before execution`.

---

### Packet 3: Deepen the existing SpecAdversary

**Finding/hypothesis:** A ProductIntent verdict can still drift or lose NFR/migration/rollback/security detail before execution unless the existing SpecAdversary binds the complete frozen contract.

**Owner:** existing `SpecAdversary`; do not create `ProductSpecCourt`, `SpecCourtV2` or another acceptance compiler.

**Allowed files:** `EngineeringKernel/Spec/**`, minimal ProductIntent adapter, focused spec tests.

- [ ] Write RED tests for ProductIntent-hash mismatch, stale world snapshot, missing invariants/NFR/security/accessibility/observability/compatibility/migration/rollback/roles/oracles/invalidators, and spec mutation after freeze.
- [ ] Write RED self-review tests where spec author and final spec witness are the same identity.
- [ ] Extend `SpecDraft`/`SpecReceipt` with the fixed ProductIntent/world/evidence bindings and deterministic frozen hash.
- [ ] Deepen `SovereignSpecFloor` and witnesses; model shadows may contest but cannot grant freeze.
- [ ] Require a new version/hash whenever acceptance, scope, world snapshot or relevant constraint changes.
- [ ] Emit `unit.frozen` for the spec and pass the exact hash into `ExecutionOrder`.
- [ ] Run Spec contract/golden/ambiguity/shadow/oracle tests plus cross-mode and mutation suites.

**GREEN acceptance:** no drift, missing NFR or self-witness reaches execution; equivalent cases freeze identically across modes; revised facts create a new version.

**Migration/data ownership:** reuse current spec receipts/artifacts and canonical events. No Spec ledger table.

**Failure and rollback:** if evidence/world facts become stale before execution, invalidate the order and return to Product/Spec adjudication; never patch the frozen hash in place.

**Local commit:** `feat: bind product truth into frozen specifications`.

---

### Packet 4: Canonical 22-role workcell admission and topology

**Finding/hypothesis:** Workcell topology may currently be selected as a convenience and can omit required role participation or independence. Admission must derive topology/depth from order risk while retaining all 22 roles.

**Owner:** `AtlasAgenticWorkcellRuntimeService` consuming the canonical Engineering Company roster from plan 02.

**Allowed files:** AgenticWorkcell/EngineeringCompany families, Workcell models and focused tests.

- [ ] Write RED tests for a missing role, overlapping builder/verifier/final-certifier, ownership overlap, shared sandbox between candidates, unsupported topology, and mode-specific bar reduction.
- [ ] Define mapping from `single|candidate_set|workcell|DAG|portfolio` to execution ordering and witness depth only; membership remains the exact 22-role roster.
- [ ] Make workcell admission require frozen ProductIntent/spec/world hashes, authority, allowed scope, risk/depth and evidence policy from `ExecutionOrder`.
- [ ] Allocate isolated candidate sandboxes and explicit ownership; integration lane is serial and protected by reservation/fencing.
- [ ] Give judges only frozen spec, candidate artifact and independent evidence; author explanations are excluded from adjudication input.
- [ ] Run Workcell runtime/certification, Engineering Company role, ownership overlap, sandbox and parity tests.

**GREEN acceptance:** every workcell has 22 dispositions, independent contexts, isolated candidates, serial integration and deterministic ownership; missing verifier holds the delivery.

**Migration/data ownership:** extend existing `AtlasAgenticWorkcell*`/`AiEngineeringCompany*` records only for order/spec/snapshot hash refs, fencing or role-run links. Events stay canonical.

**Failure and rollback:** cancel/kill leaves candidate sandboxes and receipts for replay, releases reservations after lease expiry and performs no partial integration.

**Local commit:** `feat: admit elite workcells from frozen orders`.

---

### Packet 5: Circuit breakers, candidate competition and independent certification

**Finding/hypothesis:** Unbounded retries or homogeneous candidates can spend more without improving evidence. Explicit circuit breakers and frontier tests will force replan, approach change or durable pause.

**Owner:** existing Workcell runtime and certification; Atlas Decide selection remains plan 04.

**Allowed files:** AgenticWorkcell and EngineeringCompany families, focused tests.

Circuit breakers are fixed:

- same failure fingerprint three times → replan;
- two rounds without evidence delta → change approach;
- ambiguous spec → Product/Spec Courts;
- all eligible providers unavailable → durable pause;
- insufficient authority, inconsistent ledger or irreversibility outside envelope → hard stop;
- candidate with no frontier improvement ends that line, not the mission.

- [ ] Write RED state-machine tests for each breaker, kill/restart at every boundary and repeated identical evidence.
- [ ] Add candidate-set tests proving R5 uses competing approaches and different verifier families when available.
- [ ] Persist fingerprints, evidence deltas, approach IDs and terminal reasons in existing workcell events/outcomes.
- [ ] Ensure final certifier never edits code and cannot certify without the complete independent evidence bundle.
- [ ] Reconcile incomplete workcells after crash without duplicate provider call, integration or disposition.
- [ ] Run focused Workcell state/recovery/certification tests and neighboring Kernel replay tests.

**GREEN acceptance:** no infinite identical retry, no spend without evidence delta, no same-context self-certification, safe restart and truthful `held|blocked|refused` states.

**Migration/data ownership:** no new table. Persist fingerprints, evidence deltas and terminal reasons through existing Workcell events/outcomes.

**Failure and rollback:** stop/drain workcells, quarantine uncertain artifacts and preserve receipts. Never switch to a human specialist or lower risk depth.

**Local commit:** `feat: add workcell evidence circuit breakers`.

---

### Packet 6: End-to-end Product → Spec → Workcell → Kernel parity

**Finding/hypothesis:** Component correctness is insufficient unless an equivalent request produces the same frozen truth, roster and acceptance path in all modes.

**Owner:** adapters around the existing Product, Spec, Workcell and Kernel owners.

**Allowed files:** cross-mode fixture tests and minimal adapters in the families above; no new orchestration runtime.

- [ ] Write RED end-to-end parity tests that fail on divergent intent/spec/world/order hashes, roster/evidence depth or mode-specific fail-open behavior.
- [ ] Build R0, R3 and R5 fixtures and run each through Dev, Forge and Autônomos entry adapters.
- [ ] Assert identical ProductIntent/spec/world hashes, risk/depth policy, 22-role membership, evidence floor and Kernel order; only operator/duration/topology fields may differ.
- [ ] Inject contradiction, spec drift, ownership collision, provider outage, verifier outage and crash; assert the same fail-closed decision in every mode.
- [ ] Assert zero direct provider, workspace, acceptance or release bypass from Product/Spec/Workcell paths.
- [ ] Produce a readiness manifest with live receipt/test refs and no unresolved parity mutation.

**GREEN acceptance:** the complete pre-execution chain is deterministic, replayable and mode-parity; no Court or Workcell can issue a comparative claim or terminal release state.

**Migration/data ownership:** no migration. This packet reads and validates existing ProductIntent/spec/workcell/order receipts.

**Failure and rollback:** keep the chain in observe/shadow read-only until parity passes. Any mismatch blocks enforcement for all modes rather than allowing a divergent mode path.

**Local commit:** `test: prove product spec and workcell parity`.

## Verification and rollout

Each packet runs its exact focused test, the neighboring subsystem directories, focused Pint/PHPStan, architecture validation, docs health and:

```bash
git diff --check -- <packet-files>
```

Rollout is read-only adjudication → shadow comparison with existing compiler/spec/workcell outputs → sandbox enforcement → eligible mode adapters. Do not execute production release/cutover under this plan.

## Honest states and blockers

- ProductIntent/spec/workcell implementation does not imply `art_grade_delivery`, `quality_foundry_ready`, an elapsed outcome or a comparative claim.
- Contradiction, absent metric/window, stale world snapshot, spec drift, missing role/verifier, shared sandbox, ownership overlap, unresolved circuit breaker, inconsistent ledger or provider unavailability at required depth is a blocker/hold.
- `planned` or `frozen` is never execution or sign-off.
- This plan authorizes no push, deploy, production cutover, temporal claim or Rivals claim.
