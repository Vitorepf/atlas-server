# AAEOS — CANONICAL IMPLEMENTATION PLAN (vFINAL · AI-idiot-proof)

> **Status:** CANONICAL · PLAN_ONLY · code needs literal **`EXECUTE P0`**  
> **Who this is for:** any AI/human implementer with zero prior context  
> **Rule:** do exactly the phase authorized. Do not invent Mission Runtime / WorkGraph / second ledger.  
> **Branch:** `main` only · `git add -- <paths>` only · never `git add -A`  
> **Evidence dir:** `docs/evidence/2026-07-23-aaeos-elite-deepening/`  
> **Satellites:** `LEDGER.md` + `SCOREBOARD.md` only  

```text
IF operator said "EXECUTE P0" → do ONLY section P0 below, then stop and write PHASE-0-RECEIPT.md
IF operator said "EXECUTE P1a" → do ONLY section P1a
IF operator said anything else without EXECUTE → DO NOT write production code
```

---

## 0. How to implement (read this first)

### 0.1 Order (never skip)

```text
P0  → stop lying (honesty + run/cycle + admission + delete dead scorers)
P1a → fix Autônomos brain:next args + fuse adapters (behavior-preserving)
P1b → effect authority (REFUSE until P2a+P2b green, then ACT)
P2a → ledger integrity
P2b → Decision v3 / mandate / AWIS
P2c → journey/repair/crash/independence
P3  → economy/M views + hygiene + verification surface
P4  → REAL_OPERATION journeys (out of PHPUnit)
```

### 0.2 Every phase ritual (copy-paste)

```bash
# 1) branch
git branch --show-current   # MUST print: main

# 2) status (do not touch foreign WIP)
git status --short

# 3) baseline tests (save failures)
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Aaeos/Control tests/Feature/Ai/Aaeos --no-coverage

# 4) implement ONLY allowed paths for this phase
# 5) run RED tests first (must fail before fix), then GREEN
# 6) re-run lane tests; no NEW failures vs baseline dirty-main
# 7) scoped commit
git add -- path1 path2 ...
git commit -m "feat(core): AAEOS-MT P0 ..."

# 8) write docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-N-RECEIPT.md
# 9) update LEDGER.md phase row + SCOREBOARD.md gates
# 10) STOP. Do not start next phase without operator EXECUTE.
```

### 0.3 Phase receipt template (fill every field)

Create `docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-0-RECEIPT.md`:

```markdown
# PHASE-0-RECEIPT
- status: passed|failed|blocked|partial
- branch: main
- base_sha: <git rev-parse HEAD before edits>
- result_sha: <after commit>
- allowed_paths: [list]
- touched_paths: [list from git diff --cached --name-only]
- tests_run:
  - cmd: ...
    exit: 0
- residuals_closed: [R17, R21, ...]
- residuals_open: [R33, ...]
- proof_level: AUTOMATED_CHARACTERIZED
- failure_reason: null
- next_phase_authorized: false
```

### 0.4 Forbidden forever (if you do these, stop and revert)

- `git add -A`, new branch for this work, merge/pull-merge  
- Create `MissionRuntime`, `WorkGraph`, `SovereigntyPort`, `AaeosModeExecutor`, second ledger  
- Call `EliteExecutorKernel` **from** AAEOS Control directly  
- Hardcode `human_in_engineering_loop => false` as “identity”  
- Claim DONE/SOTA/god because score ≥ 9  
- Fix R33 inside P0  
- `git` touch `archive/app/Services/Ai/Aaeos/Quarantine`  

---

## 1. Tiny law (do not re-architect)

| Rule | Meaning for code |
|---|---|
| Same bar Dev/Forge/Autônomos | Never lower Autônomos quality |
| Eng judgment agentic | No human required for code review in path |
| Provider untrusted | Provider cannot set verified/land/promote |
| AAEOS thin muscle | AAEOS routes/projects; native owners execute |
| `atlas:aaeos:run` no muscle flags | Strip productive flags from router if present; natives keep them |
| Score diagnostic | `unknown` not floor; no hardcoded 9.2 as measured |

