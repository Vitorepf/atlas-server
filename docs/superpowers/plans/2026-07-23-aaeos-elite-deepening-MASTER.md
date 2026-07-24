# AAEOS — Mother Block · CANONICAL IMPLEMENTATION PLAN (vFINAL)

> **Status:** **CANONICAL** · PLAN_ONLY · P0–P4 NOT_STARTED · code requires literal **`EXECUTE P0`**  
> **Authority:** sole master for this program. LEDGER + SCOREBOARD are the only normative satellites.  
> **Lineage:** Claude constitution + critical path + C6 teeth · Codex residual/journey/crash completeness · Grok external audit · MAIN v14–v16 security strengthenings folded into themes (no parallel residual ledger).  
> **Branch:** local `main` only · scoped commits · never `git add -A`  
> **Evidence:** `docs/evidence/2026-07-23-aaeos-elite-deepening/`  
> **Archive (history only):** `docs/superpowers/plans/archive/aaeos-elite-deepening-2026-07-23/`  
> **Freeze:** no further absolute-plan cycles unless a **new disk residual** appears during implementation. Next verb after this doc: **`EXECUTE P0`**.

Conflict order (highest wins): (1) live code + durable evidence; (2) phase contracts (E1); (3) completion predicate (E2); (4) owner map (B1) forbids parallel owner; (5) uncertainty is `NOT_PROVEN`, never `PASS`.

**Trust boundary.** Proof strength is relative to: local Postgres ledger, local git, and an **off-host operator signing key**. Authenticity of REAL_OPERATION requires that key **not** be readable by the journey process (open P4 ops requirement; today `atlas_code_signing.keypair_base64` is same-process config).

---

## CRITICAL PATH (commands the implementer — read first)

```text
EXECUTE P0   → honesty + port (T2, T9-deletes)     — stop lying
P1a          → R33/R34/R35 + dispatch fuse (T1,T11) — Autônomos can call brain
P1b-refuse   → effect authority characterization     — no ACT until P2a/P2b
P2a          → Ledger integrity + journey core (T4)
P2b          → Decision v3 / keyring / AWIS (T5)     — unlocks P1b ACT
P1b-ACT      → pre-auth/act/post/settle on code path (T3)
P2c–e        → crash/repair/H1–H7/independence (T6,T7)
P3           → economy/M views + deletes + review surface (T8–T10)
P4           → REAL_OPERATION ×3 modes out-of-PHPUnit  — Autônomos may be PARTIAL
```

**Binding DAG (no concurrent same-owner edits):**  
`P0 → P1a structural → P2a Ledger → P2b Decision/AWIS → P1b ACT → P2 durability → P3 → P4`

---

## PART A — CONSTITUTION (mother block = LAW)

### A0. What AAEOS is

AAEOS is the **mother block** of Atlas agentic software engineering: product law, effect-authority protocol, proof taxonomy, residual government. **Thin in muscle** (reimplements no executor/court/governor/ledger/actuator). **Central in law** (non-bypassable at shared effect seams).

**Where law binds direct modes** (not via `atlas:aaeos:run`): provider-spawn (`ProviderGovernanceConsult` + `ProviderGovernanceCoverageLedger`), mutative surface (`AtlasWorkspaceIntelligenceExecutionGateService`), land/settle (`AtlasTaskMergeActuator` / `CanarySettlementRequest`), courts/governor, `AtlasEvidenceLedger`. Falsifiable: AAEOS-ablation — direct Dev/Forge/Autônomos still records governed spawn + observer-minted effect + court verdict, or produces no effect.

**Router:** `atlas:aaeos:run|cycle` carries **no** productive muscle flags (`--live`, `--execute-provider`, `--max-seeds`, `--run-worker-once`, `--scope` belong to native commands). Router routes/observes/projects only.

### A1. Product law

**Three elite executors, one L0–L5 floor.** Difference = horizon / origin / sovereignty — never quality.

| Executor | Horizon | Origin | Sovereignty | Human in eng |
|---|---|---|---|---|
| Dev | session | live intent | current intent/authority | outside technical loop |
| Forge | durable Obra | plan + packets | sealed commissioning | absent after plan (except true reserve) |
| Autônomos | continuous | Brain→Seed→Task | standing mandate | absent |

