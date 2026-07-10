# Atlas Brain Extraordinary Leap — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver 100% of the extraordinary brain leap: (1) make fused/unified retrieval and Decide actually change the live hot path under gated rollout, (2) close engineering outcome → memory → held-out proof, (3) make must-keep compaction/handoff the elite default, (4) ship a real verify-retrieve agentic loop (not the AUCRI sufficiency false-friend), (5) put AURG world-model into injection ranking — with ON vs OFF measurement that proves the leap.

**Architecture:** No parallel brain. Extend the existing ACOS hot path: `AtlasContextRuntime` + `AtlasOpenBrainContextPackService` + `AtlasOpenBrainContextInjectionService` + `AtlasEngineeringOutcomeRecorder` + `AtlasVerifiedContextExecutionLoopService` (evolved from shadow-cert into verify-retrieve). Promote only through `atlas:intelligence:rollout-promote` with certifier gates and kill switches. Measure every promotion with held-out Local RAG + elite task deltas (ON vs OFF).

**Tech Stack:** Laravel 13 / PHP 8.4, Postgres+pgvector, existing AOBG/AURG/AEMOR/APCR/TEOS surfaces, PHPUnit, artisan CLIs.

**North-star constraint:** `docs/engineering-knowledge-base/atlas-terminal-first-focus.md` — verification > generation; no new shell/UI; CLI + brain seams only.

**Definition of Done (100%):**
1. Unified retrieval + fusion reorder injection refs on the live elite path (canary or default) with kill switch proven.
2. Decide gateway can apply learned routes in active mode after SLO gate (still fail-closed on Kernel/Admission).
3. Dev + Forge + Autônomos all write engineering outcomes through `AtlasEngineeringOutcomeRecorder`; promoted deltas measurably improve held-out recall.
4. Elite handoff packs always ship `must_keep_coverage=1.0` and next-session compose consumes them.
5. Agentic verify-retrieve loop runs at least one bounded retrieve→verify→expand cycle before elite commit/response when context is insufficient.
6. AURG cross-layer paths are included and ranked in injection (not receipt-only).
7. Published ON/OFF scoreboard proves leap (not opinion).

---

## Spec → task coverage (self-check)

| Promise from prior chat | Tasks |
|---|---|
| Promote unified retrieval / fusion / Decide | 1–5, 19 |
| Fusion must reorder injection (not receipt-only) | 1–3 |
| Close AEMOR learning + held-out proof | 6–10 |
| Must-keep compaction + handoff | 11–13 |
| Agentic RAG verify-loop (real) | 14–16 |
| AURG world-model in injection | 17–18 |
| Measurement + promotion ops | 19–20 |

## False-friend traps (do not treat as done)

| Looks like intelligence | Actually |
|---|---|
| `retrieval_fusion` pack section | Receipt/markdown only until Task 2 |
| `AtlasAgenticRagFrameworkService` | AUCRI source-sufficiency gate (`is_agentic=false`) |
| `AtlasVerifiedContextExecutionLoopService` today | Shadow certification pipeline, not retrieve loop |
| `semanticallyReorderMemory()` | Memory-only reorder |
| Autônomos AEMOR “already done” | **Forge only** is wired today; TaskServing has no `EngineeringOutcomeRecorder` |

## File map (create vs modify)

### Create
- `app/Services/Ai/Context/AtlasFusionInjectionApplier.php` — applies RRF candidates into pack section order + injection ref order
- `app/Services/Ai/Context/AtlasBrainLeapScoreboardService.php` — ON/OFF measurement receipt
- `app/Console/Commands/AtlasBrainLeapScoreboardCommand.php` — `atlas:intelligence:leap-scoreboard`
- `app/Services/Ai/VerifiedContextExecution/AtlasVerifyRetrieveLoopService.php` — bounded retrieve→verify→expand
- `tests/Unit/Ai/Context/AtlasFusionInjectionApplierTest.php`
- `tests/Unit/Ai/Context/AtlasBrainLeapScoreboardServiceTest.php`
- `tests/Feature/Ai/VerifiedContextExecution/AtlasVerifyRetrieveLoopServiceTest.php`
- `tests/Feature/Console/AtlasBrainLeapScoreboardCommandTest.php`
- `docs/engineering-knowledge-base/atlas-brain-extraordinary-leap.md` — operator canon (after code green)