**Useful output ≈ N×M.** This MT builds **M** for engineering: honesty, real Autônomos wire, observed effects. Company-wide M is horizon, not P0.

---

# P0 — EXECUTE THIS WHEN OPERATOR SAYS `EXECUTE P0`

## P0 goal (one sentence)

**Stop the control plane from lying:** dry-run writes, fake scores, run≠cycle, human-loop field authority, technical invalidity as “sovereign halt”. **Do not** fix brain:next args (that is P1a).

## P0 allowed production paths (ONLY these)

```
app/Console/Commands/AtlasAaeosRunCommand.php
app/Console/Commands/AtlasAaeosCycleCommand.php
app/Console/Commands/AtlasAaeosScorecardCommand.php
app/Console/Commands/AtlasAaeosCertifyCommand.php
app/Console/Commands/AtlasAaeosRouterCommand.php
app/Services/Ai/Aaeos/Control/AaeosRunApplication.php          # CREATE
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Aaeos/Control/AaeosAdmissionPolicy.php
app/Services/Ai/Aaeos/Control/AaeosAdmissionVerdict.php
app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php
app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
app/Services/Ai/Aaeos/Control/AaeosOrgStateProjector.php
app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php   # DELETE file
app/Services/Ai/Aaeos/Control/AaeosTriHygieneScorecardProjector.php # DELETE file
```

If `AtlasTriHygieneScorecardCommand.php` exists and only serves TriHygiene projector → DELETE and remove registration if any.

**Also allowed tests (CREATE/UPDATE):**

```
tests/Feature/Ai/Aaeos/AtlasAaeosRunCommandTest.php
tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php
tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php
tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php
tests/Unit/Ai/Aaeos/Control/AaeosAdmissionPolicyTest.php
tests/Feature/Ai/Aaeos/AaeosGodSotaCertificationTest.php   # UPDATE asserts
tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php      # UPDATE if needed
tests/Unit/Ai/Aaeos/Control/AaeosOperateDispatchTest.php    # UPDATE if needed
```

**Also allowed evidence docs:**

```
docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-0-RECEIPT.md
docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md
docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md
```

## P0 step-by-step (do in order)

### Step 0 — prove baseline

```bash
git branch --show-current
BASE=$(git rev-parse HEAD)
echo $BASE
/opt/homebrew/bin/php artisan atlas:aaeos:scorecard --json | head -c 2000
/opt/homebrew/bin/php artisan atlas:aaeos:certify --json | head -c 2000
```

Expect today: composite ~9.5x, `operate_path_wiring` 9.2 inject on certify, `cycles` zero-ish.

### Step 1 — write RED tests FIRST (must fail on current code)

#### 1.1 `tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php`

Assert (against `AaeosCycleRuntime::runCycle` dry path):

- [ ] When `$dryRun === true`, receipt `runtime_write_performed === false`
- [ ] When dry, `dry_run === true`
- [ ] Receipt does **not** require `human_in_engineering_loop` key for correctness (prefer `array_key_exists` false after full P0, or non-authoritative)

#### 1.2 `tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php`

- [ ] `project([])` dimensions that claim measured must not hardcode 9.2/9.5 as “live proof”
- [ ] With zero samples, any `measured_composite` key is `null` OR absent; **never** invent high score from constants alone
- [ ] `god_sota` boolean must **not** be true solely because mean(dimensions) ≥ 9.0 with static dims

Practical approach if full rewrite is large:
- Add `projectMeasured()` or gate: if no runtimeHints samples, set `measured_status=unknown`, `composite=null` for measured view, keep `legacy_assessment` clearly labeled if needed for compat.
- Certify must stop injecting 9.2.

#### 1.3 `tests/Unit/Ai/Aaeos/Control/AaeosAdmissionPolicyTest.php`

Build minimal objective/mode arrays like existing ControlPlane tests:

- [ ] Unknown/invalid mode → verdict **`repair_required`** (new const), **NOT** `halt_sovereign`
- [ ] `allowsExecution` false for repair_required
- [ ] Irreversible outside mandate still can be halt/sovereign path (do not break real sovereign)

#### 1.4 Feature parity tests Run vs Cycle

`AtlasAaeosRunCommandTest` + `AtlasAaeosCycleCommandTest` (create if missing):

- [ ] Same intent + same flags available after P0 → same JSON keys for status/mode/admission (or cycle is thin alias to RunApplication)
- [ ] Exit code: `dispatch_failed` and `halted` → non-zero on **both**
- [ ] `--json` prints receipt only

**Run red:**

```bash
/opt/homebrew/bin/php artisan test \
  tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php \
  tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php \
  tests/Unit/Ai/Aaeos/Control/AaeosAdmissionPolicyTest.php \
  --no-coverage
# Expect FAILURES before you fix production code
```

### Step 2 — `AaeosAdmissionVerdict.php`

- [ ] Add const `REPAIR_REQUIRED = 'repair_required'`
- [ ] Ensure `allowsExecution()` returns **false** for REPAIR_REQUIRED
- [ ] Keep existing AUTO / AUTO_NOTIFY / HALT_SOVEREIGN behavior for real cases
- [ ] Update any match/switch on verdicts (`AaeosCycleOutcomeRecorder`, certify) so REPAIR_REQUIRED is handled (no fatal)

### Step 3 — `AaeosAdmissionPolicy.php`

- [ ] Line ~36 today: `invalid_mode → HALT_SOVEREIGN`  
  **Change to:** `return $this->pack(self::REPAIR_REQUIRED or AaeosAdmissionVerdict::REPAIR_REQUIRED, ['invalid_mode'], ...)`
- [ ] Same for pure technical “unknown mode” style failures (not business irreversible)
- [ ] Do **not** map wipe/legal irreversible to repair_required

### Step 4 — `AaeosCycleRuntime.php`

- [ ] **Stop hardcoding** `'runtime_write_performed' => true` (~line 93)
- [ ] Derive:  
  `runtime_write_performed = (!$dryRun) && (ledger write attempted OR dualcore write OR learning write OR any muscle write)`  
  For dry: **always false**
- [ ] On dry: skip evidence ledger write (already mostly); ensure OutcomeRecorder path from commands also skips writes on dry (see Run/Cycle commands — if they always call `record()`, make recorder no-op when dry_run)
- [ ] Remove or stop emitting authoritative `human_in_engineering_loop`  
  Replace with optional `sovereignty_channel` string if easy: `live_intent|plan_seal|standing_mandate` from mode — **do not** invent complex schema in P0
- [ ] Prefer additive keys: `effect_level` = `none` when not live; `prepared` if pack/intake only; never claim `mutated` without real effect

### Step 5 — `AaeosScorecardProjector.php`

- [ ] Remove hardcoded celebration scores used as truth (`control_plane => 9.2`, thesis 9.5, etc.) as **measured**
- [ ] Structure suggestion:

```php
// legacy_assessment: optional, clearly named, for compat only
// measured: only from runtimeHints with sample_size, else null/unknown
// hard_gates: tree pure, quarantine imports, etc. stay boolean from scans
```

- [ ] `god_sota` / elite marketing flags: **false** unless measured gates say so; never `composite >= 9.0` with static dims

### Step 6 — `AtlasAaeosCertifyCommand.php`

- [ ] **Delete** inject block:

```php
'operate_path_wiring' => 9.2,
'spine_enforced' => 9.2,
'antifragile_loop' => 9.0,
```

- [ ] Stop requiring `human_in_engineering_loop === false` for Autônomos (line ~34). Gate on mode/sovereignty instead or structural purity only.
- [ ] Certify scopes: `structural` ok can stay; **do not** print GOD_SOTA from fake composite

### Step 7 — `AaeosRunApplication.php` (CREATE)

Deep module: one place both commands call.

