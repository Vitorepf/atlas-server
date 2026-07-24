# AAEOS — CANONICAL IMPLEMENTATION COOKBOOK (vFINAL-COOKBOOK)

> **Status:** CANONICAL · PLAN_ONLY · implementer follows this file like a script  
> **Edition:** **vFINAL-COOKBOOK** (supersedes vFINAL-EXEC sparsity; fuses constitution + full v16 body + step recipes)  
> **Authority:** sole master. Satellites only: LEDGER.md + SCOREBOARD.md  
> **Branch:** local `main` only · scoped `git add -- <paths>` · never `git add -A`  
> **Evidence dir:** `docs/evidence/2026-07-23-aaeos-elite-deepening/`  
> **Archive (reference only):** `docs/superpowers/plans/archive/aaeos-elite-deepening-2026-07-23/`  
> **Freeze:** no plan cycle-10 unless NEW residual discovered on disk during implement  

```text
AI RULE #0 — DO NOT THINK, FOLLOW
1. Read ONLY the slice named by the operator EXECUTE phrase.
2. Edit ONLY the closed path list of that slice.
3. Write RED tests first → production fixes → GREEN → PHASE receipt → LEDGER/SCOREBOARD → scoped commit → STOP.
4. If a RED needs an unlisted path: STOP. Do not freestyle. Report path; human amends MASTER first.
5. Never invent architecture, second ledger, AaeosRunApplication, Mission, WorkGraph, SovereigntyPort, ModeExecutor.
```

```text
OPERATOR GATES (literal strings)
EXECUTE P0
EXECUTE P1a
EXECUTE P1b.1 | EXECUTE P1b.2 | EXECUTE P1b.3
EXECUTE P2a.1 | EXECUTE P2a.2
EXECUTE P2b-EXPAND | EXECUTE P2b-SHADOW | EXECUTE P2b-CANARY | EXECUTE P2b-CUTOVER | EXECUTE P2b-CONTRACT
EXECUTE P2c | EXECUTE P2d | EXECUTE P2e | EXECUTE P2f
EXECUTE P3a | EXECUTE P3b
EXECUTE P4-DEV | EXECUTE P4-FORGE | EXECUTE P4-AUTONOMOS | EXECUTE P4-FREEZE
Without an EXECUTE phrase → zero production PHP.
```

Conflict order: (1) live code + durable evidence (2) this phase's closed paths + exit checklist (3) DONE predicate (4) residual hard-done (5) NOT_PROVEN ≠ PASS.

---

## 0. Universal ritual (copy every slice)

```bash
set -euo pipefail
cd /Users/vitorepf/develop/Atlas/atlas-server
git branch --show-current   # MUST print: main
git status --short
BASE=$(git rev-parse HEAD)
echo "BASE=$BASE"

# optional baseline (do not "fix" unrelated dirt)
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Aaeos/Control tests/Feature/Ai/Aaeos --no-coverage || true

# === implement ONLY this slice's closed paths ===
# RED tests first (must FAIL on HEAD)
# production edits
# GREEN tests
# write PHASE-*.json
# update LEDGER.md + SCOREBOARD.md

git add -- <exact paths from closed list + evidence files>
git status --short
git diff --cached --stat   # must match only this slice
git commit -m "<commit message from slice>"
# STOP — do not start next slice
```

### 0.1 Closed evidence artifacts (only these)

`PHASE-P0.json`, `PHASE-P1A.json`, `PHASE-P1B1.json`, `PHASE-P1B2.json`, `PHASE-P1B3.json`, `PHASE-P2A1.json`, `PHASE-P2A2.json`, `PHASE-P2B-EXPAND.json`, `PHASE-P2B-SHADOW.json`, `PHASE-P2B-CANARY.json`, `PHASE-P2B-CUTOVER.json`, `PHASE-P2B-CONTRACT.json`, `PHASE-P2C.json`, `PHASE-P2D.json`, `PHASE-P2E.json`, `PHASE-P2F.json`, `PHASE-P3A.json`, `PHASE-P3B.json`, `PHASE-P4-DEV.json`, `PHASE-P4-FORGE.json`, `PHASE-P4-AUTONOMOS.json`, `PHASE-P4-FREEZE.json`, plus LEDGER.md + SCOREBOARD.md.

### 0.2 Universal PHASE receipt schema

```json
{
  "schema": "atlas.aaeos.mt.phase_receipt.v1",
  "phase": "<SLICE_ID>",
  "status": "GREEN|RED|PARTIAL",
  "edition": "vFINAL-COOKBOOK",
  "base_commit": "<BASE>",
  "head_commit": "<after commit>",
  "branch": "main",
  "allowed_paths": [],
  "touched_paths": [],
  "deleted_paths": [],
  "tests_red_then_green": [{"path":"…","red_exit":1,"green_exit":0}],
  "exit_checklist": {},
  "residuals_closed": [],
  "residuals_still_open": [],
  "forbidden_touched": false,
  "next_phase_authorized": false,
  "notes": ""
}
```

### 0.3 Hard bans (every slice)

- `git add -A` · force-push · work branch · stash-to-hide-WIP · merge/pull that creates merge  
- New classes: `AaeosRunApplication`, `AaeosModeExecutor`, `SovereigntyPort`, second ledger, Mission/WorkGraph, Quarantine revive, ACDE  
- Kernel called from AAEOS Control  
- Fix R33 in P0  
- Provider/tool/sandbox/mutation before P1b.2 gate (and never before P2b CUTOVER — R101)  
- GOD_SOTA / REAL_OPERATION from PHPUnit or static scores  
- Hardcode `human_in_engineering_loop=false` as identity  

### 0.4 Binding DAG (do not reorder)

```text
P0
 → P1a (structural; provider/effect REFUSED)
 → P2a.1 Ledger v2 + PG roles
 → P2a.2 EngineeringOutcome v3 expand/dual-read/shadow
 → P2b EXPAND → SHADOW → CANARY → CUTOVER (+ AWIS R102)
 → P1b.1 pre-effect authority replay
 → P2c native lineage / crash durability
 → P1b.2 native ACT/settlement (+ tool redaction)
 → P1b.3 AAEOS projection only
 → P2d Spine settlement refs
 → P2e async H1–H7
 → P2f operator census / strip technical CLI flags
 → P2b CONTRACT (after old-worker drain)
 → P3a census → P3b deletion/alignment
 → P4-DEV → P4-FORGE → P4-AUTONOMOS → P4-FREEZE
```

Slices that touch `AtlasEvidenceLedger.php` **never** run concurrently.

---

## 1. Constitution (do not re-architect)

### 1.1 Mother block
AAEOS = law at shared seams (provider gov, mutative gate, land/settle, courts, ledger). Thin muscle. Router `atlas:aaeos:run|cycle` has **no** productive flags long-term (`--live/--execute-provider/--max-seeds/--run-worker-once/--scope` are native-only; strip in P2f/P3 after parity).

### 1.2 Executors
Same L0–L5 bar. Diff = horizon/origin/sovereignty. Eng judgment agentic. Remove authoritative `human_in_engineering_loop`.

### 1.3 OneShot
Operator UX label only — not one code pass. Server-derived from sealed ingress/egress events. Autônomos: zero task-causal operator actions.

