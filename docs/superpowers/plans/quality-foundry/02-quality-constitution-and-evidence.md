# Quality Constitution and Evidence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan packet-by-packet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Quality Constitution executable and fail-closed so every Dev, Forge and Autônomos delivery receives 22 explicit role dispositions backed by independent evidence, while Rivals remains the only comparative claim authority.

**Architecture:** Extend the existing Engineering Company roster, Agentic Workcell certification, Engineering Kernel acceptance bundle and canonical ledgers. Add only the value objects and guards needed to express constitutional decisions; do not build a second role catalog, court, ledger, outcome system or certification runtime.

**Tech Stack:** PHP 8.4+, Laravel 13, existing Engineering Company/Workcell models, Atlas Evidence Ledger, `atlas_ledger_events`, `ai_run_outcomes`, PHPUnit, architecture guards.

## Source contract and dependencies

- Master: `docs/superpowers/plans/2026-07-09-atlas-quality-foundry-world-10x.md`, sections 2, 3.2–3.5, 5, 15 and 16.
- Predecessor: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`, AcceptanceBundle, honesty floor, outcome and evidence contracts.
- Depends on `01-factory-v2-closure.md`: typed order/outcome, canonical receipt chain, all-mode Kernel routing and honest readiness states.
- Produces the roster/disposition/evidence contract consumed by plans 03–08.

## Global constraints

- Same Kernel and same conjunctive quality bar for Dev, Forge and Autônomos.
- All 22 roles always receive `pass|block|not_applicable`; risk changes depth and witness set, never membership.
- Missing role, evidence, provenance, signer, world snapshot, ledger receipt or required oracle is a block.
- `not_applicable` requires a deterministic rule, rationale, evidence and independent signature.
- Author, builder, verifier and final certifier identities must be distinguishable; an author cannot certify their own output.
- Cost and time are recorded but cannot offset a red quality dimension.
- No human specialist is required. Independent model/tool families and implementation-independent oracles provide review.
- Only Rivals issues or revokes `multiplier_proven`, `world_leading`, `world_10x_quality_proven` or any `world_*` claim.
- No runtime v3, second role registry, second evidence ledger, second outcome store, second world model or shell.
- Local-main override preserves concurrent WIP. No push, deploy, cutover or temporal/comparative claim is authorized.

## Existing owners to deepen

- Role execution and storage: `AtlasRealEngineeringCompanyRuntimeService`, `AiEngineeringCompanyRoleRun` and existing Engineering Company models/tables.
- Workcell membership/certification: `AtlasAgenticWorkcellRuntimeService`, `AtlasAgenticWorkcellCertificationService`, `AtlasAgenticWorkcell*` models.
- Kernel decision: `AcceptanceGate`, `AcceptanceBundle`, `CertVerdict`, `SovereignHonestyFloor`, `FalseClaimInvariant`.
- Evidence: `AtlasEvidenceLedger`, `atlas_ledger_events`, current receipt adapters.
- Outcomes/temporal projections: `AiRunOutcome`, `AiTemporalCertification`, `AtlasEngineeringOutcomeRecorder`, `AtlasTemporalCertificationService`.
- Comparative claims: existing `app/Services/Ai/Rivals/**` only.

## Constitutional vocabulary

The canonical 22 role keys, in this exact order, are:

```text
product_strategy, product_management, domain_research, ux_research,
interaction_design, visual_design, software_architecture, backend,
frontend, mobile, data, qa_test, appsec_privacy,
performance_resilience, devops_sre, observability, release,
technical_docs_dx, maintainability_simplification, outcome_analysis,
evidence_audit, final_certification
```

Each persisted disposition contains: schema, run/delivery/order hashes, role key, `pass|block|not_applicable`, risk/depth, applicability rule/hash, rationale, evidence refs/hashes, author/builder/verifier/final-certifier identities, signer/provenance, timestamp and canonical receipt hash.

The public Quality Foundry vocabulary above is authoritative. The existing runtime aliases
(`architecture`, `qa_testing`, `documentation_dx`, `maintenance_simplification`) are accepted only
as a compatibility input at the Kernel boundary and are normalized to the four canonical keys;
new Court facts and public rosters must use the canonical names.

Official quality states are conjunctive:

- `art_grade_delivery`: every applicable role passes and no red is hidden.
- `multiplier_proven`: causal same-model uplift, issued only by Rivals.
- `world_leading`: scoped superiority over the strongest baselines, issued only by Rivals.
- `world_10x_quality_proven`: Rivals proves `upper_IC95(quality_loss_atlas / quality_loss_best_baseline) <= 0.10` with no critical dimension worse.

None of these implies cutover or an elapsed observation window.

## Allowed subsystem and file families

- `app/Services/Ai/EngineeringCompany/**`
- `app/Services/Ai/AgenticWorkcell/**`
- `app/Services/Ai/EngineeringKernel/{AcceptanceBundle.php,AcceptanceGate.php,CertVerdict.php,SovereignHonestyFloor.php,FalseClaimInvariant.php,ExecutionEvidence.php}` and a focused `Quality/**` subnamespace.
- `app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php`
- Existing outcome and temporal writers/readers under `app/Services/Ai/{Aemor,Compounding}/**`.
- `app/Models/{AiEngineeringCompanyRoleRun,AiRunOutcome,AiTemporalCertification}.php` and existing Workcell models.
- Additive migrations on existing owner tables only; `atlas_ledger_events` remains the event authority.
- `app/Services/Ai/Rivals/**` only for the boundary that receives dispositions and issues/revokes claims; reconcile active Rivals WIP first.
- Matching tests under `tests/{Unit,Feature}/Ai/{EngineeringCompany,AgenticWorkcell,EngineeringKernel,Aemor,Rivals}/` and architecture tests.

For every packet, attach RED and GREEN focused/neighboring outputs, canonical evidence refs, the explicit migration decision, owner-doc delta (or `docs_unchanged` with reason), local commit, remaining blockers and the named next packet.

---

### Packet 1: Canonical 22-role roster and risk-depth policy

**Finding/hypothesis:** Existing registries may omit roles or let topology/mode alter membership. One canonical roster extended in place, plus a deterministic R0–R5 depth policy, will give all modes the same bar.

**Owner:** existing Engineering Company role registry/runtime; Workcell consumes it and cannot maintain its own list.

**Allowed files:** EngineeringCompany and AgenticWorkcell families, `AiEngineeringCompanyRoleRun`, focused tests. No new roster table.

- [x] Write RED tests asserting exact ordered membership of all 22 keys for Dev, Forge and Autônomos across R0–R5 and every topology.
- [x] Write RED mutation tests that delete/rename a role, auto-pass a role, or let `single|candidate_set|workcell|DAG|portfolio` change membership.
- [x] Run Engineering Company and Workcell roster suites; expected RED is missing membership or mode/topology-specific drift.
- [x] Extend the existing registry to be the sole source of the 22 role definitions.
- [x] Implement deterministic depth profiles: R0 applicability/minimal evidence; R1 light independent review/local tests; R2 standard review/contracts/integration; R3 multiple verifiers/regression/compatibility/controlled release; R4 security/mutation/property/chaos/rollback; R5 competing candidates, different-family verifiers and disaster drills.
- [x] Persist selected depth separately from mode, complexity, duration and topology.
- [x] Run focused suites plus neighboring Workcell/Engineering Company tests, architecture guard, Pint and PHPStan.

**GREEN acceptance:** all 22 roles appear once, in order, for every mode/risk/topology; only depth changes; duplicate or unknown keys are refused.

**Migration/data ownership:** reuse `AiEngineeringCompanyRoleRun`; add nullable/versioned fields only if the live table cannot hold role key, disposition and hashes. Migration is additive and N−1 compatible.

**Failure and rollback:** disabling the new schema maps all incomplete runs to `held`; it never falls back to a smaller roster or synthesized pass.

**Local commit:** `feat: enforce canonical quality role roster`.

---

### Packet 2: Typed dispositions and fail-closed applicability

**Finding/hypothesis:** Unstructured statuses and favorable defaults permit absent evidence or forged N/A to look green. A typed, hash-bound disposition written through existing owners closes the gap.

**Owner:** new internal `EngineeringKernel\Quality\RoleDisposition` value object; persistence remains Engineering Company role runs plus canonical ledger.

**Allowed files:** focused EngineeringKernel `Quality/**`, EngineeringCompany writer, `AcceptanceBundle`, existing model/migration and tests.

- [x] Write RED construction tests for missing/unknown role, missing disposition, favorable default, N/A without rule/rationale/evidence/signer, stale hash, duplicate disposition and absence of any one of 22 roles.
- [x] Write RED tests proving `not_applicable` cannot be chosen from mode or implementation convenience and cannot be signed by the author.
- [x] Implement immutable disposition validation and canonical hashing. Legal statuses are exactly `pass`, `block`, `not_applicable`.
- [x] Store dispositions in existing role-run records and append `role.disposition.recorded` to `atlas_ledger_events`; retries with the same hash are idempotent and conflicting hashes block.
- [x] Make missing or invalid dispositions materialize as `block` in acceptance, not as a persisted fake disposition.
- [x] Run focused disposition tests, role-run persistence/replay, AcceptanceBundle and neighboring outcome suites.

**GREEN acceptance:** a bundle cannot be certified with 21 roles, a forged N/A, stale provenance, duplicate role, absent signer or favorable default; replay reconstructs the same 22 dispositions.

**Migration/data ownership:** no disposition ledger table. Existing role-run rows are the queryable projection; `atlas_ledger_events` is the event truth.

**Failure and rollback:** reader compatibility understands legacy records as `legacy_unproven`; disabling the writer leaves incomplete deliveries `held`.

**Local commit:** `feat: add fail-closed quality role dispositions`.

---

### Packet 3: Evidence applicability matrix and independent certification

**Finding/hypothesis:** A single green score can mask missing evidence or self-review. The AcceptanceBundle must bind applicable evidence, independent witnesses and per-dimension results conjunctively.

**Owner:** existing `AcceptanceBundle`, `AcceptanceGate`, Workcell certification and Verification Court evidence services; plan 05 later composes the full acceptance gate.

**Allowed files:** listed Kernel acceptance files, existing Workcell certification, Verification Court evidence contract and their tests.

- [x] Write RED tests for missing unit/integration/contract/E2E/security/privacy/performance/accessibility/chaos/recovery/replay/static/compatibility/migration/rollback/outcome evidence when the risk/applicability policy requires it.
- [x] Write RED tests for stale command results, zero assertions, fixed smoke, lint-as-suite, evidence whose file/spec/order hash differs, and author=verifier/final-certifier.
- [x] Add an applicability matrix keyed by role, risk and delivery facts; every skipped evidence type requires the same N/A proof contract.
- [x] Bind raw criteria, frozen spec/world snapshot, changed-file hashes, provider/workspace/release receipts, commands/exit codes/timeouts/assertions, repair/replay/regression and role dispositions into one deterministic acceptance hash.
- [x] Require builder, verifier and final certifier contexts to be independent; for R4/R5 use different model families when available, otherwise hold with `independent_verifier_unavailable`.
- [x] Ensure averages and aggregate scores cannot override any `block`.
- [ ] Run AcceptanceGate/Workcell/false-green/mutation suites and architecture guards.

**GREEN acceptance:** certification is possible only when every applicable dimension and role passes; one red remains red; author self-certification and unavailable independent verification hold the delivery.

**Migration/data ownership:** evidence remains in Atlas Evidence Ledger and canonical events; role-run/outcome tables contain hashes/refs, not copied evidence bodies.

**Failure and rollback:** witness/provider outage yields durable hold/retry. It never invokes a human specialist and never lowers the bar.

**Local commit:** `feat: bind independent evidence to quality certification`.

---

### Packet 4: Event contract, replay and legacy truth

**Finding/hypothesis:** Cross-surface evidence cannot be audited unless every constitutional transition is canonical and historical gaps remain visibly unproven.

**Owner:** Atlas Evidence Ledger and existing projections.

**Allowed files:** canonical ledger event schemas/writers, existing outcome/temporal/role projections, additive migrations if fields are absent, focused replay tests.

The canonical events are exactly:

```text
experiment.preregistered, unit.frozen, execution.started,
operator.interval.closed, role.disposition.recorded,
acceptance.adjudicated, release.authorized, release.landed,
release.reverted, outcome.observed, learning.adjudicated,
claim.evaluated, claim.issued, claim.revoked
```

Every event has schema, run/delivery IDs, correlated hashes, timestamp and provenance.

- [x] Write RED schema tests for missing IDs/hash/provenance, out-of-order release/canary/outcome, duplicate idempotency key with divergent content, and a projection that assumes pass when an event is absent.
- [x] Implement event validation and deterministic replay through the existing ledger.
- [x] Make legacy rows with no provenance/evidence `legacy_unproven`; make absent current observations `unknown`.
- [x] Rebuild role, acceptance, release, outcome and claim-eligibility projections from events and compare hashes to live projections.
- [x] Run ledger replay/hash-chain, outcome default, temporal and projection reconciliation suites.

**GREEN acceptance:** replay is deterministic; missing/out-of-order events block; legacy data never becomes a modern pass or claim; projections can be rebuilt without a duplicate ledger.

**Migration/data ownership:** additive fields/indexes may be added only to existing role-run, outcome or temporal projection tables identified in this packet; canonical events remain in `atlas_ledger_events` and no new table is created.

**Failure and rollback:** ledger write/replay inconsistency stops acceptance/release and quarantines the run. Existing append-only events are never deleted during rollback.

**Local commit:** `feat: make quality evidence replayable and legacy honest`.

---

### Packet 5: Rivals-only claim authority and architecture hard guards

**Finding/hypothesis:** Outcome memories, temporal certificates, scores or mode runtimes can over-claim if they emit comparative labels directly. Static and runtime guards can make Rivals the sole issuer/revoker.

**Owner:** Rivals claim boundary; all other systems are evidence producers/claim readers.

**Allowed files:** Architecture scanner/tests, claim-eligibility fields on existing outcomes, and the minimal Rivals receiving boundary after reconciling active Rivals work. Do not redesign the Rivals trial here; plan 07 owns that.

- [x] Write RED static tests locating `world_*`, `multiplier_proven`, `world_leading`, `world_10x_quality_proven`, `claim.issued` or `claim.revoked` writes outside the Rivals allowlist.
- [x] Write RED runtime tests proving temporal certification, outcome memory, mode runtime, learning and simulation cannot set `claim_eligible=true` or issue a claim.
- [x] Add a sole-authority guard: non-Rivals services may emit evidence and request evaluation only; Rivals validates and writes claim events/bundles.
- [x] Require scope, baseline, experiment/preregistration, exposure, statistics, expiration and evidence refs on every claim.
- [x] Route contradictory or late outcomes to Rivals evaluation/revocation rather than mutating claim state elsewhere.
- [x] Run architecture scan, outcome/temporal/learning tests and focused Rivals claim-boundary tests.

**GREEN acceptance:** repository scan and runtime tests find no external claim issuer; non-Rivals requests cannot mint or revoke a claim; all new outcomes default ineligible.

**Migration/data ownership:** claim bundles stay Rivals artifacts referenced by canonical events; no generic claim ledger is added.

**Failure and rollback:** if Rivals is unavailable, evidence accumulates and claim evaluation waits. Existing claims are not renewed automatically.

**Local commit:** `feat: reserve comparative claims for Rivals`.

---

### Packet 6: Constitution parity, adversarial mutation and rollout gate

**Finding/hypothesis:** The Constitution is ready only if equivalent inputs receive equivalent dispositions across modes and deliberate false-green mutations are rejected.

**Owner:** Kernel acceptance and coverage; no new runtime.

**Allowed files:** focused cross-mode fixtures, mutation tests, readiness projection and canonical docs.

- [x] Write RED parity and mutation tests that fail when any mode omits a role, lowers depth, accepts forged evidence/N/A or gains claim authority.
- [x] Build an equivalent Dev/Forge/Autônomos fixture at each risk level and assert the same roster, applicability, evidence floor and verdict; only operator/duration/topology metadata may differ.
- [x] Mutate each role to absent/pass-without-evidence/forged-N/A/self-certified and prove acceptance blocks.
- [x] Mutate evidence hashes, world/spec snapshot, ledger order and outcome status and prove acceptance or claim eligibility blocks.
- [ ] Produce a Constitution readiness manifest with live test/evidence refs and zero unresolved mutation survivors.
- [ ] Run focused and neighboring suites, full architecture validation, docs health and `git diff --check`.

**GREEN acceptance:** parity is proven, every adversarial mutation is killed, no average masks red, and the highest honest state is `implemented_not_cutover_ready` or the already-proven factory state. `art_grade_delivery` may be issued only per eligible delivery; comparative states remain Rivals-only.

**Migration/data ownership:** no migration. This packet validates the existing role, evidence, claim and readiness projections.

**Failure and rollback:** any mutation survivor, missing role, mode drift, external claim writer or legacy favorable default blocks rollout of the constitutional gate. Disable enforcement only to observe; never convert a block into pass.

**Local commit:** `test: prove quality constitution parity and honesty`.

## Verification and rollout

Per packet, run the named focused tests, their neighboring subsystem suite, focused Pint/PHPStan, architecture validation and:

```bash
git diff --check -- <packet-files>
/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json
```

Roll out as observe → compare existing verdicts → enforce on read-only fixtures → enforce on sandbox writes → mode-by-mode enforcement only after parity. Existing favorable records are never silently upgraded.

## Honest states and blockers

- Constitution component readiness creates no new official delivery state; the system remains at the highest already-proven master state, normally `implemented_not_cutover_ready`, until all conjunctive gates advance it.
- Missing role/evidence/signer/provenance, self-certification, forged N/A, mutation survivor, ledger inconsistency, role drift, or a claim writer outside Rivals is a blocker.
- Independent verifier unavailability causes `held`, not human escalation as a runtime dependency and not a lowered bar.
- This plan authorizes no push, deploy, production cutover, temporal claim or comparative claim.
