# AAEOS GOD/SOTA Complete — Implementation Plan

> **For agentic workers:** Execute phase-by-phase. Steps use checkbox (`- [ ]`) syntax.  
> Prefer scoped commits on local `main`: `refactor(core)|test(core)|docs(core): AAEOS-GOD …`  
> Do **not** invent product domains outside engineering OS. Do **not** reanimate Quarantine.

**Goal:** Implement **Atlas Agentic Engineering OS (AAEOS)** at full **GOD/SOTA** level: a thin organizational control plane that runs Dev · Forge · Autônomos (same bar L0–L5) over the shared Nucleus spine (especially N9 Delivery + N10 Muscle + N11 Evidence), with human **out of the engineering loop** by default, antifragile proof+learning, and zero operate-path cemetery.

**Definition of program DONE (all must be true simultaneously):**

1. **Control plane live end-to-end:** intent → difficulty → mode → admission → dispatch → evidence record → certify path.  
2. **Same-bar L0–L5 enforced** in code + docs for Dev · Forge · Autônomos (no “Dev=patch”, no “Autônomos=low tier”).  
3. **Human-out-of-loop default:** Autônomos is default 24/7 path; halt-sovereign only for irreversible / legal / $ / wipe / ambiguous business objective.  
4. **Shared spine enforced:** Dev and Forge cannot declare engineering done without N9 delivery contract + N11 ledger API (no parallel ledger).  
5. **Autônomos plugged:** `runAutonomosCycle` (or successor) can drive/observe brain→seed→task operate path with receipts.  
6. **Quarantine gone from operate path:** zero production `use` of `Aaeos\Quarantine`; ideally physical `archive/`; catalog remains as data.  
7. **Antifragile loop:** failures → ledger → learning proposals (N12) with measured scorecard (reincident failure rate trackable).  
8. **Surfaces:** CLI (and optional HTTP) for cycle + org state + scorecard.  
9. **Density & CODEMAP:** AAEOS Control/Spine/Support façades ≤800 hot / ≤2000 any; CODEMAP entries for public APIs.  
10. **Gates green:** focused AAEOS + DualCore + Brain connector tests; no known operate-path import of Quarantine.  
11. **Docs aligned:** mother AAEOS + elite executors + implementation-reality + this plan scoreboard all agree.  
12. **Scoreboard AAEOS composite ≥ 9.0/10** on the matrix in §Done Scoreboard (not vanity LOC).

**Architecture:**

```text
AAEOS Control Plane (thin)
  → Mode adapters: Dev | Forge | Autônomos
    → Shared Spine = Nucleus N2–N12 (esp. N9/N10/N11)
      → Coroa C (CLI/HTTP/MCP)
      → Q archive only
```

**Tech stack:** PHP 8.4 · Laravel 13 · PHPUnit · Artisan · Postgres · existing Kernel Evidence + RealExecution + SelfConstruction/Brain.

**Baseline already landed (do not redo; extend):**

| Piece | Path | Status |
|---|---|---|
| Control plane classes | `app/Services/Ai/Aaeos/Control/*` | DONE skeleton |
| Spine seam | `app/Services/Ai/Aaeos/Spine/AaeosEngineeringSpine.php` | DONE seam only |
| Connectors re-home | `Aaeos/Support/AtlasSourceConnectorsAndCaptureService.php` | DONE |
| Quarantine FROZEN + catalog | `Quarantine/README` + `AAEOS-QUARANTINE-CATALOG.*` | DONE freeze |
| Elite executors doc | `atlas-elite-executors-dev-forge-autonomos.md` | DONE |
| DualCore `ROUTE_AUTONOMOS` | `DualCoreRouteDecisionCanon` | DONE canon |
| Unit tests control plane | `tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php` | 7 green |
| Vision HTML | `AAEOS-VISAO-COMPLETA.html` | DONE design |

**Evidence root for this program:**

```text
docs/evidence/2026-07-23-aaeos-god-sota/
  LEDGER.md          # phase cursor + paste commands
  SCOREBOARD.md      # composite 0–10
  PHASE-*-RECEIPT.md # per phase
```