### 1.4 Effect protocol
SUSPECT → PRE-AUTHORIZE → ACT → POST-ATTEST → SETTLE on **native** owners.  
LAND nonce = `AuthorizedMergeAction::nonce`. SETTLE = `CanarySettlementRequest::idempotencyHash`. Exactly-one via hard INSERT ledger `event_id`.

### 1.5 R4
`AutonomosLiveDispatcher` → `atlas:brain:next` = **SOURCE_WIRED only** until P1a args + payload class + P4 journey.

### 1.6 Score fiction (exact disk today)
Certify injects: `operate_path_wiring=9.2`, `spine_enforced=9.2`, `antifragile_loop=9.0` (`AtlasAaeosCertifyCommand.php` ~67–69).  
Projector defaults: operate/spine/antifragile **9.0**, control_plane **9.2** (`AaeosScorecardProjector.php`).  
CycleRuntime: `runtime_write_performed => true` hardcode line ~93; `human_in_engineering_loop` line ~107.  
P0 removes all as measured truth.

### 1.7 M
`enforced_governed_coverage = COVERED/total` (not ledger `governed` that folds consulted). Comparative SOTA = Rivals only.

### 1.8 Signing / Autônomos zero-touch
Standing mandate pre-signed (Ed25519 `HumanDecisionReceiptSigner`). Journey roots **derive** mandate id/hash/revision — operator does not sign every Autônomos root. P4: key custodied off journey process.

### 1.9 Shared use-case
**No `AaeosRunApplication`.** Both commands call `AaeosCycleRuntime` (`runCycle` / `runAutonomosCycle`). Shared flag normalization: private helpers or small trait on commands — not a new public application class.

### 1.10 R64 note (verdict enum)
Add **one** const `REPAIR_REQUIRED = 'repair_required'` (4th). Do **not** grow a 6-state mini-governor. Invalid/unknown mode → REPAIR_REQUIRED. Irreversible / business_ambiguous (non-dev) / high-severity world still may HALT_SOVEREIGN.

---

## 2. Disk truth (re-verify every slice start)

| Fact | Disk anchor | Closes |
|---|---|---|
| rwp hardcoded true | `AaeosCycleRuntime.php:93` | P0 |
| human_in_loop receipt | `AaeosCycleRuntime.php:107` | P0 readers; P1a dispatcher writes |
| certify inject 9.2 | `AtlasAaeosCertifyCommand.php:67-69` | P0 |
| certify human gate | `AtlasAaeosCertifyCommand.php:34` | P0 |
| invalid_mode → HALT | `AaeosAdmissionPolicy.php:35-36` | P0 |
| brain `--scope` | `AutonomosLiveDispatcher.php:89-96` | P1a R33 |
| seed invents `--max` | `AutonomosLiveDispatcher.php:55-58` | P1a R35 |
| Run always records outcomes | `AtlasAaeosRunCommand.php:68` even dry | P0 |
| Cycle exit only checks halted | `AtlasAaeosCycleCommand.php:47,62` | P0 |
| RuntimeDaemon service | **MISSING** (extract in P1a R98) | P1a |
| OperateScorecardProjector | exists; delete after rg=0 | P0 R71 |

```bash
rg -n 'human_in_engineering_loop|runtime_write_performed' app/Services/Ai/Aaeos app/Console/Commands/AtlasAaeosCertifyCommand.php
rg -n 'brainNextArgs|--scope|--max' app/Services/Ai/Aaeos/Control/Dispatch/AutonomosLiveDispatcher.php
rg -n 'operate_path_wiring|9\.2' app/Console/Commands/AtlasAaeosCertifyCommand.php app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
```

---

## 3. Residual index (binding)

Phase column = earliest close. Full hard-done text for R64–R103 is in **§3.1** below (copied from v16). Phase exits win if conflict with prose.

| ID | Gap | Phase |
|---|---|---|
| R1 | Spine N9/N11 partial | P2d |
| R2 | daily Spine coverage partial | P2d |
| R3 | Dev/Forge full rewrite unsafe | P1/P2 strangler |
| R4 | brain:next source call exists | CLOSED_SOURCE (never live proof alone) |
| R5 | no real Autônomos operation receipt | P4 |
| R6 | DualCore record best-effort | P0/P1 visible status |
| R7 | skipped evidence can fail open | P0/P2 fail explicit |
| R8 | world snapshot silent default | P0 measured provenance |
| R9 | legacy aliases deferred | P3 |
| R10 | dual Aaeos/AEOS maps; AEOS unwired | P1a R73 + P3 docs |
| R11 | docs-as-PHP reduction intentional | ACCEPTED |
| R12 | Quarantine absent | CLOSED/HOLD |
| R13 | HTTP/desktop not daily | HORIZON |
| R14 | provider opt-in looks inert | P0 honest status |
| R15 | cockpit not eng loop | P1/P3 read model |
| R16 | score mixes hints/static | P0 |
| R17 | rwp hardcoded true | P0 |
| R18 | dispatched_live conflates levels | P0 typed effect_level |
| R19 | score lacks provenance | P0 |
| R20 | counter store duplicates Ledger | PROHIBITED |
| R21 | run/cycle diverge | P0 shared CycleRuntime — **no RunApplication** |
| R22 | RunCommand lacks complete test | P0 |
| R23 | source/mock/dry/real mixed | all phases §6 ladder |
| R24 | numeric target launders DONE | hard-gate only |
| R25 | blocked ops replace live proof | P4 incomplete if so |
| R26 | rg=0 insufficient alias proof | P3 classmap/runtime |
| R27 | ObserveRegistry preselected | DELETE unless measured need |
| R28 | Spine criteria lacked owner | P2d |
| R29 | OneShot fused with attempt count | OneShot=UX only |
| R30 | dirty-main baseline | §0 ritual |
| R31 | archive deletion as future work | CLOSED/HOLD |
| R32 | CODEMAP legacy alias | P3 |
| R33 | brain scope flag invalid | **P1a** |
| R34 | exit0 disabled/dry false success | **P1a** |
| R35 | seed --max invented | **P1a** |
| R36 | live needs preflight | P4 blocked_ops |
| R37 | run/cycle flags/exit differ | P0 |
| R38 | human field authoritative | P0/P1a |
| R39 | review presence≠quality | P0/P3 |
| R40 | technical invalidity = sovereignty | P0 R64 |
| R41 | v6 mode axes | deleted |
| R42 | 17-phase as operate | P3 demote |
| R43 | CLI-to-CLI Brain/Seed | P1a extract |
| R44 | empty Spine self-green | FOLDED→R66 |
| R45 | attention queues as authority | P2e Ledger only |
| R46 | principal independence | P2b |
| R47 | sustained government | HORIZON after P4 |
| R48 | topology ablation | HORIZON Rivals |
| R49 | attention/economy | P3 ITT |
| R50 | external superiority | HORIZON Rivals |
| R51 | regex irreversibility authority | P0 suspicion; P1b native |
| R52–R103 | see §3.1 hard-done tables | phase exits |

**Non-waivable for full DONE:** R33,R34,R35,R38,R40,R43,R46,R51–R103 (R44→R66). HORIZON only where marked.

### 3.1 Residual hard-done detail (R64–R103)

### 7.4 v8 cycle-2 survivors R64–R74