Remove authoritative `human_in_engineering_loop` (sites: `AtlasAaeosCertifyCommand:34`, `AaeosCycleRuntime:107`, `DevModeAdapter`). **Never** hardcode `false` as identity; use sovereignty arrangement.

**Three loops:** ENGINEERING (agentic; author≠judge≠governor) · SOVEREIGNTY (async, pre-issued) · AUDIT (optional observe/pause/revoke — never default eng gate).

**Laws (non-negotiable):**
1. Provider **output** untrusted: never verifies, authorizes, lands, promotes learning, or issues comparative claims.
2. Author≠judge≠governor by principal/capability/mechanical evidence; **same model family → mechanical court required** to mint release.
3. `n=1` default; fan-out needs width + measured lift; `agent_count` never KPI.
4. Less human presence adds assurance for **new failure surfaces**, not a higher shared quality bar.
5. Intent clarity may remove ceremony — never evidence, mutation safety, courts, failure_reason, rollback.
6. No silent failure: every adverse terminal has precise `failure_reason` on native outcome.
7. Quarantine absent forever; ACDE dead.
8. Score diagnostic; DONE = conjunction of hard evidence, never `composite ≥ x`.
9. Provider **input** sovereign (Law 9): `sovereignty_local_first` + `human_approval_for_high_risk` on `AtlasConstitutionalKernelService` — **fail-closed independent of** `ProviderGovernanceConsult::enforce` (default OFF/observe-only at `:122`).

### A2. OneShot Experience Law

OneShot = **operator experience**, never algorithm.

- Operator commissions **once**; not a workflow worker. Clarification only via `ProductIntentCourt` lineage — not plan/diff/test/release approval.
- Internally: unlimited plan/implement/reject/repair/retest/canary/rollback. Failed court → agentic stage, never routine human tech labor.
- Proof **server-derived**; `capture_coverage == 1.0` over operator ingress/egress census; missing = `unknown`, never zero. Autônomos OneShot = **absence** of operator task-causal actions under full capture.
- **Forbidden:** encoding OneShot as one pass, one dispatch, speed target, or gate that skips review.

### A3. N × M (why the mother block exists)

`useful ≈ N × M`. **N** = provider. **M** = Atlas: governance, refusal, proof, memory, recovery, mandate.

**Measured now (falsifiable):**
- `enforced_governed_coverage = COVERED / total_provider_spawns` (manager-resolved only; ledger `governed` folds `consulted` — report separately, **never** as M).
- `provider_proposal_refuse_repair_rate` over commissioned journeys (Ledger + `EngineeringOutcome`).
- Completeness: spawn-site census test green or claim = `unknown`.

**SOTA / 50× / #1** requires `capability_proof=comparative` from `RivalsClaimAuthority` under budget symmetry — never internal composite. Horizon: multi-domain company M (product/marketing/strategy) after eng M is real.

---

## PART B — ARCHITECTURE (reuse-only)

### B1. Owner map