### Modify
- `app/Services/Ai/AtlasOpenBrainContextPackService.php` — call applier when fusion live
- `app/Services/Ai/AtlasOpenBrainContextInjectionService.php` — honor fused ref order; reality graph ranking; include_reality_graph path
- `app/Services/Ai/Context/AtlasContextRuntime.php` — already rollout-aware; expose fusion status on contract
- `config/atlas.php` — fusion apply flag / verify-retrieve mode / reality inclusion rollout keys
- `app/Services/Ai/SelfConstruction/AtlasTaskServingService.php` — wire `AtlasEngineeringOutcomeRecorder`
- Dev fast-path orchestrator (locate live completion hook; wire recorder) — see Task 6
- `app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php` — delegate to verify-retrieve when enabled
- `app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php` + Forge continuation builder — consume must_keep handoff in next compose
- `bootstrap/app.php` — register new command

---

## Phase 0 — Freeze measurement baseline (no code leap yet)

### Task 0: Capture OFF baseline scoreboard

**Files:**
- Create later scoreboard service in Task 19; for now operator capture only

- [ ] **Step 1: Record current flags**

Run:
```bash
cd /Users/vitorepf/develop/Atlas/atlas-server
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode(["unified"=>config("atlas.context_runtime"),"fusion_enabled"=>config("atlas.aobg.fusion_enabled"),"fusion_mode"=>config("atlas.aobg.fusion_mode"),"decide"=>config("atlas.atlas_decide"),"aemor"=>config("atlas.aemor"),"reality"=>config("atlas.open_brain.injection.include_reality_graph")], JSON_PRETTY_PRINT);'
```
Expected: unified OFF/offline, fusion OFF/offline, decide shadow, aemor default, reality false.

- [ ] **Step 2: Capture quality gates OFF baseline**

Run:
```bash
php artisan atlas:context:quality-certify --json > /tmp/atlas-leap-baseline-quality.json
php artisan atlas:aemor:readiness --json > /tmp/atlas-leap-baseline-aemor.json
php artisan atlas:ai:slo --hours=24 --json > /tmp/atlas-leap-baseline-slo.json
php artisan atlas:ai:local-rag-benchmark --json > /tmp/atlas-leap-baseline-rag.json || true
```
Store hashes:
```bash
shasum /tmp/atlas-leap-baseline-*.json
```

- [ ] **Step 3: Commit nothing** — baseline artifacts stay local under `/tmp` (or `storage/atlas/intelligence/baseline/` if operator prefers durable). Do not mint fake evidence.

---

## Phase 1 — Fusion actually changes provider context

### Task 1: Failing test for fusion applicator

