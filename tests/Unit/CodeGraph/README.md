# Code Graph unit tests (AP-815)

Characterization + contract tests for the `App\Services\Engineering\CodeGraph\*`
read models and helpers. They capture what the code-graph services **actually do**
(deterministic, fail-safe, bounded) so the engine can be refactored against a fixed
oracle.

## Running

Whole directory:

```bash
php -d memory_limit=3072M artisan test tests/Unit/CodeGraph
```

A single file:

```bash
php -d memory_limit=3072M artisan test tests/Unit/CodeGraph/CodeGraphPerformanceBudgetTest.php
```

> The base `Tests\TestCase` re-clamps `memory_limit` to the phpunit.xml baseline
> (`4096M`) at the start of every test, so an earlier test's in-process 512M/360s
> clamp can never poison these. The `-d memory_limit=3072M` above is the CLI
> bootstrap floor; phpunit.xml's `<ini name="memory_limit" value="4096M"/>` is what
> the test bodies actually see.

## The performance-budget tests (D-3)

`CodeGraphPerformanceBudgetTest` is a **CI tripwire**, not a benchmark. It builds a
large **deterministic** synthetic graph (50,000 edges over ~10,000 nodes, generated
by pure modular arithmetic — no `mt_rand`, no faker, no clock seeding) and asserts:

| Test | What it guards | Ceiling |
|---|---|---|
| `test_fromEdges_builds_large_graph_under_budget` | `fromEdges()` stays roughly linear | `< 5.0s` |
| `test_neighbors_batch_lookups_are_constant_time_under_budget` | `neighbors()` is O(1)-ish, not O(E) | `< 2.0s` for 10k lookups |
| `test_incoming_batch_lookups_are_constant_time_under_budget` | reverse map (blast-radius) is O(1)-ish | `< 2.0s` for 10k lookups |
| `test_node_ceiling_is_honored_at_scale` | the `DEFAULT_MAX_NODES` memory bound still bites | (bound, not timing) |
| `test_context_pack_assembler_packs_large_candidate_set_under_budget` | the packer stays single-pass linear | `< 2.0s` for 10k candidates |

### Why the ceilings are deliberately generous

A healthy build/query is **orders of magnitude** under these numbers (sub-second on a
laptop). The ceilings are set wide on purpose so that **micro-noise, GC pauses, and a
loaded CI runner cannot trip them** — only a true ≈10x+ algorithmic collapse can
(e.g. a lookup regressing to re-walk the whole edge set). If one of these ever goes
red, treat it as a real regression, not a flaky test: bisect the build/lookup path,
do not "fix" it by raising the ceiling unless the input size itself grew.

Each timing test also asserts a **correctness guard** (node count, non-empty lookups,
packed prefix) so a fast-but-broken implementation cannot pass on speed alone.

## The measurement tests (D-5 / D-6)

Two of the files here are **measurement** tests, not branch-coverage tests. They feed a
realistic corpus through a service and assert a **rate** clears a floor/ceiling. The
floors are the **actually measured** values (probed before the test was written), never
aspirational — if the service regresses, the number moves and the test fails loudly.

| File | Service under test | Bar | Measured (2026-06-09, PHP 8.5.5) |
|---|---|---|---|
| `CodeGraphSecretScannerRecallTest` (D-5) | `CodeGraphSecretScanner` | recall `>= 0.80`, FP rate `<= 0.10` | recall **18/18 = 1.00**, FP **0/15 = 0.00** |
| `CodeGraphEconomyFloorTest` (D-6) | `CodeGraphRetrievalCompressor` → AP-813 pipeline | output `<= 0.70 ×` input | log **0.0845**, json **0.3485**, pack **0.5656** |

Run just these two:

```bash
php -d memory_limit=3072M artisan test \
  tests/Unit/CodeGraph/CodeGraphSecretScannerRecallTest.php \
  tests/Unit/CodeGraph/CodeGraphEconomyFloorTest.php
```

### Honesty contract for these two

- The conservative floor/ceiling guard against a real regression; a second, **stricter**
  test (`test_measured_*`) pins the exact documented rate so a silent drift off the
  measured number is caught even while it stays above the loose floor — forcing the
  docblock to be re-measured, not left stale.
- `CodeGraphSecretScannerRecallTest::test_documents_known_detection_gaps` records — as
  executable facts — the scanner's three real blind spots (bare `sk_live_…`; compound
  assignment keys like `DB_PASSWORD=` / `STRIPE_SECRET=`; IP-host connection strings).
  These are the legacy's actual behaviour. If a rewrite **closes** a gap (strictly
  better), that test fails on purpose — update it to assert the new behaviour.
- `CodeGraphEconomyFloorTest` includes two **fail-open** cases (layer disabled →
  byte-identical passthrough; heterogeneous input → ratio 1.0) so the economy claim is
  never read as "free on any input": the compressors only shrink provably-redundant bulk
  and are lossless-by-governance (original recoverable from the CCR store).

### Adding a corpus case to a measurement test

- **Secret scanner (D-5):** add a `name => sample` entry to `positives()` or
  `negatives()`. Use a **synthetic** value in a **real on-disk format** (never a live
  credential). Re-run the file; if recall drops below the floor, **lower the floor to the
  newly measured value AND name the missed format in the class docblock** — do not pad the
  sample to fake a pass.
- **Economy floor (D-6):** add a `private function <name>Block(): string` that builds a
  realistic retrieval payload, plus a `test_<name>_meets_economy_floor()` asserting
  `compressed_chars / original_chars <= self::ECONOMY_CEILING`. If a content type does not
  clear 0.70, that is a true finding — assert its real ratio and say so in the docblock.

## Adding a new case

1. Pick the right file — one test class per `CodeGraph` service (mirrors
   `app/Services/Engineering/CodeGraph/`). New service → new
   `CodeGraph<Name>Test.php` in this directory.
2. Name the method as a specification: `test_<subject>_<expected_behaviour>()`.
3. Use **literal** inputs and **literal** expected outputs. No "should work" — assert
   the concrete value the code produces (the legacy/current code is the oracle; if it
   disagrees with a spec, assert what it does and flag the discrepancy separately).
4. Cover every branch the service has, plus boundaries (empty, zero, negative, max,
   malformed) — these services are contractually fail-safe, so prove it.
5. For a **performance** case: generate the fixture **deterministically** (index math,
   never randomness), time **only** the operation under test with `microtime(true)`,
   assert a **generous literal** ceiling that guards against a ~10x regression (not
   micro-noise), and add a correctness assertion alongside the timing.
6. A behaviour not yet implemented in the target gets `$this->markTestSkipped('pending
   RULE-NNN')` — never a deleted or commented-out test.