Root judged the cycle-2 adversarial panel (15 lenses) against v7 and disk. Only reuse/delete/fuse survived; every duplicate of an existing owner was rejected — capability_proof is already killed by §5.4/R58, the 5-axis envelope is already collapsed by §5.1, the cockpit proof/economy render is already committed in P3, and a 6-state Aaeos admission enum duplicates AtlasMergeGovernorAdmissionPolicy. Each survivor names the existing owner it reuses or deletes; none creates a new organ.

| ID | Gap surpassing v7 | Existing owner reused / deleted | Hard done condition |
|---|---|---|---|
| R64 | R40 fix under-specified: an implementer could de-conflate technical vs sovereign by growing the verdict enum | reuse DECISION_REPAIR string `'repair_required'` | **COOKBOOK override:** add **one** const `REPAIR_REQUIRED` (4th — not a 6-state mini-governor). invalid_mode/unknown-mode → repair_required, never HALT_SOVEREIGN; only irreversible/business_ambiguous/sovereign world reasons use HALT_SOVEREIGN |
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



---

# SLICE CATALOG — FOLLOW EXACTLY

---

# SLICE P0 — truth before capability

**Gate phrase:** `EXECUTE P0`  
**Objective:** remove false success; unify ports on CycleRuntime; honest projection.  
**Does NOT:** fix Brain args (R33), seed (R35), provider, Decision v3, Ledger chain.

## P0 closed production paths

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

**Delete only after zero production consumers:**
```
app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php
```
```bash
rg -n 'AaeosOperateScorecardProjector' app tests --glob '!**/AaeosOperateScorecardProjector.php'
# must be 0 production refs
```

## P0 closed test paths

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

## P0 ordered steps (do in order)

### P0.0 Preflight
```bash
git branch --show-current
BASE=$(git rev-parse HEAD)
/opt/homebrew/bin/php artisan atlas:aaeos:certify --json | tee /tmp/aaeos-cert-before.json || true
/opt/homebrew/bin/php artisan atlas:aaeos:scorecard --json | tee /tmp/aaeos-score-before.json || true
```

### P0.1 Write RED tests FIRST (must fail on current HEAD)

#### `tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php` (NEW)
Assert via `AaeosCycleRuntime::runCycle('x', [], [], dryRun: true)`:
- `$r['dry_run'] === true`
- `$r['runtime_write_performed'] === false`
- `$r['evidence_status']` is not a successful ledger write claim when dry
- no `human_in_engineering_loop` key is **authoritative** for admit/certify (key may be absent; if present must not be used by certify gate)

#### `tests/Unit/Ai/Aaeos/Control/AaeosAdmissionTaxonomyTest.php` (NEW)
```php
// invalid mode
$pack = $policy->admit($obj, $diff, ['mode' => 'not_a_mode'], []);
// REQUIRED:
// $pack['verdict'] === AaeosAdmissionVerdict::REPAIR_REQUIRED
// $pack['allows_execution'] === false
// $pack['reasons'] contains 'invalid_mode'
// NOT halt_sovereign
```
Irreversible objective still may be HALT_SOVEREIGN (keep that behavior).

#### `tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php` (NEW)
With empty/zero ledger sample / no runtimeHints:
- measured dimensions are `null` or explicit `unknown` — never default 9.0/9.2/9.5 as measured
- composite cannot mint GOD_SOTA from static means

#### `tests/Unit/Ai/Aaeos/Control/AaeosHumanLoopFieldRetirementTest.php` (NEW)
- `AaeosCycleRuntime` receipt assembly must not emit authoritative engineering-loop boolean for certify
- `AtlasAaeosCertifyCommand` must not require `human_in_engineering_loop === false` for green

#### `tests/Unit/Ai/Aaeos/Control/AaeosRunCycleParityTest.php` (NEW) + feature tests
Same normalized intent + dry:
- both ports use CycleRuntime
- `dispatch_failed` → non-zero exit both
- dry → neither path should claim success via OutcomeRecorder ledger write

#### `tests/Unit/Ai/Aaeos/Control/AaeosIrreversibilitySuspicionCharacterizationTest.php` (NEW)
IntentCompiler regex may set suspicion flags only; alone never authorizes effect / never forces HALT without sovereign reasons.

#### Invert `AaeosGodSotaCertificationTest` / extend CycleCommandTest
Remove/invert asserts that require inject 9.2 or human_in_loop gate for success.

```bash
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php --no-coverage
# expect RED (fail) on HEAD before production edit
```

### P0.2 Production recipes (exact)

#### (1) `AaeosAdmissionVerdict.php`
Add:
```php
public const REPAIR_REQUIRED = 'repair_required';
// include in ALL list
// allowsExecution: only AUTO and AUTO_NOTIFY (REPAIR_REQUIRED => false)
```

#### (2) `AaeosAdmissionPolicy.php`
Change invalid mode branch **only**:
```php
// BEFORE
return $this->pack(AaeosAdmissionVerdict::HALT_SOVEREIGN, ['invalid_mode'], $mode, $level);
// AFTER
return $this->pack(AaeosAdmissionVerdict::REPAIR_REQUIRED, ['invalid_mode'], $mode, $level);
```
Do **not** change irreversible/business_ambiguous/incident HALT_SOVEREIGN paths unless tests prove they are technical invalidity (they are sovereign).

#### (3) `AaeosCycleRuntime.php` receipt assembly
```php
// BEFORE
'runtime_write_performed' => true,
'human_in_engineering_loop' => ($mode['mode'] ?? '') === AaeosExecutorMode::DEV,

// AFTER (derive writes)
'runtime_write_performed' => ! $dryRun && ($receiptEvidenceWritten ?? false),
// omit human_in_engineering_loop OR leave non-authoritative inventory-only under 'legacy_inventory' key if a test needs migration — NEVER feed certify
```
Set `$receiptEvidenceWritten` true only when `recordEvidence` actually persists (not skipped/failed-open as success).

Dry path: never call paths that append ledger.

`effect_level` vocabulary in receipt: distinguish at least `none|prepared|claimed|mutated|blocked` (strings; derived from dispatch status, not caller).

#### (4) `AtlasAaeosRunCommand.php` + `AtlasAaeosCycleCommand.php`
Shared exit helper (duplicate-minimal OK; trait OK; **no** new public Application class):
```php
private function exitCode(array $receipt): int
{
    $status = (string) ($receipt['status'] ?? '');
    if (in_array($status, ['halted', 'dispatch_failed', 'repair_required', 'blocked'], true)) {
        return self::FAILURE;
    }
    $verdict = (string) data_get($receipt, 'admission.verdict', '');
    if ($verdict === 'repair_required') {
        return self::FAILURE;
    }
    return self::SUCCESS;
}
```
Dry-run: **do not** call `$outcomes->record($receipt)` if record writes ledger. Options:
- pass dry into recorder which no-ops, or
- skip call when `$dryRun`.

Both commands must call only `AaeosCycleRuntime`. Align CycleCommand to use same exit taxonomy as Run (not only `halted`).

#### (5) `AaeosScorecardProjector.php`
Remove defaults of 9.0/9.2/9.5 as **measured**. Missing sample → null/unknown. Do not invent GOD_SOTA.

#### (6) `AtlasAaeosCertifyCommand.php`
- Delete inject block assigning operate/spine/antifragile to 9.2/9.2/9.0  
- Delete gate requiring `human_in_engineering_loop === false`  
- GOD_SOTA only if measured evidence predicates pass (if none, not claimed)