**Files:**
- Create: `tests/Unit/Ai/Context/AtlasFusionInjectionApplierTest.php`
- Create: `app/Services/Ai/Context/AtlasFusionInjectionApplier.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AtlasFusionInjectionApplier;
use Tests\TestCase;

final class AtlasFusionInjectionApplierTest extends TestCase
{
    public function test_apply_reorders_pack_sections_by_fusion_candidates(): void
    {
        $pack = [
            'code_graph' => [
                ['ref' => 'code:b', 'path' => 'b.php'],
                ['ref' => 'code:a', 'path' => 'a.php'],
            ],
            'memory' => [
                ['ref' => 'mem:2', 'title' => 'two'],
                ['ref' => 'mem:1', 'title' => 'one'],
            ],
            'reality_graph_paths' => [
                ['ref' => 'aurg:y', 'target' => 'code:module:y'],
                ['ref' => 'aurg:x', 'target' => 'code:module:x'],
            ],
            'retrieval_fusion' => [
                'status' => 'fused',
                'algorithm' => 'rrf',
                'candidates' => [
                    ['source' => 'memory', 'ref' => 'mem:1', 'fused_score' => 0.9],
                    ['source' => 'code', 'ref' => 'code:a', 'fused_score' => 0.8],
                    ['source' => 'reality', 'ref' => 'aurg:x', 'fused_score' => 0.7],
                    ['source' => 'memory', 'ref' => 'mem:2', 'fused_score' => 0.6],
                    ['source' => 'code', 'ref' => 'code:b', 'fused_score' => 0.5],
                    ['source' => 'reality', 'ref' => 'aurg:y', 'fused_score' => 0.4],
                ],
            ],
        ];

        $out = (new AtlasFusionInjectionApplier)->apply($pack);

        $this->assertSame(['mem:1', 'mem:2'], array_column($out['memory'], 'ref'));
        $this->assertSame(['code:a', 'code:b'], array_column($out['code_graph'], 'ref'));
        $this->assertSame(['aurg:x', 'aurg:y'], array_column($out['reality_graph_paths'], 'ref'));
        $this->assertTrue((bool) data_get($out, 'retrieval_fusion.applied_to_sections'));
    }

    public function test_apply_is_noop_when_no_fusion_candidates(): void
    {
        $pack = [
            'code_graph' => [['ref' => 'code:a']],
            'memory' => [],
            'reality_graph_paths' => [],
        ];
        $out = (new AtlasFusionInjectionApplier)->apply($pack);
        $this->assertSame($pack['code_graph'], $out['code_graph']);
        $this->assertFalse((bool) data_get($out, 'retrieval_fusion.applied_to_sections'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Ai/Context/AtlasFusionInjectionApplierTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

final class AtlasFusionInjectionApplier
{
    /**
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    public function apply(array $pack): array
    {
        $candidates = array_values((array) data_get($pack, 'retrieval_fusion.candidates', []));
        if ($candidates === []) {
            data_set($pack, 'retrieval_fusion.applied_to_sections', false);

            return $pack;
        }

        $orderBySource = [
            'code' => [],
            'memory' => [],
            'reality' => [],
        ];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $source = (string) ($candidate['source'] ?? '');
            $ref = (string) ($candidate['ref'] ?? '');
            if ($ref === '' || ! isset($orderBySource[$source])) {
                continue;
            }
            $orderBySource[$source][] = $ref;
        }

        $pack['code_graph'] = $this->reorder((array) ($pack['code_graph'] ?? []), $orderBySource['code']);
        $pack['memory'] = $this->reorder((array) ($pack['memory'] ?? []), $orderBySource['memory']);
        $pack['reality_graph_paths'] = $this->reorder((array) ($pack['reality_graph_paths'] ?? []), $orderBySource['reality']);
        data_set($pack, 'retrieval_fusion.applied_to_sections', true);

        return $pack;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<string>  $preferredRefs
     * @return list<array<string,mixed>>
     */
    private function reorder(array $items, array $preferredRefs): array
    {
        if ($preferredRefs === [] || $items === []) {
            return array_values($items);
        }
        $byRef = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ref = (string) ($item['ref'] ?? '');
            if ($ref !== '') {
                $byRef[$ref] = $item;
            }
        }
        $ordered = [];
        $seen = [];
        foreach ($preferredRefs as $ref) {
            if (isset($byRef[$ref]) && ! isset($seen[$ref])) {
                $ordered[] = $byRef[$ref];
                $seen[$ref] = true;
            }
        }
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ref = (string) ($item['ref'] ?? '');
            if ($ref === '' || isset($seen[$ref])) {
                continue;
            }
            $ordered[] = $item;
            $seen[$ref] = true;
        }

        return $ordered;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Ai/Context/AtlasFusionInjectionApplierTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ai/Context/AtlasFusionInjectionApplier.php tests/Unit/Ai/Context/AtlasFusionInjectionApplierTest.php
git commit -m "feat(acos): add fusion injection applier to reorder pack sections"
```

