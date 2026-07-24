# AAEOS — CANONICAL IMPLEMENTATION PLAN (vFINAL-EXEC)

> **Status:** CANONICAL · PLAN_ONLY · **ready for Codex `EXECUTE P0` after operator phrase**  
> **Authority:** sole master. LEDGER + SCOREBOARD only satellites.  
> **What this is:** vFINAL constitution + **v16 executable body** (path manifests, tests, receipts, residual index, binding DAG) + cycle-9 security folds + C6 teeth. Fixes Grok/Codex critique: no executability regression.  
> **Branch:** `main` only · scoped `git add -- paths` · never `git add -A`  
> **Evidence:** `docs/evidence/2026-07-23-aaeos-elite-deepening/`  
> **Archive:** `docs/superpowers/plans/archive/aaeos-elite-deepening-2026-07-23/` (v16 full text + Claude C6 history)  
> **Freeze absolute:** no plan cycle-10 unless NEW disk residual during implement.  

```text
IF "EXECUTE P0"  → implement ONLY §P0, write PHASE-P0.json + receipt md, STOP
IF "EXECUTE P1a" → ONLY §P1a, STOP
IF "EXECUTE P1b" → ONLY §P1b (refuse until P2a+P2b green)
ELSE without EXECUTE → no production code
```

Conflict order: (1) live code+evidence (2) phase manifests below (3) DONE predicate (4) owner map (5) NOT_PROVEN ≠ PASS.

---

## 0. Critical path (binding DAG)

```text
P0 truth/port
 → P1a native dispatch (R33–R35; adapters; daemon extract)  [refusal: no provider/effect]
 → P2a.1 Ledger v2 + PG roles
 → P2a.2 EngineeringOutcome v3 expand/dual-read/shadow
 → P2b Decision v3 EXPAND→SHADOW→CANARY→CUTOVER + AWIS R102
 → P1b.1 pre-effect authority replay (CodeGraph etc.)
 → P2c native lineage/crash durability
 → P1b.2 native ACT/settlement (+ tool redaction)
 → P1b.3 AAEOS projection only
 → P2d Spine settlement refs
 → P2e async H1–H7 (after P2c)
 → P2f operator census / strip technical CLI choreography
 → P2b CONTRACT after old-worker drain
 → P3 deletion/alignment/economy/verification surface
 → P4 REAL_OPERATION ×3 (out of PHPUnit) + optional OneShot proof
```

Slices sharing `AtlasEvidenceLedger.php` never run concurrently. Each slice = separate scoped commit + exact receipt file listed in §0.1.

### 0.1 Closed evidence artifacts (only these)

`PHASE-P0.json`, `PHASE-P1A.json`, `PHASE-P1B1.json`, `PHASE-P1B2.json`, `PHASE-P1B3.json`, `PHASE-P2A1.json`, `PHASE-P2A2.json`, `PHASE-P2B-EXPAND.json`, `PHASE-P2B-SHADOW.json`, `PHASE-P2B-CANARY.json`, `PHASE-P2B-CUTOVER.json`, `PHASE-P2B-CONTRACT.json`, `PHASE-P2C.json`, `PHASE-P2D.json`, `PHASE-P2E.json`, `PHASE-P2F.json`, `PHASE-P3A.json`, `PHASE-P3B.json`, plus P4 mode receipts named in §P4. Also LEDGER.md + SCOREBOARD.md. **No other evidence files** without MASTER amendment.

### 0.2 Phase ritual (every slice)

```bash
git branch --show-current   # main
git status --short
BASE=$(git rev-parse HEAD)
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Aaeos/Control tests/Feature/Ai/Aaeos --no-coverage || true
# implement ONLY listed paths
# RED then GREEN tests for this slice
git add -- <exact paths>
git commit -m "feat(core): AAEOS-MT <slice> ..."
# write PHASE-*.json under evidence/
# update LEDGER + SCOREBOARD
# STOP
```

### 0.3 Forbidden

`git add -A`; Mission*/WorkGraph/SovereigntyPort/AaeosModeExecutor/AaeosActionEffectClassifier-as-authority; second ledger; Quarantine revive; ACDE; hardcode `human_in_engineering_loop=false` as identity; GOD_SOTA from composite; fix R33 in P0; call Kernel from AAEOS Control.

---

## 1. Constitution (compact — do not re-architect)

### 1.1 Mother block
AAEOS = law at shared seams (provider gov, mutative gate, land/settle, courts, ledger). Thin muscle. Router `atlas:aaeos:run|cycle` has **no** productive flags (`--live/--execute-provider/--max-seeds/--run-worker-once/--scope` are native-only).

### 1.2 Executors
Same L0–L5 bar. Diff = horizon/origin/sovereignty. Eng judgment agentic. Remove authoritative `human_in_engineering_loop` (sites Certify:34, CycleRuntime:107, DevModeAdapter, dispatchers).

### 1.3 OneShot
Operator experience only — not one code pass. Server-derived; capture_coverage; Autônomos zero task-causal operator actions.

### 1.4 Effect protocol
SUSPECT → PRE-AUTHORIZE → ACT → POST-ATTEST → SETTLE on **native** owners. Declarations raise risk only. LAND nonce=`AuthorizedMergeAction::nonce`; SETTLE=`CanarySettlementRequest::idempotencyHash`. Exactly-one: hard INSERT ledger event_id.