#### (7) `AaeosIntentCompiler.php`
Document/comment: irreversibility regex = **suspicion only**. Ensure admission still needs sovereign reasons for HALT (already separate). No authority mint from regex alone.

#### (8) Delete `AaeosOperateScorecardProjector.php` after rg=0; remove DI/bindings if any.

#### (9) Docs
- `atlas-cli-daily-map.md`: cannot teach GOD_SOTA without measured evidence  
- elite-executors: point to this MASTER **vFINAL-COOKBOOK**

#### (10) `AaeosCycleOutcomeRecorder.php`
If it treats all non-auto as halt: ensure `repair_required` is recorded as technical failure, not sovereign success.

### P0.3 GREEN
```bash
/opt/homebrew/bin/php artisan test \
  tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php \
  tests/Unit/Ai/Aaeos/Control/AaeosAdmissionTaxonomyTest.php \
  tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php \
  tests/Unit/Ai/Aaeos/Control/AaeosHumanLoopFieldRetirementTest.php \
  tests/Unit/Ai/Aaeos/Control/AaeosRunCycleParityTest.php \
  tests/Unit/Ai/Aaeos/Control/AaeosIrreversibilitySuspicionCharacterizationTest.php \
  tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php \
  tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php \
  tests/Feature/Ai/Aaeos/AtlasAaeosRunCommandTest.php \
  tests/Feature/Ai/Aaeos/AaeosGodSotaCertificationTest.php \
  --no-coverage
```

### P0 exit checklist (all true)

- [ ] No class `AaeosRunApplication` created  
- [ ] Run+Cycle call `AaeosCycleRuntime` only  
- [ ] dry → `runtime_write_performed===false`  
- [ ] dry → no OutcomeRecorder ledger write  
- [ ] `dispatch_failed` / `repair_required` → non-zero both ports  
- [ ] rwp derived not hardcoded  
- [ ] invalid_mode → `repair_required`  
- [ ] zero authoritative human_in_loop readers in certify/runtime assembly  
- [ ] no static 9.2 inject; no vanity GOD_SOTA  
- [ ] regex suspicion only  
- [ ] OperateScorecardProjector deleted or proven still-needed with consumers  
- [ ] PHASE-P0.json + LEDGER + SCOREBOARD updated  

### P0 commit
```
feat(core): AAEOS-MT P0 honesty port admission and measured projection
```
Prefer one commit; optional second if measured-reader huge.

### P0 STOP
Write `PHASE-P0.json`. Set LEDGER P0=GREEN. Do **not** start P1a.

---

# SLICE P1a — native dispatch (refusal: no provider/effect)

**Gate:** `EXECUTE P1a` (requires PHASE-P0 GREEN)  
**Objective:** fix R33–R35; deepen dispatchers; extract RuntimeDaemon; delete 4 Adapters after parity.  
**Still forbidden:** provider spawn, sandbox, mutation, Decision ACT.

## P1a closed production paths

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

## P1a closed test paths

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

## P1a ordered steps

### P1a.1 RED for R33/R34/R35
Write tests that fail on current `AutonomosLiveDispatcher`:
- `brainNextArgs` must NOT pass `'--scope' => …`
- must pass positional scope (or Artisan signature-compatible positional) + `'--json' => true`
- default scope when empty: `'autonomous'`
- exit_code===0 AND json status in {disabled,dry,error} ⇒ not mutated success (R34)
- seed must not invent `'--max'` (R35)

### P1a.2 Fix brainNextArgs (literal)

**File:** `AutonomosLiveDispatcher.php` method `brainNextArgs`

```php
// REQUIRED final form:
private function brainNextArgs(array $options): array
{
    $scope = trim((string) ($options['scope'] ?? '')) ?: 'autonomous';

    return [
        'scope' => $scope,   // positional argument name used by atlas:brain:next
        '--json' => true,
    ];
}
// FORBIDDEN: '--scope' => $scope
```

Verify against real command signature:
```bash
/opt/homebrew/bin/php artisan atlas:brain:next --help
# confirm positional scope name; if different, amend MASTER with exact name before inventing
```

### P1a.3 Fix seed (R35)
Remove `'--max' => $maxSeeds` invention. Use only real seed flags (`--json` etc.). Prefer in-process replenisher when wired; otherwise skip seed with honest effect kind `brain_seed_skipped` + reason.

### P1a.4 R34 payload honesty
When parsing brain/seed/task Artisan output JSON:
- if `status` in disabled/dry/error → effect is not `mutated` / not success live claim even if exit 0

### P1a.5 R98 extract RuntimeDaemon
- NEW file: `app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemon.php`
- Move **exact** private productive composition from `AtlasSelfConstructionRuntimeDaemonCommand` into this service
- Command + Autonomos dispatcher both call the same service
- **No** second runtime; **no** Artisan brain→seed→task soup inside AAEOS after extract
- Return native journey/cycle/task refs

### P1a.6 Mode wiring
- **Dev:** native DevIntent/ConfirmedDevRun → SeniorLoop→KernelRunExecutor→AtlasDevExecutionService; no Kernel inject from AAEOS  
- **Forge:** ForgeCommissioning → ForgeObraRuntime; live command stays characterization until P1b authority green  
- **Autônomos:** RuntimeDaemon seam; `task next` = claimed not worker_executed  

### P1a.7 Delete 4 Adapters
Only after compile + runtime parity tests green:
- delete Adapter classes
- CycleRuntime must not consume adapters
- remove bindings

### P1a.8 Synthetic packs
Delete recommended_flow / next_commands / provider_opt_in_noted synthetic worklists after native parity.

### P1a exit
- R33/R34/R35 green  
- adapters gone  
- RuntimeDaemon exists and shared  
- provider/mutation still disabled  
- PHASE-P1A.json  

### P1a commit
```
refactor(core): AAEOS-MT P1a native dispatch and brain transport
```
### P1a STOP

---

# SLICES P1b — effect authority (activation after P2a+P2b CUTOVER)

**Critical (R101):** Before P2a+P2b CUTOVER green, every P1b path is **characterization/refusal-only** — zero provider/tool/sandbox/mutation.

## P1b.1 — pre-effect replay
**Gate:** `EXECUTE P1b.1` after P2b-CUTOVER GREEN  
**Receipt:** `PHASE-P1B1.json`

### Production paths
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

### Steps
1. RED: caller-authored ExecutionOrder with fake decision id must refuse provider/sandbox/mutation  
2. Factory must not synthesize `*-decision-*` fallbacks  
3. Reload DecisionReceipt from trusted store under guard before any boundary  
4. CodeGraph: caller options may only narrow, never append sovereign identity  
5. GREEN goldens: tampered/expired/revoked/stale lease  

### Exit
Caller-authored order cannot cause context/provider/sandbox/tool/mutation until authoritative decision reloaded and bound.

### Commit
```
feat(core): AAEOS-MT P1b.1 pre-effect authority replay
```

## P1b.2 — native ACT/settlement
**Gate:** `EXECUTE P1b.2` after P1b.1 + P2c where required by DAG  
**Receipt:** `PHASE-P1B2.json`

### Production paths
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

### Steps (order)
1. RED present-but-false `read_only` with real mutation → must observe write (R70)  
2. Bind LAND to `AuthorizedMergeAction::nonce`; SETTLE to canary idempotency hash  
3. Replay auth under lock immediately pre-commit  
4. Provider spawn set-equality: consult→spawn→result→EngineeringOutcome  
5. Tool attachPath redaction honesty  
6. Executable materialization attestation pre-exec  
7. Migrate skip_counter into CoverageLedger then delete second writer  
8. External generic AAEOS effects remain blocked  