### Task 2: Wire applier into packFor when fusion is live

**Files:**
- Modify: `app/Services/Ai/AtlasOpenBrainContextPackService.php`
- Modify: `tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php`
- Modify: `config/atlas.php` (optional explicit `fusion_apply_to_sections` — default true when fusion live)

- [ ] **Step 1: Extend existing fusion test** to assert section order changes when fusion enabled + live mode

Add assertion that after `packFor()`, with fused candidates, `array_column($pack['memory'], 'ref')` follows fusion order (construct fixture so order differs from raw retrieval).

- [ ] **Step 2: Run test — expect FAIL** (receipt exists, sections not reordered)

- [ ] **Step 3: Implement wire**

In `packFor()`, after building `retrieval_fusion`, when `AtlasIntelligenceRolloutMode::shouldExecuteLive($fusionMode)`:

```php
$pack = app(AtlasFusionInjectionApplier::class)->apply($pack);
```

In shadow mode: still compute fusion receipt, **do not** apply section reorder (keeps byte-comparable injection for dual-path).

- [ ] **Step 4: Run pack service tests**

Run: `php artisan test --filter=AtlasOpenBrainContextPackServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ai/AtlasOpenBrainContextPackService.php tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php config/atlas.php
git commit -m "feat(aobg): apply RRF fusion order to pack sections on live rollout"
```

### Task 3: Injection honors fused pack order for refs

**Files:**
- Modify: `app/Services/Ai/AtlasOpenBrainContextInjectionService.php`
- Modify: `tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php`

- [ ] **Step 1: Write failing test** — `precomputed_aobg_pack` with reordered memory/code must produce `refs` in that relative order (at least within source groups; preferably global fusion order if present).

- [ ] **Step 2: Run — FAIL** if `mergeRefs()` still flat-concats ignoring fusion.

- [ ] **Step 3: Minimal fix** — if `retrieval_fusion.applied_to_sections` or candidates present, emit refs following candidate order (map source→ref), then append unmentioned refs.

- [ ] **Step 4: Run injection tests — PASS**

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ai/AtlasOpenBrainContextInjectionService.php tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php
git commit -m "feat(aobg): honor fused candidate order in context injection refs"
```

### Task 4: Shadow → canary promotion for unified retrieval + fusion (operator gated)

**Files:** none new (ops via existing command)

- [ ] **Step 1: Dry-run promote shadow**

```bash
php artisan atlas:intelligence:rollout-promote unified_retrieval --to=shadow --json
php artisan atlas:intelligence:rollout-promote fusion --to=shadow --json
```
Expected: dry_run receipt, gates pass or `--skip-gates` only with operator note.

- [ ] **Step 2: Apply shadow**

```bash
php artisan atlas:intelligence:rollout-promote unified_retrieval --to=shadow --apply --json
php artisan atlas:intelligence:rollout-promote fusion --to=shadow --apply --json
```

- [ ] **Step 3: Prove shadow does not change live injection** — unit/runtime: `retrieval_core_status=shadow`, no `precomputed_aobg_pack` on inject; fusion receipt present, `applied_to_sections=false`.

- [ ] **Step 4: Canary 10% after Task 3 green**

```bash
php artisan atlas:intelligence:rollout-promote unified_retrieval --to=canary --canary-percent=10 --apply --json
php artisan atlas:intelligence:rollout-promote fusion --to=canary --canary-percent=10 --apply --json
```

- [ ] **Step 5: Kill-switch drill**

```bash
php artisan atlas:intelligence:rollout-promote unified_retrieval --off --apply --json
# restore canary after drill
php artisan atlas:intelligence:rollout-promote unified_retrieval --to=canary --canary-percent=10 --apply --json
```

Do **not** go to default until Phase 5 scoreboard passes.

---

## Phase 2 — Close engineering learning loop for all three elite executors

### Task 5: Prove Forge remains wired; add regression test

**Files:**
- Modify: `tests/Feature/Ai/Aemor/AtlasEngineeringOutcomeRecorderTest.php` or Forge live execution test

- [ ] Confirm `AtlasForgeLiveExecutionService` still calls `->record([...])`.
- [ ] Add/extend test that forge completion path invokes recorder (mock ok).
- [ ] Commit: `test(aemor): lock forge engineering outcome recorder wiring`

### Task 6: Wire Autônomos TaskServing → EngineeringOutcomeRecorder

**Files:**
- Modify: `app/Services/Ai/SelfConstruction/AtlasTaskServingService.php`
- Create/Modify: `tests/Feature/Ai/SelfConstruction/AtlasTaskServingAemorOutcomeTest.php`

- [ ] **Step 1: Failing test** — on successful scoped task completion with evidence refs, assert one AEMOR episode + pending delta when learning_claim set and rollout live.

- [ ] **Step 2: Implement** — inject `AtlasEngineeringOutcomeRecorder`, call `record()` on terminal success/fail with:
  - `executor=autonomos`
  - `evidence_refs` including commit hash / test gate ids
  - `status` mapped from task result
  - never auto-promote

- [ ] **Step 3: Tests PASS; brain/task health unchanged**

```bash
php artisan test tests/Feature/Ai/SelfConstruction/AtlasTaskServingAemorOutcomeTest.php
php artisan atlas:task:health --json
```

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(autonomos): record engineering outcomes through AEMOR"
```