**Related canon (read, do not fork):**

- `docs/evidence/2026-07-22-atlas-server-god-debulk/AAEOS-NUCLEUS-AGENTIC-ERA.md`
- `docs/evidence/2026-07-22-atlas-server-god-debulk/ATLAS-NUCLEUS-GOD-SOTA.md`
- `docs/evidence/2026-07-22-atlas-server-god-debulk/AAEOS-VISAO-COMPLETA.html`
- `docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md`
- `docs/engineering-knowledge-base/atlas-agentic-engineering-os.md`
- `docs/engineering-knowledge-base/atlas-autonomos-live-system.md`

---

## Global constraints

- Local **`main` only**; scoped `git add -- <files>`; never `git add -A` as habit.  
- Keep-list Autônomos: never delete `AtlasLoop*` by prefix (`atlas-autonomos-live-system.md`).  
- ACDE/`atlas:loop:*` remains dead operate path.  
- **Do not reanimate** `Aaeos/Quarantine/*` as runtime.  
- No PHP→Swift rewrite; no new product domain (Finance etc.) inside this program.  
- Density: new PHP ≤2000; hot façade/command ≤800; target 150–800.  
- Policy pure (0 I/O); Runtime sole mutator; Projector 0 write.  
- If SelfConstruction/Readiness is claimed by another engine: **skip** N10 heavy slices; record debt.  
- Anti-vanity: no “pass N” docs-only commits as victory.

---

## Phase map

```text
P0  Evidence harness + scoreboard baseline
P1  Control plane production (CLI + ledger + world admission)
P2  Mode adapters + DualCore/router wiring
P3  Spine enforcement (Dev + Forge strangler → N9/N11)
P4  Autônomos full plug (cycle → brain/task observe + receipts)
P5  Quarantine elimination from operate path (+ archive)
P6  Antifragile learning loop + scorecard metrics
P7  Density, CODEMAP, guards, legacy Aaeos root cleanup
P8  Surfaces polish + docs final alignment
P9  GOD/SOTA certification suite + composite ≥ 9.0
```

---

## Done Scoreboard (falsifiable)

Update `SCOREBOARD.md` after each phase. **Program complete only if every row ≥ target.**

| Dimension | Baseline (2026-07-23) | Target GOD/SOTA | Measure |
|---|---:|---:|---|
| Thesis clarity (docs) | 9 | ≥9 | Owner docs agree |
| Elite same-bar identity | 8.5 | ≥9.5 | Code + tests reject low-tier semantics |
| Control plane completeness | 6 | ≥9.5 | CLI + ledger + adapters |
| Operate-path wiring | 4 | ≥9 | Autonomos cycle drives real path |
| Spine N9/N11 enforced | 3.5 | ≥9.5 | assertShared on intakes + cert |
| Antifragile loop | 3 | ≥9 | failure→learn tracked |
| Quarantine operate-path = 0 | 5 | 10 | `rg` production = 0 |
| Density AAEOS live | 7 | ≥9 | audit ≤2000/≤800 hot |
| Composite | ~6.2 | **≥9.0** | mean of dimensions |

---

### Task 0: Phase 0 — Evidence harness

**Files:**
- Create: `docs/evidence/2026-07-23-aaeos-god-sota/LEDGER.md`
- Create: `docs/evidence/2026-07-23-aaeos-god-sota/SCOREBOARD.md`
- Create: `docs/evidence/2026-07-23-aaeos-god-sota/PHASE-0-RECEIPT.md`

- [ ] **Step 0.1:** Create `LEDGER.md` with schema:

```yaml
program: aaeos-god-sota-complete
phase: P0
focus: harness
last_commit: null
notes: |
  ...
commands: |
  <paste>
```

- [ ] **Step 0.2:** Create `SCOREBOARD.md` copying the matrix above with baseline numbers and date.  
- [ ] **Step 0.3:** Paste baseline proof:

```bash
php artisan test tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php --no-coverage
rg -n "Aaeos\\\\Quarantine" app --glob '*.php' | grep -v '/Quarantine/' || true
```

- [ ] **Step 0.4:** Commit: `docs(core): AAEOS-GOD P0 evidence harness`

**Phase 0 gate:** LEDGER + SCOREBOARD exist; baseline tests still green.

---

### Task 1: Phase 1 — Control plane production

**Goal:** Skeleton becomes operable OS entry.

**Files (expected):**
- Create: `app/Console/Commands/AtlasAaeosCycleCommand.php` (signature `atlas:aaeos:cycle`)
- Modify: `bootstrap/app.php` or command auto-discovery if needed
- Modify: `app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php` (ledger hook, richer receipt)
- Create: `app/Services/Ai/Aaeos/Control/AaeosWorldSnapshot.php` (DTO only)
- Create: `app/Services/Ai/Aaeos/Control/AaeosWorldSnapshotBuilder.php` (I/O allowed here → feeds policy)
- Modify: `AaeosAdmissionPolicy.php` (consume world fields: budget_pressure, incident_open, queue_depth)
- Create: `tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php`
- Modify: unit tests admission with world

- [ ] **Step 1.1:** Implement `atlas:aaeos:cycle {intent?} {--autonomos} {--json} {--dry-run}` calling `AaeosCycleRuntime`.  
- [ ] **Step 1.2:** Feature test: autonomos cycle JSON has `mode=autonomos`, `human_in_engineering_loop=false`, operate_path brain/task.  
- [ ] **Step 1.3:** Feature test: irreversible intent → `halted` / `halt_sovereign`.  
- [ ] **Step 1.4:** `AaeosWorldSnapshotBuilder` builds array from config/optional DB fail-open (empty snapshot ok).  
- [ ] **Step 1.5:** Admission uses world: e.g. `incident_open=true` + L5 → at least `auto_notify` or halt per policy table documented in receipt.  
- [ ] **Step 1.6:** On successful dispatch (non-dry-run), record cycle event via `AtlasEvidenceLedger` **if** available; fail-open with `evidence_status=skipped|recorded` in receipt (never silent lie).  
- [ ] **Step 1.7:** Characterization: receipt always includes `elite_same_bar=true`, `spine.delivery=N9`, `spine.evidence=N11`.  
- [ ] **Step 1.8:** Run tests; commit: `refactor(core): AAEOS-GOD P1 cycle CLI + world admission + ledger hook`

**Phase 1 gate:**

```bash
php artisan atlas:aaeos:cycle "x" --autonomos --json --dry-run
php artisan test tests/Unit/Ai/Aaeos/Control tests/Feature/Ai/Aaeos --no-coverage
```

---

### Task 2: Phase 2 — Mode adapters + router wiring

**Goal:** Dispatch is real structure, not only string lists.

**Files:**
- Create: `app/Services/Ai/Aaeos/Control/Adapters/AaeosExecutorModeAdapter.php` (interface)
- Create: `.../DevModeAdapter.php`
- Create: `.../ForgeModeAdapter.php`
- Create: `.../AutonomosModeAdapter.php`
- Modify: `AaeosCycleRuntime` to use adapters for `dispatch`
- Modify: `DualCoreRouteDecisionService` and/or router runtime integration point (find call sites of `DualCoreRouteDecisionCanon::ROUTES`)
- Create/Modify tests DualCore for `ROUTE_AUTONOMOS`
- Create unit tests adapters return operate contracts

- [ ] **Step 2.1:** Interface:

```php
interface AaeosExecutorModeAdapter {
    /** @param array<string,mixed> $cyclePlan
     *  @return array{status:string,operate_path:list<string>,spine:array<string,string>,...} */
    public function accept(array $cyclePlan): array;
}
```