### Exit
Observed write-set wins; observer-minted settlement; mismatch → rollback/settlement path; zero external generic effects.

### Commit
```
feat(core): AAEOS-MT P1b.2 native act settlement and provider coverage
```

## P1b.3 — AAEOS projection only
**Gate:** `EXECUTE P1b.3`  
**Receipt:** `PHASE-P1B3.json`  
**Paths:** `AaeosCycleRuntime.php`, `AaeosAdmissionPolicy.php`, `AaeosScorecardProjector.php`  
**Exit:** AAEOS projects authorization/observation refs only; cannot self-mint observed authority.  
**Commit:** `feat(core): AAEOS-MT P1b.3 aaeos projection of native refs`

### P1b tests (all slices; amend MASTER if new path)
```
tests/Unit/Ai/EngineeringKernel/TypedEngineeringContractTest.php
tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php
tests/Feature/Ai/EngineeringKernel/CanonicalCommitActuationTest.php
tests/Feature/Ai/AtlasTaskScopedCommitterTest.php
tests/Feature/Ai/Aaeos/AaeosEffectAuthorityProtocolTest.php
tests/Feature/Ai/Aaeos/AaeosAuthorityReplayToctouTest.php
tests/Feature/Ai/Aaeos/AaeosAuthorityLaunderingTest.php
tests/Feature/Ai/Aaeos/AaeosProviderToolBoundaryInjectionTest.php
tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerCommandPlanRunnerTest.php
tests/Unit/Ai/Brain2/AtlasNativeWorkerCommandPlanRunnerHardeningTest.php
tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/AgentExecutionProviderPortServiceTest.php
tests/Unit/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionHermeticSandboxApplyServiceTest.php
tests/Unit/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionNativeActionExecutorTest.php
tests/Unit/Ai/Governance/ProviderGovernanceCoverageLedgerTest.php
tests/Unit/Ai/Governance/ProviderGovernanceConsultTest.php
tests/Unit/Ai/Cognition/AcosProgram/Multx05GovernanceConsultSkipCounterTest.php
tests/Unit/CodeGraph/CodeGraphWorkspaceAccessPolicyTest.php
tests/Feature/Tools/AtlasToolEvidenceStoreArtifactRedactionTest.php
tests/Feature/Ai/Runtime/AiToolProcessRunnerAuthorityBoundaryTest.php
tests/Feature/Ai/Aaeos/AaeosProviderSpawnSetEqualityTest.php
tests/Feature/Ai/Aaeos/AaeosFilesystemOperationIdentityTest.php
tests/Feature/Ai/Aaeos/AaeosExecutableMaterializationAttestationTest.php
```

---

# SLICES P2 — authority / evidence / durability

## P2a.1 Ledger v2 + PG roles
**Gate:** `EXECUTE P2a.1` after P1a  
**Receipt:** `PHASE-P2A1.json`

### Production paths
```
app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php
app/Models/AtlasLedgerEvent.php
app/Services/Ai/Kernel/Evidence/LedgerEventType.php
config/database.php
database/migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
```

### Steps
1. RED: tenant isolation A/B cannot cross-link  
2. Migration: unique `(tenant_id, chain_key_hash, chain_position)`; append-only role guard  
3. Emit `atlas.ledger_event.v2` full-envelope hash; v1 dual-read `legacy_unverified` never rehashed  
4. Non-circular receipt_core_hash (exclude evidence_event_id/hash from core)  
5. Real PostgreSQL contract (not SQLite skip):
```bash
export ATLAS_ALLOW_LIVE_DB_TESTS=1
export ATLAS_TEST_PG_HOST=… ATLAS_TEST_PG_PORT=… ATLAS_TEST_PG_DATABASE=atlas_test_…
export ATLAS_TEST_PG_USERNAME=… ATLAS_TEST_PG_PASSWORD=…
# missing vars = phase FAIL (not skip)
```
6. Assert session_user/current_user/grants; forbid SET ROLE/superuser/BYPASSRLS  
7. dump→restore→replay drill  

### Tests
```
tests/Unit/Ai/Kernel/EvidenceLedgerTest.php
tests/Feature/Ai/Aaeos/AaeosPostgresDurabilityContractTest.php
tests/Feature/Ai/Aaeos/AaeosLedgerTenantIsolationTest.php
tests/Feature/Ai/Aaeos/AaeosLedgerTruncationCutoffTest.php
tests/Feature/Ai/Aaeos/AaeosPostgresRestoreIdentityTest.php
tests/Feature/Ai/Aaeos/AaeosCanonicalP2SchemaUpgradeTest.php
tests/Feature/Ai/Aaeos/AaeosJourneyManifestIntegrityTest.php
tests/Feature/Ai/Aaeos/AaeosFreshProcessJourneyReplayTest.php
```

### Commit
```
feat(core): AAEOS-MT P2a.1 ledger v2 tenant chain and pg roles
```

## P2a.2 EngineeringOutcome v3
**Gate:** `EXECUTE P2a.2`  
**Receipt:** `PHASE-P2A2.json`  
**Paths:** `EngineeringOutcome.php` + consumers in P2a list + `AaeosEngineeringOutcomeSchemaRolloutTest`  
**Rollout:** expand → dual-read/shadow → (later canary with P2b discipline)  
**Adverse:** require `failure_reason_code` + precise `failure_reason`  
**v2:** historical read-only, never inferred/backfilled  
**Commit:** `feat(core): AAEOS-MT P2a.2 engineering outcome v3 expand dual-read`

## P2b Decision v3 (5 serial checkpoints)

Each checkpoint = own EXECUTE + own PHASE receipt + own commit. Same production path set:

```
config/atlas.php
config/atlas_code_signing.php
app/Services/Ai/AtlasDecideService.php
app/Services/Ai/AtlasDecide/AiDecisionReceiptRefreshService.php
app/Services/Ai/ValueObjects/OperationalDecision.php
app/Services/Ai/Kernel/Decision/DecisionReceipt.php
app/Services/Ai/Kernel/Decision/DecisionReceiptHash.php
app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php
app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php
app/Services/Ai/Kernel/Evidence/LedgerProjectionRegistry.php
app/Console/Commands/AtlasCliCockpitCommand.php
app/Services/Ai/Memory/AtlasMemoryLearningPromotionService.php
app/Services/Ai/Compounding/AtlasHeldEvidenceMinerService.php
app/Services/Ai/AtlasOpenBrainContextPackService.php
app/Services/Ai/Context/AiConversationContextBuilder.php
app/Services/Ai/Context/AiContextPackBuilder.php
app/Services/Ai/Kernel/Decision/Reversibility/ReceiptReversibilityConsentGate.php
app/Services/AtlasCode/HumanDecisionReceiptSigner.php
app/Services/Ai/EngineeringKernel/Spec/SpecSourceIndependence.php
app/Services/Ai/EngineeringKernel/Spec/SelfComposedWitnessResolver.php
app/Services/Ai/EngineeringKernel/Spec/AdvisorWitnessResolver.php
app/Services/Ai/EngineeringKernel/Spec/SovereignSpecFloor.php
app/Services/Ai/EngineeringKernel/VerificationCourtAcceptanceGate.php
app/Services/Ai/EngineeringKernel/RoleEvidenceReceipt.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/ProviderLock.php
app/Services/Ai/AiWorker.php
app/Http/Resources/AiDecisionResource.php
app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php
app/Services/Ai/ExecutionAuthority/AwisExecutionGatePort.php
app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceExecutionGateService.php
app/Services/Ai/EngineeringKernel/Coverage/EngineeringExecutionSurfaceRegistry.php
app/Services/Ai/SelfConstruction/AtlasTaskServingService.php
```