### Task 7: Wire Dev completion → EngineeringOutcomeRecorder

**Files:**
- Locate Dev completion hook (likely `AtlasDevFastPathOrchestrator` or programming pipeline finalizer — confirm with `rg -n "stageAemor|DevFastPath|programming.dev" app/Services/Ai/Programming`)
- Modify that service + feature test

- [ ] Same contract as Task 6 with `executor=dev`.
- [ ] Commit: `feat(dev): record engineering outcomes through AEMOR`

### Task 8: Operator promotion path drill (manual, scripted)

- [ ] Produce one pending delta via recorder test path.
- [ ] Promote with existing CLI:

```bash
php artisan atlas:cli:memory review --json
php artisan atlas:cli:memory accept <delta_id>
php artisan atlas:cli:memory promote <delta_id>
```

- [ ] Assert registry entry exists and appears in `LocalRagBenchmarkService` promoted fixtures on next run.

- [ ] Document exact commands in leap canon doc (Task 20). No new promoter service.

### Task 9: Held-out proof that promoted engineering memory improves recall

**Files:**
- Modify: `app/Services/Ai/Context/LocalRagBenchmarkService.php` (if needed to include engineering-executor tagged fixtures)
- Modify: `resources/atlas/local_rag/independent_precision_corpus.v1.json` only if held-out cases missing engineering promotion scenario
- Test: extend quality-certify / local-rag tests

- [ ] Add case: query that should hit a freshly promoted engineering learning.
- [ ] Run:

```bash
php artisan atlas:ai:local-rag-benchmark --json
php artisan atlas:context:quality-certify --json
```

- [ ] Store `/tmp/atlas-leap-post-learning-rag.json` and require non-worse than baseline on memory recall slice.
- [ ] Commit: `test(rag): held-out proof for promoted engineering memory`

---

## Phase 3 — Must-keep compaction + handoff as elite default

### Task 10: Enable must_keep allocator on elite certify path (flag-gated → default for programming)

**Files:**
- Modify: `config/atlas.php` (`atlas.context_budget.must_keep_allocator_enabled`)
- Modify: `app/Services/Ai/Context/AtlasTokenEconomyRuntimeService.php` / AUCRI enforcement consumers as needed
- Tests: `tests/Unit/Ai/Context/ContextWindowMustKeepBudgetAllocatorTest.php`

- [ ] Write failing integration: elite `certify()` with must_keep segments never drops below coverage 1.0 when allocator ON.
- [ ] Flip flag for programming domain only if a domain-scoped flag exists; otherwise enable globally with kill switch env.
- [ ] Commit: `feat(context): enforce must_keep allocator on elite certify path`