```php
final class AaeosRunApplication
{
    public function __construct(private AaeosCycleRuntime $runtime, private AaeosCycleOutcomeRecorder $outcomes) {}

    /** @param array<string,mixed> $input normalized flags */
    public function run(array $input): array
    {
        // dryRun wins over live
        // build hints: live_dispatch, max_seeds, execute_provider, run_worker_once, scope, source
        // force_mode if autonomos/mode set
        // receipt = runtime->runCycle(...) or runAutonomosCycle
        // if dry: do not call outcomes->record that writes ledger; attach learning skipped_dry_run
        // else: receipt['learning'] = outcomes->record(receipt)
        // return receipt
    }
}
```

### Step 8 — Run + Cycle commands

**RunCommand:** thin: parse CLI → array → `AaeosRunApplication::run` → print JSON or human.

**CycleCommand:** **same application path**. Same flags as run for: `intent`, `--autonomos`, `--live`, `--dry-run`, `--max-seeds`, `--json`. Prefer adding missing flags to cycle as aliases (mode/execute-provider/run-worker-once/scope) so parity tests pass.

Exit codes (both):

| status | exit |
|---|---|
| halted, dispatch_failed, repair_required-as-failure if you map it | 1 |
| invalid flags | 2 |
| success/plan/prepared | 0 |

### Step 9 — Delete dead projectors

- [ ] Delete `AaeosOperateScorecardProjector.php` if zero real consumers (`rg AaeosOperateScorecardProjector app tests`)
- [ ] Delete TriHygiene projector + command if only LOC/`is_file` vanity
- [ ] Fix any broken references (should be none for Operate projector)

### Step 10 — green tests + lane

```bash
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Aaeos/Control tests/Feature/Ai/Aaeos --no-coverage
/opt/homebrew/bin/php artisan atlas:aaeos:scorecard --json
/opt/homebrew/bin/php artisan atlas:aaeos:certify --json
rg -n 'operate_path_wiring.*=.*9\.2|human_in_engineering_loop' app/Console/Commands/AtlasAaeosCertifyCommand.php app/Services/Ai/Aaeos/Control
```

### Step 11 — commit + receipt

```bash
git add -- \
  app/Console/Commands/AtlasAaeosRunCommand.php \
  app/Console/Commands/AtlasAaeosCycleCommand.php \
  app/Console/Commands/AtlasAaeosScorecardCommand.php \
  app/Console/Commands/AtlasAaeosCertifyCommand.php \
  app/Services/Ai/Aaeos/Control/AaeosRunApplication.php \
  app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php \
  app/Services/Ai/Aaeos/Control/AaeosAdmissionPolicy.php \
  app/Services/Ai/Aaeos/Control/AaeosAdmissionVerdict.php \
  app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php \
  app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php \
  app/Services/Ai/Aaeos/Control/AaeosOrgStateProjector.php \
  tests/Unit/Ai/Aaeos/Control/ \
  tests/Feature/Ai/Aaeos/ \
  docs/evidence/2026-07-23-aaeos-elite-deepening/

# only if deleted:
# git add -- app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php ...

git commit -m "feat(core): AAEOS-MT P0 honesty port admission and measured scorecard"
```

Update LEDGER: P0 = DONE_CODE or FAILED with reason.  
Update SCOREBOARD gates that P0 closed.

**STOP. Do not start P1a without `EXECUTE P1a`.**

---

# P1a — ONLY WHEN OPERATOR SAYS `EXECUTE P1a`

## P1a goal

**Make Autônomos live dispatch actually call `atlas:brain:next` correctly; classify success by payload; fix seed; optional adapter fuse later in same phase if tests green.**

## P1a allowed paths