- [ ] **Step 2.2:** Autonomos adapter returns brain/seed/task path + `commit_policy=scoped_main`.  
- [ ] **Step 2.3:** Dev adapter returns session surfaces + spine N9/N11.  
- [ ] **Step 2.4:** Forge adapter returns obra/forge surfaces + spine.  
- [ ] **Step 2.5:** Wire DualCore: accept `autonomos` route with default evidence slots already in canon; add Feature test record autonomos decision.  
- [ ] **Step 2.6:** Optional: map AAEOS mode select → DualCore route helper `AaeosModeToDualCoreRoute`.  
- [ ] **Step 2.7:** Tests green; commit: `refactor(core): AAEOS-GOD P2 mode adapters + dualcore autonomos`

**Phase 2 gate:** adapters covered; DualCore records `autonomos` without exception.

---

### Task 3: Phase 3 — Spine enforcement (N9 + N11)

**Goal:** Engineering done cannot bypass shared delivery/evidence.

**Files:**
- Modify: `AaeosEngineeringSpine.php` (stricter, versioned contract)
- Create: `AaeosSpineGate.php` (throws/returns typed violation)
- Integrate into **at least one** real Dev intake and **one** Forge intake (strangler — search `DualCore`, Dev flow factory, Forge intake services)
- Create: `tests/Unit/Ai/Aaeos/Spine/AaeosEngineeringSpineTest.php` (expand)
- Create: Feature/integration test that parallel_ledger fails closed

- [ ] **Step 3.1:** Document required delivery_runtime_class + evidence_ledger_class (already anchors).  
- [ ] **Step 3.2:** `assertShared` used at Dev programming envelope build **or** equivalent hot intake.  
- [ ] **Step 3.3:** Same for Forge work intake / completion gate path.  
- [ ] **Step 3.4:** Certify path: completion without spine slots → blocked status in envelope.  
- [ ] **Step 3.5:** Inventory remaining parallel paths in `PHASE-3-RECEIPT.md` as residual debts with owners (do not claim 100% call-sites if not done — be honest; target ≥ critical intakes).  
- [ ] **Step 3.6:** Commit: `refactor(core): AAEOS-GOD P3 spine enforcement strangler`

**Phase 3 gate:** critical Dev + Forge intakes enforce spine; tests prove parallel ledger forbidden.

**Honesty rule:** If full strangler of all call-sites is too large, define **Critical Path List** (max 8 sites) and complete 100% of that list before claiming P3 done.

---

### Task 4: Phase 4 — Autônomos full plug

**Goal:** AAEOS cycle is the governance wrapper around live Autônomos, not a duplicate muscle.

**Files:**
- Modify: `AutonomosModeAdapter` / CycleRuntime
- Optional: `AaeosAutonomosDispatchService` (run/observe only)
- Integrate read-only checks against task serving readiness projectors **if** safe (no fight with Readiness godfile claims)
- Tests: unit + feature with fakes for brain/task command invocation **or** dry-run dispatch plans
- Docs: autonomos-live cross-link

- [ ] **Step 4.1:** When mode=autonomos and not dry-run, adapter may call a **safe** dispatch hook (config-flagged) to record “should run brain:next” intent in ledger — default dry.  
- [ ] **Step 4.2:** Receipt includes `muscle=N10`, `seed_gate_required=true`, `scoped_commit_required=true`.  
- [ ] **Step 4.3:** Test: autonomos path never sets `human_in_engineering_loop=true`.  
- [ ] **Step 4.4:** Test: L5 autonomos → auto_notify or halt per policy (document chosen rule).  
- [ ] **Step 4.5:** Ensure keep-list not touched.  
- [ ] **Step 4.6:** Commit: `refactor(core): AAEOS-GOD P4 autonomos adapter plug`

**Phase 4 gate:** autonomos is first-class mode with N10 semantics in receipt + tests.

---

### Task 5: Phase 5 — Quarantine elimination

**Goal:** Operate path pure.