### Task 11: Next-session compose consumes APCR/TEOS continuity must_keep

**Files:**
- Modify: `app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php`
- Modify: `app/Services/Ai/Programming/Forge/ForgeContinuationPackBuilder.php` (or `ForgeLongHorizonStateService`)
- Modify: `app/Services/Ai/Context/AtlasContextRuntime.php` options intake for `continuity_pack` / `persistent_context_hash`
- Tests: existing continuity + new compose-consume test

- [ ] Failing test: given continuation pack with must_keep ledger, `compose()` injection includes those refs / hashes.
- [ ] Implement consume path (no second brain — pass as options into pack/inject).
- [ ] Certify:

```bash
php artisan atlas:persistent-context:certify --json
php artisan atlas:long-horizon:continuity-certify --json || php artisan atlas:teos:readiness --json
```

- [ ] Commit: `feat(handoff): elite compose consumes must_keep continuity packs`

### Task 12: Compaction loss regression guard

**Files:**
- Modify/extend: `tests/Feature/Ai/Compaction/AiCompactionServiceCompactForScopeTest.php`
- Modify/extend: `tests/Feature/Ai/ContextIntelligence/AtlasVerifiedCompactionServiceTest.php`

- [ ] Assert `must_keep_coverage === 1.0` after compaction for fixture with must_keep segments.
- [ ] Commit: `test(compaction): lock must_keep_coverage=1.0 invariant`

---

## Phase 4 — Real agentic verify-retrieve loop

### Task 13: Spec seam (no rename of AUCRI false-friend)

**Files:**
- Create: `app/Services/Ai/VerifiedContextExecution/AtlasVerifyRetrieveLoopService.php`
- Create: `tests/Feature/Ai/VerifiedContextExecution/AtlasVerifyRetrieveLoopServiceTest.php`

Contract:
```php
/**
 * @param array{
 *   objective:string,
 *   workspace:string,
 *   flow_id?:string,
 *   max_cycles?:int, // default 1, hard cap 2
 *   verification_command?:string
 * } $input
 * @return array{
 *   schema_version:string,
 *   status: ready|needs_context|blocked,
 *   cycles: list<array>,
 *   final_context_pack_hash:?string,
 *   verification: array,
 *   writes:false
 * }
 */
public function run(array $input): array
```

Cycle:
1. Critic: call `AtlasAgenticRagFrameworkService::plan()` for missing source types (sufficiency only).
2. If missing → `AtlasContextRuntime::compose()` / pack expand handles for those sources (existing on-demand, no provider invent).
3. `AtlasLocalVerificationEngineService::run()` (or scoped tests) on workspace claim.
4. Stop if verify pass OR max_cycles OR no new sources.

- [ ] **Step 1: Failing test** — insufficient plan → second compose called once → verify receipt present.
- [ ] **Step 2: Implement service** (fail-open, provider-safe, zero writes to memory).
- [ ] **Step 3: PASS**
- [ ] **Step 4: Commit** `feat(acos): add bounded verify-retrieve context loop`

### Task 14: Wire loop into verified-context execution command + elite preflight flag

**Files:**
- Modify: `app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php`
- Modify: `app/Console/Commands/AtlasVerifiedContextExecutionLoopCommand.php`
- Modify: `config/atlas.php` — `atlas.verified_context.verify_retrieve_mode` = offline|shadow|canary|default
- Modify: Forge/TaskServing/Dev preflight **only** when mode live (shadow = receipt only)

- [ ] Shadow default ON for programming can wait; start `offline`, then promote like other intelligence flags (extend `AtlasIntelligenceRolloutPromoteCommand` FEATURES map with `verify_retrieve`).
- [ ] Commit: `feat(acos): gate verify-retrieve loop behind intelligence rollout mode`

### Task 15: Extend rollout-promote for verify_retrieve + reality_injection features

**Files:**
- Modify: `app/Console/Commands/AtlasIntelligenceRolloutPromoteCommand.php`
- Modify: `tests/Feature/Console/AtlasIntelligenceRolloutPromoteCommandTest.php`