### 1.5 R4 (explicit)
`AutonomosLiveDispatcher` calling `atlas:brain:next` = **SOURCE_WIRED only**. Never claim live effect until P1a args + payload class + P4 journey.

### 1.6 Score fiction (exact)
Certify injects: `operate_path_wiring=9.2`, `spine_enforced=9.2`, `antifragile_loop=9.0`. Standalone projector defaults operate/spine/antifragile **9.0**, control_plane **9.2**, thesis/elite **9.5**. P0 removes all as measured truth.

### 1.7 M
`enforced_governed_coverage = COVERED/total` (not ledger `governed` that folds consulted). Refuse/repair rate. Census green or `unknown`. Comparative SOTA = Rivals only.

### 1.8 Signing / Autônomos zero-touch
Standing mandate is **pre-signed** (Ed25519 via `HumanDecisionReceiptSigner`). Journey roots **derive** mandate id/hash/revision — operator does **not** sign every Autônomos root. P4 ops: key custodied **off journey process** (today config same-process = open P4 requirement, not closed).

### 1.9 CycleRuntime is the shared use-case
**No `AaeosRunApplication`.** Run and Cycle commands both call `AaeosCycleRuntime` (runCycle / runAutonomosCycle) after shared flag normalization helpers **inside the commands or a private trait** — not a new public middle-man class unless RED proves unavoidable. Prefer private static normalizer or shared protected method extraction in a single command base only if needed; default = duplicate-minimal normalize in both commands calling runtime.

---

## 2. Disk truth (revalidate every slice)

| Fact | State |
|---|---|
| Aaeos 27 PHP Control+Spine | hold pure |
| Quarantine absent | never recreate |
| rwp hardcoded true CycleRuntime:93 | P0 |
| assertShared(mode,[]) self-green | P2 |
| brainNextArgs `--scope` / missing positional | **P1a R33** |
| exit0 disabled/dry | P1a R34 |
| seed invents `--max` | P1a R35 |
| invalid_mode→HALT_SOVEREIGN | P0 |
| ProviderGovernanceConsult enforce default OFF | Law9 independent fail-closed |
| score/certify fantasy numbers | P0 |

```bash
rg -n 'human_in_engineering_loop|runtime_write_performed' app/Services/Ai/Aaeos app/Console/Commands/AtlasAaeosCertifyCommand.php
rg -n 'brainNextArgs|--scope' app/Services/Ai/Aaeos/Control/Dispatch/AutonomosLiveDispatcher.php
```

---

## 3. Residual index (binding — full done text for R64+ in archive v16 §7 if needed)

Phase column is the **earliest** close phase. Full done-conditions for R52–R103 live in archived `MASTER-v16-pre-final.md` §7 **and** phase exits below (phase exits win if conflict).