| Concern | Reuse | Forbidden |
|---|---|---|
| Dev | `DevPlanRunFacade`, `AtlasDevExecutionService`, `DevIntent`, `ConfirmedDevRun` | Dev executor inside AAEOS |
| Forge | `ForgeObraRuntime`, work-packet cycle, `ForgeCommissioning` | flatten Obra to one dispatch |
| Autônomos seed | Brain + `AtlasSelfConstructionNativeReplenisherEnqueueRunner` → `prepareAndEnqueue` | CLI→CLI; invented `--max` |
| Autônomos cycle | extract `AtlasSelfConstructionRuntimeDaemon` from `::productiveCycle` | private duplicate composition |
| Task claim/worker | `AtlasTaskServingService` + worker | `task next` as “worker done” |
| Contract | `ExecutionOrder` v2 + `EngineeringOutcome` | `EngineeringMission*` |
| Courts | `EngineeringQualityCourt`, false-green detector | AAEOS court |
| Release | KernelEvidenceAuthority, MergeGovernor family | SovereigntyPort package |
| Mutative surface | `AtlasWorkspaceIntelligenceExecutionGateService` (autonomos in set; unknown fail-closed; mode from signed authority — R102) | second gate |
| Code effect | `AtlasTaskScopedCommitter`, `AtlasTaskMergeActuator` (**extract** `changedFiles` to callable seam) | AAEOS actuator |
| Canary | `CanarySettlementRequest` | second SM |
| Compensation | `prepareRevert` → `AuthorizedRevertAction` | AAEOS reverts |
| Leases | `leaseIsLive` + `atlas_task_scope_reservations` | second reservation authority |
| Evidence | `AtlasEvidenceLedger`; `EvidenceLedgerHashChainIntegrityVerifier`; `AtlasLedgerReplayService`; daily head anchor | second ledger/counter |
| Decision/sign | `DecisionReceipt` + `HumanDecisionReceiptSigner` (Ed25519) | string authority; second signer |
| Admission | Aaeos: add `REPAIR_REQUIRED` (not MergeGovernor enum reuse) | 6-state AAEOS enum growth |
| Independence | SpecSourceIndependence, SovereignSpecFloor, modelFamily | self-declared flag |
| Metrics | MaestroCostAggregator, EngineeringOutcome | AAEOS cost truth |
| Provider gov / M | CoverageLedger, Consult, SkipCounter | second bypass meter |
| Memory-M | LearningPromotion + HeldEvidenceMiner + ContextPack | second promoter under AAEOS |
| Comparative | RivalsClaimAuthority | caller capability_proof |
| Mode route | `AaeosModeToDualCoreRoute` fail-closed | duplicate derivation |
| Law 9 | ConstitutionalKernel local_first + high_risk approval | second egress policy |
| Terminal UX | `atlas:cli:cockpit` + `atlas:review:deep` (optional, never required verdict) | new shell |

**One new production class only:** `AaeosRunApplication`.  
Flow: `run|cycle` → RunApplication → CycleRuntime → LiveDispatchGateway → native dispatcher → **native pre-effect authority** → act → observe → Ledger → AAEOS projectors. **No** direct AAEOS→`EliteExecutorKernel::execute`.

### B2. Effect-authority protocol

1. **SUSPECT** — text/regex/hints only raise risk (`AaeosIntentCompiler` regex today = suspicion).  
2. **PRE-AUTHORIZE** — server ceiling from owned classifiers + registry/tool contract + active authority + world, **before** effect.  
3. **ACT** — at most authorized effect.  
4. **POST-ATTEST** — `observed_effect_class` from real tool/write-set/SHA/receipt.  
5. **SETTLE** — bind auth+observation+outcome; mismatch → refuse/revoke/compensate.

**Trust:** ExecutionOrder untrusted until decision reloaded under lock; declarations never lower server class; release mint requires independence attestation + mechanical court if same model family.

**Nonces:** LAND = `AuthorizedMergeAction::nonce`; SETTLE = `CanarySettlementRequest::idempotencyHash`; provider spawn single-use to order. Exactly-one winner: hard `INSERT` on ledger `event_id` PK → loser `release_uncertain`.

**Crash:** cutpoints auth→provider→sandbox→commit→observe→settle→lease/report→Forge transition; restart → one effect + one terminal or `release_uncertain`; real Postgres races; no second outbox.

**Security deepenings (from MAIN v16, owners existing):** signed tenant/principal/mode; child authority validates ancestor chain + atomic parent nonce/budget; workspace binds root/device/inode/tenant/base SHA; no-follow + exact delta under lock; structured argv only; recheck executable realpath/version/hash pre-exec; secret refs resolved only at process boundary; provider cannot self-certify success; Ledger full-envelope hash + independent cutoff verification; one signed root resource ceiling across retries/handoffs; instruction provenance server-owned; AOBG blackboard **advisory only**, never execution authority.

### B3. Proof + anti-fabrication

Levels: `PLANNED < SOURCE_WIRED < AUTOMATED_CHARACTERIZED < DRY_RECEIPT < LIVE_EFFECT < REAL_OPERATION` (terminal per journey). `SUSTAINED` = horizon only.

`effect_level` / `proof_level` **derived**, not settable. `capability_proof ∈ {none, internal_only, comparative}` derived; UI “god/SOTA” with ≠ comparative → hard fail. Certify dogfoods anti-fabrication matrix.