- [ ] Add features:
  - `verify_retrieve` → `ATLAS_VERIFIED_CONTEXT_VERIFY_RETRIEVE_MODE` / `_ENABLED`
  - `reality_injection` → `ATLAS_OPEN_BRAIN_INJECTION_INCLUDE_REALITY_GRAPH` (bool) + optional mode if added
- [ ] Gates: verify_retrieve → `atlas:verified-context-execution` + quality-certify; reality → quality-certify + aurg status
- [ ] Commit: `feat(rollout): promote verify-retrieve and reality injection modes`

---

## Phase 5 — AURG world-model into injection

### Task 16: Prefer cross-layer AURG paths in pack section (already filtered) + enable injection

**Files:**
- Modify: `config/atlas.php` / env promote for `ATLAS_OPEN_BRAIN_INJECTION_INCLUDE_REALITY_GRAPH`
- Modify: `app/Services/Ai/AtlasOpenBrainContextInjectionService.php`
- Tests: injection + `tests/Feature/Reality/AtlasAurgQueryTest.php`

- [ ] Failing test: with `include_reality_graph=true`, injection refs contain `aurg:` / reality refs for seeded memory↔code edge.
- [ ] Implement enable path (today default false).
- [ ] Ensure workspace scope still excludes foreign code nodes (existing test must remain green).
- [ ] Commit: `feat(aurg): include reality graph refs in open-brain injection`

### Task 17: Cross-source rank: reality participates in fusion applier

**Files:**
- Modify: `AtlasRetrievalFusionService` only if reality candidates missing from fuse inputs (should already accept reality_graph_paths).
- Modify tests to assert reality refs appear in fused candidate list and in final injection order.

- [ ] Commit: `test(aurg): reality paths participate in fused injection order`

---

## Phase 6 — Scoreboard + final promotion to default

### Task 18: Brain leap scoreboard service + command

**Files:**
- Create: `app/Services/Ai/Context/AtlasBrainLeapScoreboardService.php`
- Create: `app/Console/Commands/AtlasBrainLeapScoreboardCommand.php`
- Create: `tests/Unit/Ai/Context/AtlasBrainLeapScoreboardServiceTest.php`
- Create: `tests/Feature/Console/AtlasBrainLeapScoreboardCommandTest.php`
- Modify: `bootstrap/app.php`

Scoreboard compares OFF snapshot vs current for:
- `context.quality_score`
- `local_rag` memory recall slice
- `aemor.ready` 9/9
- `slo` window
- `fusion.applied_to_sections` rate (from recent pack receipts if available)
- `engineering_outcomes_recorded` counts by executor (dev/forge/autonomos)
- `must_keep_coverage` min on recent continuity packs
- `verify_retrieve` cycles run / pass rate

Emit:
```json
{
  "schema_version": "atlas.intelligence.leap_scoreboard.v1",
  "verdict": "leap_confirmed|insufficient|regressed",
  "deltas": {},
  "gates": {},
  "receipt_hash": "sha256:..."
}
```

Verdict `leap_confirmed` only if: no quality/SLO/AEMOR regression AND at least two of {fusion applied live, reality in injection, verify-retrieve used, all three executors recording, held-out memory recall improved}.

- [ ] TDD service + command dry-run writing JSONL under `storage/atlas/intelligence/leap_scoreboards.jsonl`
- [ ] Commit: `feat(acos): add ON/OFF brain leap scoreboard`

### Task 19: Final promote defaults after scoreboard leap_confirmed

Operator sequence (do not skip):

```bash
php artisan atlas:intelligence:leap-scoreboard --json
# require verdict=leap_confirmed

php artisan atlas:intelligence:rollout-promote unified_retrieval --to=default --apply --json
php artisan atlas:intelligence:rollout-promote fusion --to=default --apply --json
php artisan atlas:intelligence:rollout-promote reality_injection --to=default --apply --json
php artisan atlas:intelligence:rollout-promote verify_retrieve --to=canary --canary-percent=25 --apply --json
php artisan atlas:intelligence:rollout-promote gateway_consultation --to=default --apply --json  # maps to active
```