```
app/Services/Ai/Aaeos/Control/Dispatch/AutonomosLiveDispatcher.php
app/Services/Ai/Aaeos/Control/Dispatch/DevLiveDispatcher.php
app/Services/Ai/Aaeos/Control/Dispatch/ForgeLiveDispatcher.php
app/Services/Ai/Aaeos/Control/Dispatch/AaeosLiveDispatchGateway.php
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Aaeos/Control/Adapters/*          # only if fusing/deleting after characterization
app/Console/Commands/AtlasBrainNextCommand.php    # only if needed for thin presenter; prefer not
app/Console/Commands/AtlasAaeosRouterCommand.php  # strip muscle flags if any
tests/Unit/Ai/Aaeos/Control/AaeosOperateDispatchTest.php
tests/Unit/Ai/Aaeos/Control/AutonomosBrainArgsTest.php   # CREATE
```

## P1a Step 1 — RED: `AutonomosBrainArgsTest.php`

Mock/spy `Artisan::call` OR extract pure `brainNextArgs` for unit test:

**Today broken:**

```php
// BAD
['--json' => true, '--scope' => 'autonomous']  // option does not exist
['--json' => true]  // missing required positional scope
```

**Required good shape:**

```php
// GOOD — Laravel Artisan::call argument name is the signature arg name
[
    'scope' => $scope !== '' ? $scope : 'autonomous',
    '--json' => true,
]
```

Asserts:

- [ ] Default scope becomes `autonomous` when empty  
- [ ] Never passes `--scope`  
- [ ] Always passes positional `scope`  
- [ ] When brain result `exit_code=0` but output/status is `disabled` or `dry`, treat as **not** mutated success (R34) — parse JSON status if present  

## P1a Step 2 — fix `AutonomosLiveDispatcher.php`

```php
private function brainNextArgs(array $options): array
{
    $scope = trim((string) ($options['scope'] ?? ''));
    if ($scope === '') {
        $scope = 'autonomous';
    }

    return [
        'scope' => $scope,
        '--json' => true,
    ];
}
```

- [ ] On result: if `exit_code !== 0` → dispatch_failed / brain_next_failed (keep)  
- [ ] If exit 0: try decode output JSON; if `status` in `disabled|dry|error` → **not** full success for effect_level=mutated  
- [ ] Seed: **remove** `--max`; call `atlas:brain:seed` with only real flags (`--json`, optional `--scope` if seed supports it — seed **has** `--scope` option). Prefer later in-process enqueue; for P1a minimum = honest CLI flags only  
- [ ] Remove inventing unsupported options  

## P1a Step 3 — characterization of Dev/Forge

- [ ] Snapshot current pack/intake behavior in tests  
- [ ] Emit `effect_level=prepared` when only pack/intake (not Kernel mutate)  

## P1a Step 4 — optional fuse

Only if operator wants full P1a fuse in same authorization:

- Keep interface `AaeosModeLiveDispatcher`  
- Delete four `Adapters/*` after all callers go through gateway/runtime  
- **Do not** change admission/irreversibility semantics here  

## P1a commit

```bash
git commit -m "refactor(core): AAEOS-MT P1a fix brain next args and live dispatch honesty"
```

**STOP.**

---

# P1b — ONLY WITH `EXECUTE P1b` (two sub-modes)

## P1b-REFUSE (before P2a/P2b)

- Write tests for present-but-false effect class  
- Wire **refusal**: if no Decision v3 / no authority reload → **zero** provider/tool/mutation  
- Do **not** enable full land path yet  

## P1b-ACT (after P2a+P2b green)

- PRE-AUTHORIZE → ACT → POST-ATTEST → SETTLE on **native** merge/commit owners  
- Observer write-set from `AtlasTaskMergeActuator` seam (extract `changedFiles` if needed)  
- Goldens: declare read_only but mutate → block; no-keyword irreversible action still classified  
- **Never** create `AaeosActionEffectClassifier` as global authority god-object if native owners already resolve class — prefer native gateway  

---

# P2 — ONLY WITH `EXECUTE P2` (split commits)

| Slice | Do | Don't |
|---|---|---|
| **P2a** | Ledger hash verify + replay; spine refs must resolve settlement receipts; fail `assertShared([],…)` empty self-green | new ledger table |
| **P2b** | DecisionReceipt v3 + keyring + revoke head; AWIS mode includes autonomos; unknown fail-closed | skip authority then call provider |
| **P2c** | Journey single-root; repair continues same root; crash resume once | new journey store |
| **P2d** | H1–H7: block only reserved effect; other work continues (A/B test) | global halt |