**REAL_OPERATION (out of PHPUnit):**
1. **Authenticity** — operator Ed25519 over journey-root manifest; **P4 ops:** key off-host (today config same-process = open gap).  
2. **Provider binding** — ≥1 causal `COVERED` spawn from CoverageLedger (zero-provider bash commit fails).  
3. **Tamper-evidence** — git world (`changedFiles` / SHA counted **once**), external receipt, env attestation (daily head + DB + HEAD + no-PHPUnit). Subtract-one flips verdict.  
Ledger chain + mutation suite = suite/tamper gates, not authenticity legs.

---

## PART C — DISK TRUTH (revalidate every slice)

| Fact | State | Gate |
|---|---|---|
| Aaeos tree | 27 PHP Control+Spine | hold purity |
| Quarantine | absent | never recreate |
| `runtime_write_performed` | hardcoded true | P0 derive |
| Spine `assertShared(mode,[])` | self-green | P2 fail-closed refs |
| Autônomos `brainNextArgs` | invalid `--scope` | **P1a R33** |
| Brain exit 0 | may be disabled/dry | P1a R34 |
| Seed `--max` | fictional | P1a in-process enqueue |
| Admission | invalid_mode→HALT_SOVEREIGN | P0 REPAIR_REQUIRED |
| Provider enforce | default OFF | Law 9 independent fail-closed |
| Scorecard | hardcoded 9.x + god_sota | P0 gut |
| Signing key | config same process | P4 off-host custody |

```bash
git branch --show-current   # must be main
rg -n 'human_in_engineering_loop|runtime_write_performed|assertShared' app/Services/Ai/Aaeos app/Console/Commands/AtlasAaeosCertifyCommand.php
rg -n 'brainNextArgs|--scope' app/Services/Ai/Aaeos app/Console/Commands/AtlasBrainNextCommand.php
```

---

## PART D — THEMES (executable failure inventory)

No-fuse: distinct owner+phase+done stay independent. None waivable into DONE.

| ID | Phase | Done when |
|---|---|---|
| **T1** Live transport | P1a | positional brain scope; payload success; seed in-process enqueue; fresh readback counts real drafts |
| **T2** Truth | P0 | rwp derived; run=cycle app; typed effect_level; no fake scores; dry zero-write; human field gone; invalid_mode→repair_required |
| **T3** Effect authority | P1b | five-step on native owners; reload decision before provider; present-but-false + no-keyword goldens; independence-at-mint; **ACT only after P2a+P2b** |
| **T4** Evidence | P2a | hash-chain + replay; spine refs=settlement receipts; non-circular receipt core; journey DAG fold stable under race |
| **T5** Mandate/identity | P2b | typed ingress; Decision v3 + keyring + revoke; check after provider + under effect lock; AWIS mode fail-closed |
| **T6** Journey/repair | P2/P4 | single root; repair intra-journey; crash one resume; H1–H7 A/B witness; land-before-report Autônomos |
| **T7** Independence | P2/P3 | principals; SelfComposedUnwitnessed fails; same-family → mechanical court |
| **T8** Economy/M | P3 | economy view ITT-all; enforced_coverage only as M; AAEOS hop-neutrality golden |
| **T9** Deletes | P0/P3 | dead projectors; adapters after parity; lazy aliases; AEOS partition guard; evidence-led alias burn |
| **T10** Surface | P1/P3 | verification + liveness views; review:deep optional not gate |
| **T11** Public parity | P1a/P4 | daemon seam; bin/atlas + routed parity; no delete until consumer census green |

**Horizon (not P4 DONE):** SUSTAINED; crash-at-scale beyond MVP; topology ablation/Foundry; comparative 50×; multi-domain company M; multi-operator isolation; memory write-loop fully fed; off-host key as closed authenticity.

---

## PART E — EXECUTION

### E0. Pre-flight (every phase)

```bash
git branch --show-current   # main
git status --short
# claim files on blackboard if multi-engine
# record baseline test failures for dirty-main
```

### E1. Phase contracts

**Scope fence:** touched paths = closed manifest in PHASE receipt. Unlisted path needed → **stop, amend this MASTER**, then continue. New production classes: only `AaeosRunApplication` (+ extract seams named above). Tests may be new.

#### P0 — truth/port (T2 + T9-deletes)