- [ ] **Step 5.1:** `rg -n 'Aaeos\\\\Quarantine' app tests --glob '*.php'` → only archive or zero. Fix any stragglers (re-home like connectors or delete dead tests).  
- [ ] **Step 5.2:** If zero live refs: `git mv app/Services/Ai/Aaeos/Quarantine archive/app/Services/Ai/Aaeos/Quarantine` (or equivalent).  
- [ ] **Step 5.3:** Update catalog path notes; keep catalog in evidence.  
- [ ] **Step 5.4:** `composer dump-autoload -o`; smoke: Control Plane tests + Brain frontier unit if any.  
- [ ] **Step 5.5:** Guard script or test: fail if new file under live Quarantine path.  
- [ ] **Step 5.6:** Commit: `refactor(core): AAEOS-GOD P5 archive quarantine cemetery`

**Phase 5 gate:** production code cannot autoload quarantine; scoreboard quarantine dimension = 10.

---

### Task 6: Phase 6 — Antifragile learning loop

**Goal:** Stress improves the system.

**Files:**
- Create: `AaeosCycleOutcomeRecorder.php` (maps cycle fail/halt to learning candidate fields)
- Hook N12 Compounding or existing proposal API **without** auto-promote
- Create: `AaeosScorecardProjector.php` (read-only metrics)
- Command: `atlas:aaeos:scorecard --json`
- Tests for outcome → proposal status pending_review only

- [ ] **Step 6.1:** On halted/failed cycles, emit learning proposal candidate (provider-safe summary).  
- [ ] **Step 6.2:** Scorecard fields: `cycles_total`, `halts_sovereign`, `mode_mix`, `spine_violations`, `quarantine_imports` (0), `reincident_hint` if data exists.  
- [ ] **Step 6.3:** Never auto-merge memory.  
- [ ] **Step 6.4:** Commit: `refactor(core): AAEOS-GOD P6 scorecard + learning hook`

**Phase 6 gate:** scorecard command runs; learning proposals are gated.

---

### Task 7: Phase 7 — Density, CODEMAP, legacy root cleanup

- [ ] **Step 7.1:** Run density audit on `app/Services/Ai/Aaeos/{Control,Spine,Support}`; split any >800 hot / >2000.  
- [ ] **Step 7.2:** Update `app/Services/Ai/CODEMAP.md` with AAEOS public methods (`runCycle`, `runAutonomosCycle`, `assertShared`, `project` org state, scorecard).  
- [ ] **Step 7.3:** Inventory live non-Control Aaeos root classes; for each: KEEP (justify) | MOVE to Control/Support | DEPRECATE. Write `AAEOS-ROOT-OWNERSHIP.md` in evidence.  
- [ ] **Step 7.4:** No new godfiles; commit: `docs(core)/refactor(core): AAEOS-GOD P7 codemap + density`

**Phase 7 gate:** CODEMAP covers façades; density gates green for AAEOS live tree.

---

### Task 8: Phase 8 — Surfaces + docs final

- [ ] **Step 8.1:** CLI help text + `atlas aaeos` family docs in command descriptions.  
- [ ] **Step 8.2:** Optional HTTP read-only org state / scorecard if pattern exists (thin controller → projector only). Skip if no HTTP convention — CLI sufficient.  
- [ ] **Step 8.3:** Update mother docs:
  - `atlas-agentic-engineering-os.md`
  - `atlas-agentic-engineering-os-implementation-reality.md` (create/update)
  - `atlas-elite-executors-dev-forge-autonomos.md` (link cycle)
  - `AAEOS-VISAO-COMPLETA.html` status badges DONE where true  
- [ ] **Step 8.4:** `AAEOS-DISK-EXECUTION-RECEIPT` superseded note pointing to this program complete.  
- [ ] **Step 8.5:** Commit: `docs(core): AAEOS-GOD P8 surfaces + canon sync`

**Phase 8 gate:** docs and runtime names match; no “human daily loop” drift.

---

### Task 9: Phase 9 — GOD/SOTA certification

**Goal:** Prove composite ≥ 9.0.

- [ ] **Step 9.1:** Create `tests/Feature/Ai/Aaeos/AaeosGodSotaCertificationTest.php` covering:
  - autonomos cycle zero human
  - irreversible halt
  - spine parallel ledger fail
  - elite_same_bar always true
  - quarantine class not loadable from Support path regression
  - DualCore autonomos route