| ID | Gap / note | Phase |
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
| R10 | dual Aaeos/AgenticEngineeringOs mental maps; AEOS 43k-LOC lattice unwired from the live Kernel land  | P1 code partition + guard test (R73); P3 canonical docs |
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
| R21 | run/cycle diverge | P0 shared `AaeosCycleRuntime` only — **no** `AaeosRunApplication` class |
| R22 | RunCommand lacks dedicated complete test | P0 |
| R23 | source/mock/dry/real claims mixed | all phases use section 6 |
| R24 | numeric target/waiver can launder DONE | hard-gate predicate only |
| R25 | blocked ops could replace live proof | P4 remains incomplete |
| R26 | rg=0 is insufficient alias proof | P3 classmap/runtime proof |
| R27 | ObserveRegistry was preselected without evidence | DELETE proposal unless measured need |
| R28 | Spine criteria lacked owner/call-site | P2 applicability matrix |
| R29 | operator OneShot, technical attempt count and 24/7 proof were fused | OneShot is UX; internal cycles are unbounded by this concept |
| R30 | dirty-main verification lacked baseline attribution | section 9 protocol |
| R31 | archive deletion appeared as future work | CLOSED/HOLD |
| R32 | CODEMAP points to legacy source connector alias | P3 |
| R33 | brain scope flag invalid | P1a hard gate |
| R34 | exit-only Brain success is false-positive | P1a payload semantics |
| R35 | seed --max is invented | P1a remove |
| R36 | live needs memory/master/scope preflight | P4 blocked_ops if absent |
| R37 | run/cycle flags/defaults/exit differ | P0 hard gate |
| R38 | human field is authoritative in code | P0 remove reads/writes; never hardcode false |
| R39 | review presence and quality were coupled | P0/P3 semantic cleanup |
| R40 | technical invalidity maps to sovereignty | P0 taxonomy; no human escalation |
| R41 | v6 invented conflicting mode axes | v7 deletes; derive from existing contracts |
| R42 | 17-phase/human-review runbook presented as operate | P3 demote/alignment |
| R43 | AAEOS uses CLI-to-CLI for Brain/Seed | P1a application extraction |
| R44 | Spine can self-green from empty declarations (AaeosEngineeringSpine::assertShared(mode,[]) defaults  | FOLDED into R66 (see R97): R66 closes R44 by construction at |
| R45 | attention queues risk becoming authority | P2: existing Ledger decision events + read-model projection only |
| R46 | principal/capability/spec-witness independence not proved | P2 owner hardening, same floor in all modes |
| R47 | durable sustained government | HORIZON after P4: preregistered ITT window; no Mission Runtime |
| R48 | topology ablation | HORIZON: Quality Foundry/Rivals sister claim, never MT DONE |
| R49 | attention/economy | P3 read model derived from all commissioned EngineeringOutcomes |
| R50 | external superiority | HORIZON: Rivals-only recomputed current claim |
| R51 | regex/hints/self-report used for irreversibility | P0 suspicion; P1b native authority |
| R52 | v6 land-only CRES is too late | pre-authorize → act → post-attest → settle plus crash-cutpoi |
| R53 | ExecutionOrder proposal may carry caller-authored authority; provider can run before authoritative r | active decision/authority event reloaded before provider/too |
| R54 | Ledger integrity/query/dedupe incomplete | recompute full event/envelope chain; single linear head/posi |
| R55 | EngineeringOutcome adverse causes/authority incomplete | all statuses authority-bound; adverse status requires precis |
| R56 | Autônomos claim/already_done can be sold as worker/origination | distinct served, claimed, executed, landed, resolved refs; c |
| R57 | Forge terminal concurrency/exactly-once is not proved | unique cycle position + lock/CAS + resume one running cycle/ |
| R58 | v6 capability_proof can become second truth | status derived from hard gates/Rivals only; caller value ign |
| R59 | standing mandate is a label without stale-resume proof | mutative unattended path binds active receipt id/hash/revisi |
| R60 | planned ModeExecutor duplicates existing dispatcher port | no new interface; adapters removed after parity |
| R61 | plan_seal/session enums are labels conflicting with owners | use commissioning/authority hashes and canonical duration en |
| R62 | P4 criteria accepted a first effect/exit or test receipt instead of a completed operator journey | non-testing real producer + durable backend + end-to-end art |
| R63 | reversibility gate is orphan and DecisionReceipt signing is not global-strength | integrate existing gate only for reserved effects; promote E |
| R64 | R40 fix under-specified: an implementer could de-conflate technical vs sovereign by growing the verd | see phase exits / AaeosAdmissionPolicy invalid_mode/unknown-mode ret |
| R65 | proof_level is a settable field checked only at DONE, so illegal (effect,proof) cells are constructi | see phase exits / proof_level is a pure derivation of observed effec |
| R66 | Spine N11 self-greens from empty/declared refs and is a second disjoint proof | see phase exits / an applicable critical N11 site is satisfied only  |
| R67 | measure-first is enforced only by a one-time human census; a future receipt field can ship with zero | see phase exits / one standing red test reads the field-set from the |
| R68 | independence proves principal/capability but is blind to model-weights; under verboo-only, author an | see phase exits / the independence receipt carries model_family_id f |
| R69 | R49 economy is hand-waved and an accepted-only denominator hides failed/cancelled/recovery burn | see phase exits / canonical ITT left join includes roots with no out |
| R70 | §P1b land-observer names the two chokepoint files but not the exact primitive, and only one chokepoi | see phase exits / post-commit the observed write-set is derived by t |
| R71 | AaeosOperateScorecardProjector is a verified-zero-consumer hardcoded self-grader gated behind an alr | see phase exits / class removed; scorecard/certify truth sources onl |
| R72 | AaeosTriHygieneScorecardProjector scores AEOS by file size/existence — a refactor-progress proxy and | see phase exits / no AAEOS quality number derives from lineCount()/i |
| R73 | AEOS 43k-LOC lattice is unwired from the live Kernel land path yet coupled to Aaeos only via the LOC | see phase exits / R10 re-scoped to a P1 code partition: a guard test |
| R74 | §5.1 permits mode→(route/sovereignty/delegation) derivation in read models with no single owner, and | see phase exits / §5.1 names one derivation owner; AaeosModeToDualCo |
| R75 | refs/states from valid runs can be spliced or raced into fake REAL_OPERATION | see phase exits / total journey state projection; one absorbing term |
| R76 | OneShot counters can be caller-zeroed or hide technical approvals/controls as clarifications | see phase exits / complete ingress/egress census and producer seals; |
| R77 | standing mandate is neither fully signed nor provably current; absent/foreign receipt can pass | see phase exits / trusted keyring, immutable lifecycle, audience/sco |
| R78 | AAEOS arrays can mint/discard native identity and real provider/tool/path boundaries are outside the | see phase exits / no authority remint/fallback; canonical path ident |
| R79 | rejected work and crash-restarted attempts lack owner-complete convergence | see phase exits / same root/budget across reject→repair→re-review; p |
| R80 | receipt↔Ledger binding is circular and replay lacks concurrent/DAG truth | see phase exits / non-circular persisted core/hash; linear append he |
| R81 | H1–H7 and cockpit can become a synchronous technical review queue or a hidden global halt | see phase exits / reserved effect alone blocks; technical incident c |
| R82 | Spec Floor/authority roles allow self-composed or self-expanded authority | see phase exits / same independence floor all modes; subject/executo |
| R83 | crashes and real concurrency can split Git/Ledger settlement, queue/lease/report, Forge cycle/provid | see phase exits / deterministic fault injection at every cutpoint; r |
| R84 | PHPUnit/fixtures/simulate-only services can manufacture “REAL_OPERATION” and certify their own recei | see phase exits / producer runs outside PHPUnit on a controlled real |
| R85 | authority, cycle receipt and Ledger payload semantics would be mutated in place across mixed-version | expand→dual-read/shadow→canary writer→mutative cutover→contr |
| R86 | AAEOS/provider/context/fan-out/retry amplification can be hidden or “optimized” by punishing useful  | ITT left join includes every root and all burn; AAEOS adds z |
| R87 | the AiProviderManager governance/bypass owner — the operator-named structural M-lever — is absent fr | see phase exits / §3.2 names the owner; every muscle provider spawn  |
| R88 | M — the multiplier that is the plan's reason to exist — has no falsifiable instrument; v7–v9 deleted | see phase exits / §6 emits governed_spawn_coverage = governed_provid |
| R89 | H4 authorizes delegated learning but names no owner; the memory-M seam is unfenced | see phase exits / H4 + §3.2 name the delegated-learning owner (rever |
| R90 | §4 SETTLE names "compensation as applicable" with no owner; a landed-then-proven-bad release has no  | see phase exits / a green-settled release later proven bad is compen |
| R91 | Dev OneShot is blocked wherever the operator plan-approval gate becomes a repeated technical workflo | see phase exits / both direct Dev and AAEOS-routed Dev reach provide |
| R92 | P2's six sub-slices need an explicit order because several edit AtlasEvidenceLedger.php | declare the ordered DAG over existing P2a–P2f |
| R93 | AaeosHygieneLegacyAliases eagerly loads 40 canonical classes while current code census finds zero le | see phase exits / after Composer/classmap/negative-resolution plus r |
| R94 | the 2026 verification moat is not first-class: atlas:review:deep is unmentioned and its verdict UX c | see phase exits / verification artifact = observer-minted effect + p |
| R95 | reserved-decision events must not hide a real task-causal operator action in Autônomos | see phase exits / DecisionDrafted/EscalationRequested alone are syst |
| R96 | R75's terminal-status enum has no stated boundary against R79 repair-continuation (is a repair a new | see phase exits / every repair/replan/re-review (R79) is an intra-jo |
| R97 | duplicate/latent-duplicate accounting and over-fusion risk in the evidence-integrity family | see phase exits / R44 folded into R66 (single stronger bar); R66 CON |
| R98 | the productive Autônomos composition is private inside `AtlasSelfConstructionRuntimeDaemonCommand`,  | see phase exits / direct daemon and AAEOS-routed dispatch return the |
| R99 | the daily CLI makes the operator select live execution, provider enablement, worker step, seed batch | see phase exits / `atlas:aaeos:run <intent>` enters the governed pro |
| R100 | H1–H7 continuation proves only that unrelated work is runnable, so a global halt can masquerade as z | see phase exits / two-work-item fault test: A persists H1–H7 and pro |
| R101 | P1b can authorize provider/tool/sandbox/ACT before Decision v3, trusted issuer keys, revocation head | see phase exits / P1b before P2 is characterization/refusal-only; P2 |
| R102 | AWIS mutative classification omits `autonomos`/unknown and TaskServing masks Autônomos as Dev, enabl | see phase exits / mode is derived from signed native authority; Dev/ |
| R103 | internal owner tests can pass while canonical public entries route to the wrong executor, require a  | see phase exits / fresh-process matrix proves `atlas dev`, `atlas fo |

**Non-waivable for DONE:** R33,R34,R35,R38,R40,R43,R46,R51–R103 (R44 folded into R66). HORIZON only where table says HORIZON (R47,R48,R50 partial).

---

# P0 — truth before capability

**Objective:** remove false success; unify daily port; honest projection. **Does not** fix Brain args (R33).

## P0 production paths (CLOSED — every path literal)

```
app/Console/Commands/AtlasAaeosRunCommand.php
app/Console/Commands/AtlasAaeosCycleCommand.php
app/Console/Commands/AtlasAaeosScorecardCommand.php
app/Console/Commands/AtlasAaeosCertifyCommand.php
app/Console/Commands/AtlasCliCockpitCommand.php
app/Console/Commands/AtlasAaeosRouterCommand.php
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Aaeos/Control/AaeosIntentCompiler.php
app/Services/Ai/Aaeos/Control/AaeosAdmissionPolicy.php
app/Services/Ai/Aaeos/Control/AaeosAdmissionVerdict.php
app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php
app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php
app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
docs/engineering-knowledge-base/atlas-cli-daily-map.md
docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md
docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md
docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P0.json
```

**Deletes (after `rg` proves zero production consumers):**
```
app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php
```
Run first:
```bash
rg -n 'AaeosOperateScorecardProjector' app tests --glob '!**/AaeosOperateScorecardProjector.php'
# must be 0 production refs before delete
```

## P0 test paths (CLOSED)

```
tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php
tests/Feature/Ai/Aaeos/AaeosGodSotaCertificationTest.php
tests/Feature/Ai/Aaeos/AtlasAaeosRunCommandTest.php
tests/Unit/Ai/Aaeos/Control/AaeosRunCycleParityTest.php
tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php
tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php
tests/Unit/Ai/Aaeos/Control/AaeosLedgerMeasurementReaderTest.php
tests/Unit/Ai/Aaeos/Control/AaeosAdmissionTaxonomyTest.php
tests/Unit/Ai/Aaeos/Control/AaeosHumanLoopFieldRetirementTest.php
tests/Unit/Ai/Aaeos/Control/AaeosIrreversibilitySuspicionCharacterizationTest.php
tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php
```

## P0 steps (order)

### P0.0 Preflight
```bash
git branch --show-current  # main
BASE=$(git rev-parse HEAD)
/opt/homebrew/bin/php artisan atlas:aaeos:certify --json | tee /tmp/aaeos-cert-before.json
/opt/homebrew/bin/php artisan atlas:aaeos:scorecard --json | tee /tmp/aaeos-score-before.json
```

### P0.1 RED tests first (must fail on HEAD)

**AaeosReceiptHonestyTest:** dry runCycle → `runtime_write_performed===false`, `dry_run===true`.  
**AaeosMeasuredScorecardTest:** zero sample → no fake measured composite from constants; god_sota not from static mean≥9.  
**AaeosAdmissionTaxonomyTest:** invalid mode → `repair_required` not `halt_sovereign`.  
**AaeosHumanLoopFieldRetirementTest:** no authoritative **read** of human_in_engineering_loop in CycleRuntime receipt assembly / Certify gate (dispatcher writes inventory only).  
**AaeosRunCycleParityTest / feature tests:** same normalized input → same exit class for halted/dispatch_failed; both non-zero on failure.  
**AaeosIrreversibilitySuspicionCharacterizationTest:** regex sets suspicion flag only; does not alone authorize effect.  
**AaeosGodSotaCertificationTest:** invert asserts that require inject 9.2 / human_in_loop gate.

```bash
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php --no-coverage
# expect RED
```

### P0.2 Production fixes

1. **AaeosAdmissionVerdict** — add `REPAIR_REQUIRED = 'repair_required'`; `allowsExecution()===false`.  
2. **AaeosAdmissionPolicy** — invalid/unknown mode → REPAIR_REQUIRED (not HALT_SOVEREIGN).  
3. **AaeosCycleRuntime** — derive `runtime_write_performed`; dry always false; stop authoritative human field emission; freeze remaining dispatcher human writes for P1a inventory.  
4. **Run + Cycle commands** — both call **AaeosCycleRuntime** only; shared flag normalization; dry must not call OutcomeRecorder write path; dispatch_failed exit non-zero both.  
5. **ScorecardProjector** — remove static 9.x as measured; unknown/null without sample.  
6. **CertifyCommand** — remove inject 9.2/9.2/9.0; remove human_in_loop===false gate.  
7. **IntentCompiler** — keep regex as suspicion only (document); no authority.  
8. **Delete** OperateScorecardProjector after rg=0.  
9. **Cockpit/Router/daily-map** — cannot teach GOD_SOTA without measured evidence.  
10. **Elite executors doc** — point to this MASTER vFINAL-EXEC (not v6).

### P0 exit (all required)

- [ ] run/cycle share **AaeosCycleRuntime** (no AaeosRunApplication class)
- [ ] dry zero runtime writes; no OutcomeRecorder ledger write on dry
- [ ] dispatch_failed → non-zero both ports
- [ ] rwp derived
- [ ] prepared vs claimed vs mutated distinct in receipt vocabulary
- [ ] zero authoritative **readers** of human_in_engineering_loop in certify/runtime assembly
- [ ] invalid technical → repair_required not sovereign halt
- [ ] regex/hints suspicion only
- [ ] zero-sample measures unknown/null
- [ ] certify/cockpit cannot emit GOD_SOTA from static composite
- [ ] PHASE-P0.json + LEDGER/SCOREBOARD updated
- [ ] scoped commit(s) on main

**Commits:** prefer one truth/port + optional second for measured-reader if diff huge.

```
feat(core): AAEOS-MT P0 honesty port admission and measured projection
```

---

# P1a — native dispatch (no new executor interface)

**Objective:** deepen existing `AaeosModeLiveDispatcher` port; delete 4 Adapters after parity; fix R33–R35; extract daemon seam. **No provider/effect** until P1b+P2 gates.

## P1a production paths (CLOSED)

```
bin/atlas
config/atlas_dev.php
app/Console/Commands/AtlasCliDevCommand.php
app/Services/Ai/Cli/AtlasCliDevEfficientHandler.php
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Aaeos/Control/Dispatch/AaeosModeLiveDispatcher.php
app/Services/Ai/Aaeos/Control/Dispatch/AaeosLiveDispatchGateway.php
app/Services/Ai/Aaeos/Control/Dispatch/DevLiveDispatcher.php
app/Services/Ai/Aaeos/Control/Dispatch/ForgeLiveDispatcher.php
app/Services/Ai/Aaeos/Control/Dispatch/AutonomosLiveDispatcher.php
app/Services/Ai/Aaeos/Control/Adapters/AaeosExecutorModeAdapter.php
app/Services/Ai/Aaeos/Control/Adapters/DevModeAdapter.php
app/Services/Ai/Aaeos/Control/Adapters/ForgeModeAdapter.php
app/Services/Ai/Aaeos/Control/Adapters/AutonomosModeAdapter.php
app/Services/Ai/Programming/AtlasDev/Execution/DevIntent.php
app/Services/Ai/Programming/AtlasDev/Execution/ConfirmedDevRun.php
app/Services/Ai/Programming/AtlasDev/Execution/DevPlanRunFacade.php
app/Services/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionService.php
app/Http/Controllers/AtlasDev/Support/KernelRunExecutor.php
app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopExecutor.php
app/Providers/AtlasDevServiceProvider.php
app/Services/Ai/Programming/Forge/Execution/ForgeCommissioning.php
app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php
app/Services/Ai/Programming/Forge/Execution/ForgeObraSupervisor.php
app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php
app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php
app/Console/Commands/AtlasForgeLiveExecuteCommand.php
app/Services/Ai/SelfConstruction/ContinuousRuntime/AtlasSelfConstructionContinuousRuntimeCycleRunner.php
app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycle.php
app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemon.php
app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionNativeActionExecutor.php
app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSchedulerManifest.php
app/Services/Ai/SelfConstruction/TaskServing/AtlasTaskBrainReplenisher.php
app/Services/Ai/SelfConstruction/AtlasTaskServingStack.php
app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerProductionCallbacks.php
app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php
app/Console/Commands/AtlasSelfConstructionRuntimeDaemonCommand.php
routes/console.php
```

## P1a test paths (CLOSED)

```
tests/Unit/Ai/Aaeos/Control/AaeosOperateDispatchTest.php
tests/Unit/Ai/Aaeos/Control/AaeosNativeDevDispatchContractTest.php
tests/Unit/Ai/Aaeos/Control/AaeosNativeForgeDispatchContractTest.php
tests/Unit/Ai/Aaeos/Control/AaeosNativeAutonomosDispatchContractTest.php
tests/Feature/Ai/Aaeos/AaeosDirectModeAblationTest.php
tests/Unit/Ai/Programming/Forge/Execution/ForgeObraRuntimeContractTest.php
tests/Unit/Ai/Programming/AtlasForgeLiveExecutionServiceTest.php
tests/Unit/Ai/SelfConstruction/ContinuousRuntime/AtlasSelfConstructionContinuousRuntimeCycleRunnerTest.php
tests/Unit/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycleTest.php
tests/Feature/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonParityTest.php
tests/Feature/Ai/Aaeos/AaeosDailyIntentOnlyCommissioningTest.php
tests/Feature/Ai/Aaeos/AaeosPublicEntryModeParityTest.php
tests/Feature/Ai/Programming/Forge/ForgeObraSupervisorForwardProgressTest.php
tests/Feature/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSchedulerIntegrationTest.php
tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycleTest.php
```

## P1a required behavior

### R33 brainNextArgs (exact)

```php
// REQUIRED
return [
    'scope' => trim((string)($options['scope'] ?? '')) ?: 'autonomous',
    '--json' => true,
];
// FORBIDDEN: '--scope' => ...
```

### R34
exit_code===0 AND json status in {disabled,dry,error} ⇒ not mutated success.

### R35
No `--max` on seed. Real seed flags only; prefer in-process replenisher enqueue when wired.

### Modes
- Dev: native DevIntent/ConfirmedDevRun → SeniorLoop→KernelRunExecutor→AtlasDevExecutionService  
- Forge: ForgeCommissioning → ForgeObraRuntime; live command stays characterization until authority green  
- Autônomos: call extracted `AtlasSelfConstructionRuntimeDaemon` (not Artisan step soup); return native refs  
- task next = claimed not worker_executed  
- Delete synthetic packs/worklists after parity  
- Adapters deleted only after compile+runtime parity  

**P1a exit:** no Adapter consumers; R33–R35 green; ablation green; provider/mutation still disabled pending R101/P1b+P2.

```
refactor(core): AAEOS-MT P1a native dispatch and brain transport
```

---

# P1b — effect authority (manifest; activation after P2a+P2b)

**Before P2a+P2b+R102 green:** every P1b path is **characterization/refusal-only** — zero provider/tool/sandbox/mutation.

### P1b.1 pre-effect replay — production paths
```
app/Services/Ai/EngineeringKernel/ExecutionOrder.php
app/Services/Ai/EngineeringKernel/EngineeringModeExecutionOrderFactory.php
app/Services/Ai/EngineeringKernel/EliteExecutorKernel.php
app/Services/Ai/EngineeringKernel/KernelEvidenceAuthority.php
app/Services/Ai/Programming/AtlasDev/Execution/EliteExecutorKernelDevAdapter.php
app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
app/Services/Ai/Kernel/Decision/DecisionReceipt.php
app/Services/Ai/Kernel/Decision/DecisionReceiptHash.php
app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php
app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php
app/Services/Engineering/CodeGraph/CodeGraphWorkspaceAccessPolicy.php
app/Services/Engineering/CodeGraph/CodeGraphWorkspacePrivacy.php
```
Exit: caller-authored order cannot cause provider/sandbox/mutation until decision reloaded.

### P1b.2 native actuator — production paths
```
app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php
app/Services/Ai/SelfConstruction/Governance/AtlasTaskMergeActuator.php
app/Services/Ai/EngineeringKernel/AuthorizedMergeAction.php
app/Services/Ai/EngineeringKernel/AuthorizedRevertAction.php
app/Services/Ai/EngineeringKernel/CanarySettlementRequest.php
app/Services/Ai/EngineeringKernel/KernelEvidenceAuthority.php
app/Services/Ai/EngineeringKernel/Adapters/AgentExecutionProviderPortAdapter.php
app/Services/Ai/Governance/ProviderGovernanceCoverageLedger.php
app/Services/Ai/Governance/ProviderGovernanceConsult.php
app/Services/Ai/Governance/GovernanceConsultSkipCounter.php
app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionNativeActionExecutor.php
app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerCommandPlanRunner.php
app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php
app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionHermeticSandboxApplyService.php
app/Services/Ai/Runtime/AiToolProcessRunner.php
app/Services/Tools/AtlasToolEvidenceStore.php
app/Models/AtlasToolArtifact.php
app/Support/AtlasSecurity.php
```
Exit: observed write-set wins over declared read_only; present-but-false golden; auth replay under lock; provider spawn set-equality; external generic AAEOS blocked.

### P1b.3 AAEOS projection only
```
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Aaeos/Control/AaeosAdmissionPolicy.php
app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
```

### P1b tests (minimum set — extend as RED requires; amend MASTER if new path)
```
tests/Unit/Ai/EngineeringKernel/TypedEngineeringContractTest.php
tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php
tests/Feature/Ai/EngineeringKernel/CanonicalCommitActuationTest.php
tests/Feature/Ai/AtlasTaskScopedCommitterTest.php
tests/Feature/Ai/Aaeos/AaeosEffectAuthorityProtocolTest.php
tests/Feature/Ai/Aaeos/AaeosAuthorityReplayToctouTest.php
tests/Feature/Ai/Aaeos/AaeosAuthorityLaunderingTest.php
tests/Feature/Ai/Aaeos/AaeosProviderToolBoundaryInjectionTest.php
tests/Unit/Ai/Governance/ProviderGovernanceCoverageLedgerTest.php
tests/Unit/Ai/Governance/ProviderGovernanceConsultTest.php
tests/Feature/Ai/Aaeos/AaeosProviderSpawnSetEqualityTest.php
tests/Feature/Ai/Aaeos/AaeosFilesystemOperationIdentityTest.php
tests/Feature/Ai/Runtime/AiToolProcessRunnerAuthorityBoundaryTest.php
```

**No** AaeosActionEffectClassifier / SovereigntyPort / second ledger.

---

# P2 — authority / evidence / durability

### Binding order (serial)
P2a.1 Ledger v2 + PG roles → P2a.2 EngineeringOutcome v3 expand/dual-read/shadow → P2b Decision v3 EXPAND→SHADOW→CANARY→CUTOVER + AWIS → … (see §0 DAG)

### P2a production (core)
```
app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php
app/Models/AtlasLedgerEvent.php
app/Services/Ai/Kernel/Evidence/LedgerEventType.php
app/Services/Ai/EngineeringKernel/EngineeringOutcome.php
config/database.php
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
database/migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php
```
(+ any migration files created under that naming scheme listed in PHASE-P2A1.json)

Exit: full envelope hash verify; linear head; dual-read EO v3; tenant isolation; producer vs verifier PG roles exact.

### P2b
DecisionReceipt v3 + keyring + revocation; AWIS mutative includes autonomos; unknown fail-closed; no mode downgrade.

### P2c–f
Journey single-root; crash resume; H1–H7 A/B witness; operator census; strip productive flags from aaeos:run after parity (explicit migration errors).

**Full path lists for P2c–f:** use archived v16 §8 P2 subsections if a path is missing — **amend this MASTER** before editing unlisted path (closed manifest law).

---

# P3 — deletion / alignment / economy / verification surface

Production includes (closed set — v16):
```
composer.json
config/atlas.php
routes/console.php
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Aaeos/Control/AaeosTriHygieneScorecardProjector.php
app/Console/Commands/AtlasTriHygieneScorecardCommand.php
app/Services/Ai/Compat/AaeosHygieneLegacyAliases.php
app/Services/Ai/Aaeos/Control/AaeosModeToDualCoreRoute.php
app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
app/Services/Ai/Aaeos/Control/AaeosOrgStateProjector.php
app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php
app/Services/Ai/SelfConstruction/Maestro/Cost/AtlasMaestroCostAggregator.php
app/Console/Commands/AtlasAaeosCycleCommand.php
app/Console/Commands/AtlasAaeosRunCommand.php
app/Console/Commands/AtlasCliCockpitCommand.php
app/Console/Commands/AtlasReviewDeepCommand.php
app/Console/Commands/AtlasAaeosRouterCommand.php
app/Console/Commands/AtlasCliHelpCommand.php
app/Console/Commands/AtlasApiDescribeCommand.php
app/Services/Ai/CODEMAP.md
app/Services/Ai/Aaeos/README.md
docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
docs/engineering-knowledge-base/atlas-cli-daily-map.md
docs/engineering-knowledge-base/atlas-terminal-first-focus.md
docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
```
(+ landing review/inbox paths only when census amends MASTER)

Exit: TriHygiene deleted; economy view; verification view; lazy aliases or proven burn; 17-phase not daily; no GOD_SOTA vanity.

---

# P4 — REAL_OPERATION gauntlet

**Preconditions:** P0–P3 receipts green; automated R33/R34/R51–R103 source portions green; ops preflight.

| Mode | Producer | Must prove |
|---|---|---|
| Dev | SeniorLoop / Dev native | full journey root; repairs allowed; REAL_OPERATION |
| Forge | ForgeObraRuntime + supervisor → completeObra | terminal winner; no post-seal tech human |
| Autônomos | RuntimeDaemon direct first | brain→seed→claim→worker→land OR blocked_ops PARTIAL |

Every qualifying journey:
- out of PHPUnit; durable DB; real effect where required  
- journey_terminal_status=real_operation_completed  
- COVERED provider spawn OR explicit fail  
- fresh-process certifier SELECT-only  
- OneShot server-derived if claimed  
- standing mandate binding for Autônomos (pre-signed)  

**Partial program:** P0–P3 DONE + P4 Dev/Forge real + Autônomos blocked_ops is valid **incomplete** state — not DONE.

---

## DONE predicate

DONE only if:
1. All phase receipts green for P0–P4 (or Autônomos explicit incomplete only if program marked PARTIAL — not full DONE)
2. R33,R34,R35,R38,R40,R43,R46,R51–R103 closed (non-HORIZON)
3. Three-mode REAL_OPERATION (full DONE)
4. No second ledger/organ; Quarantine/ACDE clean; scoped main commits
5. capability_proof ≥ internal_only derived not caller-set

**Not DONE:** SUSTAINED, comparative 50×, company 100% Atlas.

---

## Security deepenings (binding; owners existing)

Folded from v16 cycle-9: signed tenant/mode; capability chain + parent nonce/budget; workspace device/inode/base SHA; pre-exec binary attestation; secret refs at boundary; provider cannot self-certify; ledger full-envelope verify; one root resource ceiling; instruction provenance; blackboard advisory only.

---

## Tombstones

| Path | Status |
|---|---|
| `…-MASTER-FINAL.md` | alias → this file |
| `…-MASTER-CLAUDE.md` | ARCHIVED |
| `archive/…/MASTER-v16-pre-final.md` | **SUPERSEDED** historical body; residual detail reference |
| `archive/…/MASTER-CLAUDE-C6.md` | historical |

---

## Handoff for Codex

### Authority (read first)

| Path | Role |
|---|---|
| **This file** | Sole law. Implement **only** what the operator EXECUTE slice names. |
| `docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md` | Phase cursor satellite |
| `docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md` | Gate FAIL/PASS satellite |
| `docs/superpowers/plans/archive/aaeos-elite-deepening-2026-07-23/MASTER-v16-pre-final.md` | Historical residual **detail** for R52–R103 when a row is truncated; **never** re-introduce `AaeosRunApplication` or other SUPERSEDED architecture from v16 §3 |
| `…-MASTER-FINAL.md` / `…-MASTER-CLAUDE.md` | Aliases / archived — **do not implement from** |

### Operator gate

```text
without literal "EXECUTE P0"  → zero production PHP edits
"EXECUTE P0"                  → §P0 only, then STOP
"EXECUTE P1a"                 → §P1a only after PHASE-P0 green
```

### PHASE-P0.json (closed schema — write under evidence/)

```json
{
  "schema": "atlas.aaeos.mt.phase_receipt.v1",
  "phase": "P0",
  "status": "GREEN|RED|PARTIAL",
  "edition": "vFINAL-EXEC",
  "base_commit": "<git rev-parse HEAD before slice>",
  "head_commit": "<git rev-parse HEAD after scoped commit(s)>",
  "branch": "main",
  "paths_touched": ["…exact list…"],
  "tests_red_then_green": ["…test class paths…"],
  "exit_checklist": {
    "shared_cycle_runtime_no_run_application": true,
    "dry_zero_runtime_writes": true,
    "dispatch_failed_nonzero_both_ports": true,
    "rwp_derived": true,
    "human_loop_readers_retired_certify_runtime": true,
    "invalid_mode_repair_required": true,
    "no_static_god_sota": true,
    "regex_suspicion_only": true
  },
  "residuals_closed": ["R17", "R21", "R37", "R38", "R40", "R71", "…"],
  "residuals_still_open": ["R33", "R34", "R35", "…"],
  "forbidden_touched": false,
  "notes": "short operator-safe notes"
}
```

### Codex ritual after `EXECUTE P0`

1. `git branch --show-current` must be `main`  
2. Re-read **§P0** closed path lists — edit **only** those paths (or amend this MASTER first)  
3. RED tests (§P0.1) → production fixes (§P0.2) → GREEN  
4. Scoped `git add -- <paths>` + commit message in §P0  
5. Write `PHASE-P0.json` + update LEDGER + SCOREBOARD  
6. **STOP** — do not start P1a  

### Hard bans (every slice)

- `git add -A` · force-push · work branch · stash-to-hide-WIP  
- New class `AaeosRunApplication` / `AaeosModeExecutor` / `SovereigntyPort` / second ledger  
- Fix R33 brain args in P0  
- Provider/effect/mutation in P0 or P1a  
- Claim GOD_SOTA / REAL_OPERATION from tests or static scores  

**This is the executable god-SOTA plan of record.**  
**System god-SOTA starts when PHASE receipts are green on disk — not when this plan is complete.**