| Gate | Receipt | What to do |
|---|---|---|
| `EXECUTE P2b-EXPAND` | PHASE-P2B-EXPAND.json | Add receipt_v3 schema fields; dual-read v2; writers still legacy |
| `EXECUTE P2b-SHADOW` | PHASE-P2B-SHADOW.json | Shadow-verify v3 vs v2; any contradiction vetoes |
| `EXECUTE P2b-CANARY` | PHASE-P2B-CANARY.json | Canary writer for new issuances; mutative consumers refuse unknown |
| `EXECUTE P2b-CUTOVER` | PHASE-P2B-CUTOVER.json | Mutative cutover to v3; mixed-worker block-before-effect; **unlocks P1b.1** |
| `EXECUTE P2b-CONTRACT` | PHASE-P2B-CONTRACT.json | After old-worker drain; contract; **required before P4** |

### AWIS R102 (during CUTOVER)
- modes `dev|forge|autonomos` explicit mutative  
- unknown fail-closed  
- TaskServing cannot present Autônomos as Dev  

### P2b tests
```
tests/Unit/Ai/Kernel/DecisionReceiptIssuerTest.php
tests/Unit/Ai/Kernel/DecisionReceiptRuntimeGuardTest.php
tests/Unit/Ai/Kernel/Decision/Reversibility/ReceiptReversibilityConsentGateTest.php
tests/Unit/AtlasCode/HumanDecisionReceiptSignerTest.php
tests/Unit/Ai/EngineeringKernel/Spec/SpecAdversaryContractTest.php
tests/Unit/Ai/EngineeringKernel/Spec/SovereignSpecFloorTest.php
tests/Feature/Ai/Aaeos/AaeosStandingMandateAuthorityTest.php
tests/Feature/Architecture/DecisionReceiptDeterminismTest.php
tests/Feature/Ai/EngineeringKernel/PreLandSeamTest.php
tests/Feature/Ai/Aaeos/AaeosSeparationOfDutiesTest.php
tests/Feature/Ai/Aaeos/AaeosReceiptConsumerCensusTest.php
tests/Feature/Ai/Aaeos/AaeosMixedVersionWorkerCompatibilityTest.php
tests/Feature/Ai/AtlasDecide/AtlasDecideGatewayDecisionReceiptTest.php
tests/Unit/Ai/Brain2/AiDecisionReceiptRefreshServiceHardeningTest.php
tests/Unit/Ai/Programming/AtlasForgeRuntimeDispatchServiceTest.php
tests/Feature/Ai/SelfConstruction/AutonomosAwisGateTest.php
tests/Feature/Ai/EngineeringKernel/MutativeSurfaceAwisInvariantTest.php
tests/Feature/Ai/Aaeos/AaeosAwisModeIdentityParityTest.php
tests/Feature/Ai/Aaeos/AaeosDecisionReceiptSchemaRolloutTest.php
tests/Feature/Ai/Aaeos/AaeosAuthorityAncestorChainTest.php
tests/Feature/Ai/Aaeos/AaeosInstructionProvenanceTest.php
```

Rollback = writer selection only; never rewrite signed bytes.

## P2c — unattended durability
**Gate:** `EXECUTE P2c` after P1b.1  
**Receipt:** `PHASE-P2C.json`

### Production paths
```
app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketBuilder.php
app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneClaimLeaseRepository.php
app/Services/Ai/SelfConstruction/AtlasTaskServingService.php
app/Services/Ai/SelfConstruction/AtlasTaskServingStack.php
app/Services/Ai/SelfConstruction/AtlasTaskServingSwitch.php
app/Services/Ai/SelfConstruction/TaskServing/AtlasTaskBrainReplenisher.php
app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemon.php
app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSchedulerManifest.php
routes/console.php
app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php
app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerOutcomeMapper.php
app/Services/Ai/Programming/AtlasDev/Execution/DevPlanRunFacade.php
app/Services/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionService.php
app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopExecutor.php
app/Services/Ai/Programming/AtlasDev/Repair/RepairOrchestrator.php
app/Services/Ai/Programming/AtlasDev/Repair/DevRepairLoopService.php
app/Services/Ai/Programming/AtlasDev/Persistence/ReceiptStorage.php
app/Services/Ai/Programming/DurableExecution/DurableExecutionPreflight.php
app/Http/Controllers/AtlasDev/Support/KernelRunExecutor.php
app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php
app/Services/Ai/Programming/Forge/Execution/ForgeObraSupervisor.php
app/Services/Ai/Programming/Forge/Execution/ForgeTickBudget.php
app/Services/Ai/Programming/Forge/ForgeMultiAgentSchedulerService.php
app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php
app/Services/Ai/Programming/Forge/ForgeScopeReservationService.php
app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php
database/migrations/2026_07_23_231000_harden_ai_forge_execution_atomicity.php
```

### Steps (must)
1. RED stale-resume/revocation first  
2. Bind authority ref/hash/revision packet→lease without remint  
3. Revalidate at origination, claim, renewal, pre-effect  
4. Dev: RepairOrchestrator owns 0..N retries under one root/budget  
5. Forge: supervisor tick→Court→repair→milestone→completeObra; unique cycle position+CAS  
6. Autônomos: land + EngineeringOutcome before TaskServing success report; crash reconciles one report  
7. Scheduler cold-start without operator `--facts`  
8. Client detach ≠ cancel  

### Tests
```
tests/Unit/Ai/SelfConstruction/AtlasTaskServingServiceTest.php
tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycleTest.php
tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerOutcomeMapperTest.php
tests/Unit/Ai/Programming/AtlasDev/Repair/RepairOrchestratorTest.php
tests/Feature/Ai/Programming/AtlasDev/Repair/DevRepairLoopServiceTest.php
tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php
tests/Feature/Ai/Programming/AtlasDev/SeniorEngineerLoopCrashResumeTest.php
tests/Feature/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleServiceTest.php
tests/Feature/Ai/Programming/Forge/ForgeLongHorizonStateServiceTest.php
tests/Feature/Ai/Aaeos/AaeosAuthorityLineageResumeTest.php
tests/Feature/Ai/Aaeos/AaeosNativeRepairContinuationTest.php
tests/Feature/Ai/AtlasTaskServingResolveLoopE2ETest.php
tests/Feature/Ai/AtlasTaskServingLeaseOwnershipTest.php
tests/Unit/Ai/SelfConstruction/AgentControlPlaneClaimLeaseRegistryRebuildTest.php
tests/Feature/Ai/Aaeos/AaeosTaskLeaseCrashRecoveryTest.php
tests/Feature/Ai/Aaeos/AaeosEffectProtocolCrashCutpointModelTest.php
tests/Feature/Ai/Aaeos/AaeosAutonomosLandBeforeReportCrashTest.php
tests/Feature/Ai/Programming/Forge/ForgeObraCertificationBindingTest.php
tests/Feature/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSchedulerIntegrationTest.php
tests/Feature/Ai/Aaeos/AaeosPublicClientDetachContinuationTest.php
tests/Feature/Ai/Aaeos/AaeosSignedFanoutBudgetInvariantTest.php
tests/Feature/Ai/Aaeos/AaeosRootResourceBudgetConcurrencyTest.php
tests/Feature/Ai/Aaeos/AaeosMultiEngineDirtyMainIsolationTest.php
tests/Feature/Ai/Aaeos/ForgeWorkPacketExecutionCycleMigrationTest.php
tests/Feature/Ai/Aaeos/AaeosLedgerJourneyMigrationTest.php
```