- [ ] **Step 9.2:** Create `scripts/aaeos-god-sota-certify.php` or artisan `atlas:aaeos:certify --json` that prints scoreboard dimensions and exits 0 only if ≥9.0.  
- [ ] **Step 9.3:** Fill `SCOREBOARD.md` final numbers with proof commands pasted.  
- [ ] **Step 9.4:** Write `PHASE-9-COMPLETE.md` with explicit residual (if any residual remains, composite cannot be 9.0 unless residual is non-blocking and documented as out-of-scope with operator OK).  
- [ ] **Step 9.5:** Commit: `test(core): AAEOS-GOD P9 certification suite` + `docs(core): AAEOS-GOD program complete scoreboard`

**Phase 9 gate (PROGRAM COMPLETE):**

```bash
php artisan atlas:aaeos:certify --json   # or script
php artisan test tests/Unit/Ai/Aaeos tests/Feature/Ai/Aaeos tests/Feature/Ai/DualCore --no-coverage
rg -n 'Aaeos\\\\Quarantine' app --glob '*.php' | grep -v archive | wc -l   # expect 0
```

All Done Definition rows true. Composite ≥ 9.0.

---

## Critical path inventory (Phase 3 — fill during P3)

| # | Intake site | Executor | Spine hook | Status |
|---|---|---|---|---|
| 1 | TBD Dev envelope/factory | Dev | assertShared | pending |
| 2 | TBD Forge work intake | Forge | assertShared | pending |
| 3 | DualCore decision evidence defaults | both | canon | partial |
| 4 | AAEOS cycle receipt | all | N11 record | pending |
| 5–8 | discover during P3 | | | |

---

## Dependency graph

```text
P0 → P1 → P2 → P3 → P4
              ↘ P5 (can parallel after P1 if connectors already rehomed)
P3 + P4 + P5 → P6 → P7 → P8 → P9
```

P5 can start after P1 once `rg` is clean.  
P4 should not require Readiness godfile rewrite.  
P3 critical list before claiming spine 9.5.

---

## Execution protocol per phase

1. Read this plan section + current LEDGER.  
2. Claim paths in LEDGER `claimed:`.  
3. Implement steps; tests first or with characterization.  
4. Run phase gate commands; paste into LEDGER.  
5. Update SCOREBOARD dimensions touched.  
6. Scoped commit.  
7. Write PHASE-n-RECEIPT.md.  
8. Do not start next phase if gate red.

---

## Out of scope (explicit)

- Full GOD-DEBULK of entire atlas-server (separate program).  
- Readiness 29k full blueprint (only if unclaimed and needed for P4 — prefer avoid).  
- New business domains, mobile UI, new casca/shell.  
- Rivals superiority claims.  
- Replacing Claude/Codex providers.  
- Auto-promote learning to main memory.

---

## Operator approval checklist (before calling program complete)

- [ ] Scoreboard composite ≥ 9.0 with pasted commands  
- [ ] Quarantine not on operate path  
- [ ] Autonomos default path documented and tested  
- [ ] Dev/Forge critical intakes on spine  
- [ ] No keep-list damage  
- [ ] Mother AAEOS doc matches runtime  
- [ ] Residuals listed with owners or zero residuals  

---

## Quick start (next agent)

```bash
# 1) Create evidence dir + P0
# 2) Implement P1 CLI cycle
php artisan test tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php --no-coverage
# 3) Follow phases in order
```

**First code commit of this program after P0:** Phase 1 `atlas:aaeos:cycle`.

---

## Relationship to GOD-DEBULK

This program **consumes** GOD-DEBULK hygiene (density, no vanity, main scoped) but is **owned** as AAEOS-GOD capability delivery.  
If GOD-DEBULK executors claim SelfConstruction files, **skip** those steps and raise scoreboard residual — do not fight the claim.

---

*End of plan. Completing P0–P9 with all gates green = AAEOS GOD/SOTA complete as defined herein.*