- [ ] Re-run scoreboard; store final receipt.
- [ ] Kill-switch drill once more on fusion; restore.

### Task 20: Canonical operator doc (after code green)

**Files:**
- Create: `docs/engineering-knowledge-base/atlas-brain-extraordinary-leap.md` (canonical module doc sections required by governance)
- Update pointers in `docs/engineering-knowledge-base/atlas-cognition-operating-system.md` (short “Leap status” only — do not duplicate)

- [ ] Doc must include: false-friends table, promote commands, kill switches, scoreboard interpretation, keep Autônomos vs ACDE note.
- [ ] Sync knowledge if required by house process:

```bash
php artisan atlas:engineering:knowledge docs-health --json
```

- [ ] Commit: `docs(acos): canon for brain extraordinary leap delivery`

---

## Verification matrix (must all be green for 100%)

| Check | Command |
|---|---|
| Fusion reorders sections | `php artisan test tests/Unit/Ai/Context/AtlasFusionInjectionApplierTest.php` |
| Injection honors fusion | `php artisan test --filter=AtlasOpenBrainContextInjectionServiceTest` |
| Unified shadow/live | `php artisan test tests/Unit/Ai/Context/AtlasContextRuntimeUnifiedRetrievalTest.php` |
| AEMOR 3 executors | dedicated TaskServing + Dev + Forge tests |
| AEMOR readiness | `php artisan atlas:aemor:readiness --json` |
| Held-out RAG | `php artisan atlas:ai:local-rag-benchmark --json` |
| Quality | `php artisan atlas:context:quality-certify --json` |
| Must-keep / APCR / TEOS | continuity + persistent-context certify |
| Verify-retrieve | `php artisan test tests/Feature/Ai/VerifiedContextExecution/AtlasVerifyRetrieveLoopServiceTest.php` |
| AURG scoped + injected | `php artisan test --filter=AtlasAurgQueryTest` + injection test |
| Scoreboard | `php artisan atlas:intelligence:leap-scoreboard --json` → `leap_confirmed` |
| Live health | `php artisan atlas:brain:summary --compact` + `atlas:task:health --json` |
| Keep-list untouched | `php artisan test --filter=AtlasEliteCompactionCommandTest` |

## Out of scope (explicitly NOT in this leap)

- New desktop/mobile shell or IDE inline edit
- Rebuilding AUCRI 18-block taxonomy
- Auto-promoting memory without human accept
- Reviving ACDE / `atlas:loop:*`
- Claiming “agentic RAG” by enabling `AtlasAgenticRagFrameworkService` alone

## Risk register

| Risk | Mitigation |
|---|---|
| Fusion apply worsens noise | shadow dual-path + canary % + quality gate before default |
| Decide active wrong provider | keep Kernel/Admission + auto-worker allowlist; SLO gate; `--off` |
| Outcome spam / false learning | evidence_required + false_learning_gate + no auto_promote |
| Verify-retrieve latency | hard cap 2 cycles; offline kill; shadow receipts first |
| Goodhart on scoreboard | require multi-signal leap_confirmed; held-out not train set |

## Suggested execution mode

Worktree + **subagent-driven development**: one subagent per Task 1–20, human/gate review between Phase 1→2→3→4→5→6. Do not promote to default until Task 18 says `leap_confirmed`.

---

## Self-review (plan quality)

- **Spec coverage:** all five promised leaps mapped to tasks; measurement + docs included.
- **Placeholders:** none intentional; Dev hook path confirmed via `rg` at execute time in Task 7.
- **Type consistency:** fusion uses `ref` + `source` ∈ {code,memory,reality}; rollout vocabulary matches `AtlasIntelligenceRolloutMode`.
- **Honest precondition:** Autônomos recorder wiring is **missing today** — Tasks 6–7 are mandatory for 100%, not optional polish.