**Authorize production (exact):**
```
app/Console/Commands/AtlasAaeos{Run,Cycle,Scorecard,Certify}Command.php
app/Services/Ai/Aaeos/Control/AaeosRunApplication.php          # NEW
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Aaeos/Control/AaeosIntentCompiler.php          # suspicion-only; no full CRES
app/Services/Ai/Aaeos/Control/AaeosAdmissionPolicy.php
app/Services/Ai/Aaeos/Control/AaeosAdmissionVerdict.php        # + REPAIR_REQUIRED
app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php    # const consumers
app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php   # DELETE
app/Services/Ai/Aaeos/Control/AaeosTriHygieneScorecardProjector.php # DELETE if present
app/Console/Commands/AtlasTriHygieneScorecardCommand.php           # DELETE if present
```
**RED:** fake constants, dry writes, invalid caps, human field, invalid_mode halt_sovereign, god_sota composite.  
**GREEN:** honesty matrix; measured null with sample 0; no provider burn.  
**Commit:** `feat(core): AAEOS-MT P0 honesty port and admission taxonomy`  
**Does NOT fix R33.**

#### P1a — native dispatch (T1 + T11 structural)

**Authorize:** CycleRuntime, LiveDispatchGateway, three mode dispatchers, Adapter deletes after parity, Brain/Seed command thin presenters, ReplenisherEnqueueRunner path, RuntimeDaemon extract, Router strip productive flags, tests.  
**RED:** brainNextArgs without positional scope fails; exit-only success wrong.  
**GREEN:** R33/R34/R35; fuse adapters; AAEOS ablation on structural routing; **no** auth semantic change.  
**Commit:** `refactor(core): AAEOS-MT P1a native dispatch and brain args`

#### P1b — effect authority (T3; refuse until P2a+P2b)

Before P2a+P2b green: **characterization/refusal only** — zero provider/tool/sandbox/mutation.  
After unlock: ACT/settle on native code path; both land chokepoints observer-minted; present-but-false goldens.  
**Commits separate** from P1a.

#### P2a — Ledger foundation (T4)  
#### P2b — Decision v3 + AWIS (T5) → unlocks P1b ACT  
#### P2c–e — journey/crash/H1–H7/independence (T6–T7)  
#### P3 — T8–T10  
#### P4 — REAL_OPERATION ×3 out-of-PHPUnit; OneShot server-derived; off-host key custody proof; Autônomos may `blocked_ops` PARTIAL

**P4 producers (reuse):** `AtlasDevSeniorLoopRunCommand`, `AtlasForgeLiveExecuteCommand`, `AtlasSelfConstructionRuntimeDaemon`. Certifier = distinct identity, SELECT-only DB, no provider/write.

### E2. DONE predicates

**90-day commit:** **P0–P3 DONE + P4 PARTIAL** (Dev+Forge real; Autônomos blocked_ops if fleet off).

**Full DONE:** all T1–T11 closed; three REAL_OPERATION journeys; honesty match ledger+fresh readback; Ledger integrity; ablation+hop-neutrality goldens; `capability_proof ≥ internal_only`; Quarantine/ACDE absent; scoped main commits.

**DONE does not mean:** SUSTAINED 24/7, comparative 50×, world #1, complete autonomous company.

### E3. Hard non-goals

Mission Runtime/Envelope; WorkGraph-as-OS; SovereigntyPort; AaeosModeExecutor; AaeosActionEffectClassifier as authority; second Ledger; Foundry inside P4; new IDE/shell; Quarantine/ACDE; caller capability_proof; human field hardcoded false; Forge flattened; task-next as worker success; absolute-plan loops without disk residual.

### E4. Phase receipt minimum

```yaml
phase: P0
status: passed|failed|blocked|partial
branch: main
base_sha: ...
result_sha: ...
allowed_paths: [...]
touched_paths: [...]
tests: [{cmd, exit}]
residuals_closed: [...]
proof_level: AUTOMATED_CHARACTERIZED
failure_reason: null
next_phase_authorized: false
```

---

## Honest bottom line

This file is the **definitive plan of record** for AAEOS Elite Deepening implementation: strongest constitution, owner map, effect protocol, and executable inventory from the Claude×Codex competition, process-canonicalized.

It is **not** “Atlas at M ceiling.” R33, honesty, admission, and authority chain are still **open in code**. Zero PHASE receipts. Measured M = null.

**Masterpiece of the plan: this document.**  
**Masterpiece of the system: starts at `EXECUTE P0`.**