**Binding order:** P2a → P2b → (unlock P1b-ACT) → P2c/d  

Exact production paths: amend MASTER + PHASE receipt if you need a file not listed — **stop and ask** rather than invent.

---

# P3 — ONLY WITH `EXECUTE P3`

- [ ] `scorecard --view=economy` over all commissioned journeys (include failures)  
- [ ] `enforced_governed_coverage` reported; never call ledger `governed` that folds consulted as M  
- [ ] `scorecard --view=verification` from journey manifest  
- [ ] Lazy `AaeosHygieneLegacyAliases::register` if still eager  
- [ ] Alias burn only with classmap proof  
- [ ] Docs: 17-phase is not daily operate  

---

# P4 — ONLY WITH `EXECUTE P4`

**Not PHPUnit as producer.**

| Mode | Producer command (reuse) | Success |
|---|---|---|
| Dev | existing senior-loop / dev run command | REAL_OPERATION + fresh readback |
| Forge | existing forge live/obra command | full obra terminal, no post-seal human tech |
| Autônomos | RuntimeDaemon (extracted) after preflight | brain→…→land OR `blocked_ops` PARTIAL if master off |

**REAL_OPERATION minimum legs:**

1. Off-host signing key custody **or** document PARTIAL if unavailable  
2. ≥1 COVERED provider spawn linked to journey (or explicit N/A if pure structural journey — default require spawn for eng)  
3. Git write-set re-derived from world  
4. Fresh process recompute ledger/git truth  
5. Subtract-one: remove any leg → fail  

Write `PHASE-4-RECEIPT.md` + artifacts under evidence dir.

---

## DONE (program)

**90-day honest target:** P0–P3 green + P4 Dev/Forge real + Autônomos PARTIAL if fleet off.

**Full DONE:** three-mode REAL_OPERATION + all T-gates + `capability_proof ≥ internal_only`.

**Never claim from DONE alone:** 50×, world #1, company 100% Atlas, SUSTAINED 24/7.

---

## Quick reference — bugs and exact fixes

| ID | File today | Wrong | Right |
|---|---|---|---|
| R33 | `AutonomosLiveDispatcher::brainNextArgs` | `--scope` or missing scope | `'scope' => $scope ?: 'autonomous', '--json' => true` |
| R34 | same | exit 0 = success | parse status disabled/dry/error |
| R35 | seed call | `--max` | only real seed flags / in-process enqueue |
| R17 | `AaeosCycleRuntime:93` | rwp always true | derive; dry false |
| R16 | ScorecardProjector / Certify | 9.2 constants/hints | unknown/measured/remove inject |
| R38 | Certify:34, CycleRuntime:107, adapters | human field authority | remove; sovereignty_channel optional |
| R40 | AdmissionPolicy:36 | invalid_mode → halt_sovereign | → repair_required |
| Law9 | ProviderGovernanceConsult:122 | enforce default off | constitutional block independent of enforce |

---

## Constitution one-pager (do not expand scope)

- Mother law at **seams**, not by forcing every call through `aaeos:run`  
- Provider output untrusted; provider input (egress) sovereign  
- OneShot = operator UX; internal retries free  
- M = enforced coverage + refuse/repair, not vanity score  
- No new OS organs  

---

## Handoff

| If operator says | You do |
|---|---|
| `EXECUTE P0` | Section P0 only → PHASE-0-RECEIPT → stop |
| `EXECUTE P1a` | Section P1a only → stop |
| `EXECUTE P1b` | Refuse-mode or ACT-mode per P2 gates |
| `more absolute plan` | Refuse; implement or ask disk residual |
| nothing | Read-only; no code |

**This document is the implementable god-SOTA plan.**  
**The system becomes god-SOTA only after PHASE receipts are green on disk.**