### Commit
```
feat(core): AAEOS-MT P2c native lineage crash durability
```

## P2d Spine
**Gate:** `EXECUTE P2d`  
**Receipt:** `PHASE-P2D.json`  
**Paths:**
```
app/Services/Ai/Aaeos/Spine/AaeosEngineeringSpine.php
app/Services/Ai/Aaeos/Spine/AaeosSpineGate.php
app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
```
**Rule (R66):** applicable N11 only with settlement-emitted effect receipt via ledger readback; empty/declared refs fail.  
**Tests:** `AaeosControlPlaneTest`, `AaeosSpineSettlementEvidenceTest`  
**Commit:** `feat(core): AAEOS-MT P2d spine settlement evidence refs`

## P2e H1–H7 async
**Gate:** `EXECUTE P2e` after P2c  
**Receipt:** `PHASE-P2E.json`  
**Paths:**
```
app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
app/Services/Ai/Kernel/Evidence/LedgerEventType.php
app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php
app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php
app/Services/Ai/SelfConstruction/AtlasTaskServingService.php
app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php
```
**Proof (R100):** two-work-item A reserved / B settles / A resumes once.  
**Tests:** `AaeosSovereignContinuationTest`, `AaeosCockpitAttentionPartitionTest`, miner/context-pack extends  
**Commit:** `feat(core): AAEOS-MT P2e async H1-H7 continuation`

## P2f operator census / strip technical CLI
**Gate:** `EXECUTE P2f`  
**Receipt:** `PHASE-P2F.json`

### Production paths
```
bin/atlas
app/Services/Engineering/EngineeringRunOperatorActionService.php
app/Services/Ai/Product/ProductIntentClarificationContract.php
app/Services/Ai/Product/ProductIntentCourt.php
app/Services/Ai/EngineeringKernel/Spec/AtlasSpecGateAdapter.php
app/Services/Ai/Programming/AtlasDev/PlanVisible/AtlasDevPlanApprovalGate.php
app/Services/Ai/Programming/AtlasDev/PlanVisible/AtlasDevProviderExecutionBlock.php
app/Console/Commands/AtlasCliDevPlanCommand.php
app/Console/Commands/AtlasCliDevCommand.php
app/Services/Ai/Cli/AtlasCliDevEfficientHandler.php
app/Console/Commands/AtlasCliContinueCommand.php
app/Services/Ai/Cli/AtlasCliSessionService.php
app/Console/Commands/AtlasAaeosRunCommand.php
app/Console/Commands/AtlasCliHelpCommand.php
app/Console/Commands/AtlasApiDescribeCommand.php
app/Services/Ai/Programming/Forge/ForgeContinuationPackBuilder.php
app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleCanon.php
app/Services/Ai/Programming/Forge/Intelligence/ForgeFailureIntelligenceService.php
app/Services/Ai/Programming/Forge/Intelligence/ForgeObraScopeGuardService.php
app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketBuilder.php
app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
```

### Steps
1. Complete ingress/egress census with producer seals  
2. Clarification closed enum (technical questions invalid)  
3. Dev authority once; PlanVisible optional audit  
4. Forge: no post-seal technical human  
5. Autônomos: reject task-causal DecisionIssued for zero-touch credit  
6. Strip productive flags from `atlas:aaeos:run` (`--live`, `--execute-provider`, `--run-worker-once`, `--max-seeds`, `--scope`); keep `--dry-run`; removed flags error with migration guidance  
7. OneShot derived fold; invariant to internal attempt count  

### Commit
```
feat(core): AAEOS-MT P2f operator census and intent-first daily port
```

---

# SLICES P3 — deletion / alignment

## P3a census
**Gate:** `EXECUTE P3a`  
**Receipt:** `PHASE-P3A.json`  
Complete consumer census for PipelineRunExecutor family + OrgState + OutcomeRecorder + aliases. **No mass delete.** Amend MASTER with every consumer path found.

## P3b deletion/alignment
**Gate:** `EXECUTE P3b` only after P3a census paths listed in MASTER amendment  
**Receipt:** `PHASE-P3B.json`

### Production paths (authorized)
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
app/Console/Commands/AtlasTaskLandingReviewPublishCommand.php
app/Services/Ai/Mobile/InboxActionRegistry.php
app/Services/Ai/SelfConstruction/AtlasTaskLandingReviewPublisher.php
app/Console/Commands/AtlasTaskReviewDecideCommand.php
app/Console/Commands/AtlasReviewDeepCommand.php
app/Services/Engineering/EngineeringReviewService.php
app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php
app/Http/Controllers/AtlasDev/Support/PipelineRun/BestOfNSection.php
app/Http/Controllers/AtlasDev/Support/PipelineRun/DeterministicPatchSection.php
app/Http/Controllers/AtlasDev/Support/PipelineRun/GovernanceSection.php
app/Http/Controllers/AtlasDev/Support/PipelineRun/ProviderExecutionSection.php
app/Http/Controllers/AtlasDev/Support/PipelineRun/ProviderResultSupport.php
app/Http/Controllers/AtlasDev/Support/PipelineRun/RepairProjectionSection.php
app/Http/Controllers/AtlasDev/Support/PipelineRun/ResolverSupport.php
app/Http/Controllers/AtlasDev/Support/PipelineRun/WorkspaceGitSupport.php
app/Services/Ai/Aaeos/README.md
app/Console/Commands/AtlasAaeosRouterCommand.php
app/Console/Commands/AtlasCliHelpCommand.php
app/Console/Commands/AtlasApiDescribeCommand.php
app/Services/Ai/Programming/AtlasWeeklyEngineeringReportService.php
app/Services/Ai/Programming/AtlasFableFinalReportService.php
app/Services/Ai/Programming/AtlasFableFinalCaptureService.php
app/Services/Ai/CODEMAP.md
docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
docs/engineering-knowledge-base/atlas-terminal-first-focus.md
docs/engineering-knowledge-base/atlas-cli-daily-map.md
docs/loop-soak-run-profile.md
docs/loop-soak-runbook.md
docs/loop-task-class-discovery.md
```

### Required deletions/behaviors
- Delete TriHygiene projector+command (R72)  
- OrgState/OutcomeRecorder only after zero-reader census  
- PipelineRunExecutor family **retain** until full R103 census amendment then port  
- Aliases: classmap + cold-process negative resolution before delete (R93)  
- 17-phase demote from daily operate  
- Cockpit: no technical next_commands; human accept/reject cannot mint eng truth  
- ITT economy left join all roots (R69)  
- Quarantine import count remains 0  

### Tests
```
tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php
tests/Feature/Ai/Aaeos/AaeosAeosPartitionGuardTest.php
tests/Feature/Ai/Aaeos/AaeosEconomyIntentToTreatProjectionTest.php
tests/Feature/Ai/Aaeos/AaeosCanonicalDocsAndAliasTest.php
tests/Feature/Ai/AtlasCliCockpitCommandTest.php
tests/Feature/Ai/TaskLandingReviewCockpitTest.php
tests/Feature/Ai/AtlasCliInboxOperatorActionsTest.php
tests/Feature/Ai/Aaeos/AaeosOneShotCockpitNonBlockingTest.php
tests/Feature/Ai/Aaeos/AaeosVerificationCockpitProjectionTest.php
tests/Feature/Ai/Aaeos/AaeosLegacyDispatchPackConsumerCensusTest.php
tests/Feature/Ai/Aaeos/AaeosSelfDeclaredOrgStateAbsenceTest.php
tests/Feature/Ai/Aaeos/AaeosDeadLoopSurfaceAbsenceTest.php
tests/Feature/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHttpSmokeTest.php
tests/Feature/Ai/Programming/AtlasDev/PipelineRunExecutorAwisGateTest.php
tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHermesProviderTest.php
tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php
```

### Commit
```
refactor(core): AAEOS-MT P3 alignment deletion and verification surface
```

---

# SLICES P4 — REAL_OPERATION gauntlet

**Preconditions:** all P0–P3 receipts GREEN; automated R33/R34/R51–R103 source portions green; ops preflight.

## Shared P4 rules
- Outside PHPUnit for qualifying journeys  
- Durable PostgreSQL via `ATLAS_P4_PG_PRODUCER_URL` + `ATLAS_P4_PG_VERIFIER_URL` (prefix `atlas_p4_`)  
- Distinct producer vs SELECT-only verifier roles  
- Secrets never in receipts  
- exit 0 alone never qualifies  
- PHPUnit/fixtures/fake providers may test readers only  

## P4-DEV
**Gate:** `EXECUTE P4-DEV`  
**Receipt:** `PHASE-P4-DEV.json`  
**Producer:** `bin/atlas dev <intent>` → SeniorLoop journey → EngineeringOutcome  
**Must:** journey_terminal_status=real_operation_completed; authority lineage; repairs allowed under same root; COVERED provider spawn or explicit fail; fresh-process certifier  

## P4-FORGE
**Gate:** `EXECUTE P4-FORGE`  
**Receipt:** `PHASE-P4-FORGE.json`  
**Producer:** `bin/atlas forge <intent>` → ForgeCommissioning → completeObra  
**Must:** terminal winner; certification Ledger-bound; no post-seal tech human  

## P4-AUTONOMOS
**Gate:** `EXECUTE P4-AUTONOMOS`  
**Receipt:** `PHASE-P4-AUTONOMOS.json`  
**Producer:** RuntimeDaemon direct first (`aaeos_initiated=false`)  
**Must:** brain→seed→claim→worker→land OR honest blocked_ops PARTIAL; zero task-causal operator actions; pre-signed mandate  

## P4-FREEZE
**Gate:** `EXECUTE P4-FREEZE`  
**Receipt:** `PHASE-P4-FREEZE.json`  
Bind tenant/chain cutoff, code SHA, three mode manifests, verifier result. Later drift makes current DONE stale without rewriting history.

### P4 tests / paths
```
bin/atlas
app/Console/Commands/AtlasCliDevCommand.php
app/Services/Ai/Cli/AtlasCliDevEfficientHandler.php
app/Console/Commands/AtlasAaeosRunCommand.php
app/Console/Commands/AtlasDevSeniorLoopRunCommand.php
app/Console/Commands/AtlasForgeLiveExecuteCommand.php
app/Console/Commands/AtlasSelfConstructionRuntimeDaemonCommand.php
app/Console/Commands/AtlasAaeosCertifyCommand.php
app/Console/Commands/AtlasCliCockpitCommand.php
tests/Feature/Ai/Aaeos/AaeosDevRealJourneyTest.php
tests/Feature/Ai/Aaeos/AaeosForgeRealObraJourneyTest.php
tests/Feature/Ai/Aaeos/AaeosAutonomosRealJourneyTest.php
tests/Feature/Ai/Aaeos/AaeosOneShotOperatorEvidenceTest.php
tests/Feature/Ai/Aaeos/AaeosFreshProcessJourneyReplayTest.php
tests/Feature/Ai/Aaeos/AaeosPublicOperatorSurfaceRealJourneyTest.php
tests/Feature/Ai/Aaeos/AaeosP4CertifierReadOnlyBoundaryTest.php
tests/Feature/Ai/Aaeos/AaeosP4RuntimeProfileRejectionTest.php
tests/Feature/Ai/Aaeos/AaeosP4NonTerminalExitZeroRejectionTest.php
tests/Feature/Ai/Aaeos/AaeosP4ProviderToolProvenanceTest.php
tests/Feature/Ai/Aaeos/AaeosP4IndependentVerifierPrivilegeTest.php
tests/Feature/Ai/Aaeos/AaeosCertificationInvalidatorMatrixTest.php
```

### Certifier command shape
```bash
/opt/homebrew/bin/php artisan atlas:aaeos:certify \
  --profile=p4 --journey=<ref> --cutoff=<ledger-head> \
  --evidence-dir=docs/evidence/2026-07-23-aaeos-elite-deepening --json
# read-only; never append ledger; never spawn provider
```

---

## DONE predicate

Full DONE only if:
1. All phase receipts GREEN for P0–P4 (Autônomos PARTIAL allowed only if program marked incomplete — not full DONE)  
2. R33,R34,R35,R38,R40,R43,R46,R51–R103 closed (non-HORIZON)  
3. Three-mode REAL_OPERATION  
4. No second ledger/organ; Quarantine/ACDE clean; scoped main commits  
5. capability_proof derived not caller-set  

**Not DONE:** SUSTAINED, comparative 50×, company 100% Atlas.

---

## Verification discipline (every slice)

1. Record branch/HEAD/status  
2. Attribute baseline failures — never stash unrelated dirt  
3. RED fingerprint before change; GREEN after  
4. Scoped pathspec stage only  
5. Mutation/invalidator on critical predicates when slice touches authority/effect/terminal  

Architecture regressions always:
- no Quarantine import  
- no atlas:loop:* operate  
- no AAEOS→Kernel direct  
- no CLI-to-CLI after P1a  
- no second evidence store  
- no ModeExecutor/classifier authority  
- no authoritative human_in_engineering_loop  
- no GOD_SOTA from internal score  

---

## Tombstones

| Path | Status |
|---|---|
| `…-MASTER-FINAL.md` | alias → this file |
| `…-MASTER-CLAUDE.md` | ARCHIVED |
| `archive/…/MASTER-v16-pre-final.md` | SUPERSEDED historical |
| `archive/…/MASTER-CLAUDE-C6.md` | historical |

---

## Codex / any-IA handoff

1. Operator says one EXECUTE phrase.  
2. Open **this file**, jump to that SLICE.  
3. Follow ordered steps only.  
4. PHASE + LEDGER + SCOREBOARD + scoped commit.  
5. STOP.

**Prompt file:** `docs/prompts/atlas-aaeos-mt-EXECUTE-P0-CODEX.md` (extend phrase for later slices).

**This is the executable god-SOTA cookbook of record.**  
**System god-SOTA starts when PHASE receipts are green on disk.**
