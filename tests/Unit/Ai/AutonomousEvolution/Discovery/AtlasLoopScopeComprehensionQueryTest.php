<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionQuery;
use App\Services\Ai\AutonomousEvolution\Discovery\ScopeComprehensionQuery;
use App\Services\Ai\AutonomousEvolution\Discovery\ScopeRuntimeFacts;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PART 1 · P1-A — the memoizing comprehension QUERY front-door.
 *
 * The win this pins: a single refill used to call the builder's ~9s `build()` once PER supply lane (dedup,
 * orphan-wiring, doc-gap) for the SAME scope — three identical builds. The query shares ONE memoized model
 * per (scopeRoot, opts) for the lifetime of one refill, so identical-input lanes pay the build cost once.
 *
 * The PÉTREO guard this protects: the query is a pure pass-through cache. The model it serves is BYTE-IDENTICAL
 * to a fresh `build()` (the characterization test) — the query adds no field, drops no field, and emits no
 * scalar/rank of its own. Caching can never become a behaviour change.
 */
final class AtlasLoopScopeComprehensionQueryTest extends TestCase
{
    private function fixtureRoot(): string
    {
        return base_path('tests/Fixtures/loop-comprehension-scope');
    }

    private function query(array $opts = ['docs_roots' => ['docs']]): AtlasLoopScopeComprehensionQuery
    {
        return new AtlasLoopScopeComprehensionQuery(new AtlasLoopScopeComprehensionModelBuilder, $this->fixtureRoot(), $opts);
    }

    /** A query that has already comprehended app/Scope — the honest order: build comprehension, then ask. */
    private function comprehended(): AtlasLoopScopeComprehensionQuery
    {
        $q = $this->query();
        $q->model('app/Scope');

        return $q;
    }

    public function test_model_is_byte_identical_to_a_fresh_build_characterization(): void
    {
        $opts = ['docs_roots' => ['docs']];
        $fresh = (new AtlasLoopScopeComprehensionModelBuilder)->build($this->fixtureRoot(), 'app/Scope', $opts);

        $served = (new AtlasLoopScopeComprehensionQuery(new AtlasLoopScopeComprehensionModelBuilder, $this->fixtureRoot(), $opts))
            ->model('app/Scope');

        // The query must NEVER alter the comprehension model — full serialization AND the structural
        // projection are byte-identical to a fresh build (anti-Goodhart: caching adds/drops nothing).
        $this->assertSame($fresh->toArray(), $served->toArray());
        $this->assertSame($fresh->structuralProjection(), $served->structuralProjection());
        $this->assertSame($fresh->snapshotId, $served->snapshotId);
    }

    public function test_repeated_model_calls_for_the_same_scope_build_once(): void
    {
        $query = $this->query();

        $a = $query->model('app/Scope');
        $b = $query->model('app/Scope');
        $c = $query->model('/app/Scope/'); // path-normalized to the same key

        // ONE build, the SAME instance handed back every time — the 3-builds-per-refill collapse.
        $this->assertSame(1, $query->buildCount(), 'identical (scopeRoot, opts) must build exactly once');
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function test_distinct_scope_roots_are_not_collapsed(): void
    {
        $query = $this->query();

        $query->model('app/Scope');
        $query->model('app/Wiring');

        $this->assertSame(2, $query->buildCount(), 'a different scope root is a different model — never a stale cache hit');
    }

    public function test_distinct_opts_are_not_collapsed_so_doc_gap_lane_keeps_its_own_model(): void
    {
        // The doc-gap lane builds WITH docs_roots; the dedup/orphan lanes build WITHOUT. They are different
        // models (different doc_stated_gaps) and must NOT share a cache entry — otherwise the doc-gap lane
        // would silently see the empty-docs model and mint nothing.
        $withDocs = new AtlasLoopScopeComprehensionQuery(new AtlasLoopScopeComprehensionModelBuilder, $this->fixtureRoot(), ['docs_roots' => ['docs']]);
        $noDocs = new AtlasLoopScopeComprehensionQuery(new AtlasLoopScopeComprehensionModelBuilder, $this->fixtureRoot(), ['docs_roots' => []]);

        $this->assertNotEmpty($withDocs->model('app/Scope')->docStatedGaps, 'docs_roots build detects the stated gap');
        $this->assertSame([], $noDocs->model('app/Scope')->docStatedGaps, 'empty-docs build has no gaps — a genuinely different model');
    }

    // --- Item 2: the ScopeComprehensionQuery contract (interface + staleness) -----------------------------

    public function test_query_implements_the_scope_comprehension_contract(): void
    {
        $this->assertInstanceOf(ScopeComprehensionQuery::class, $this->query());
    }

    public function test_staleness_of_a_freshly_built_scope_is_not_stale(): void
    {
        $root = $this->copyFixture();
        $query = new AtlasLoopScopeComprehensionQuery(new AtlasLoopScopeComprehensionModelBuilder, $root, ['docs_roots' => ['docs']]);
        $query->model('app/Scope');

        $st = $query->staleness('app/Scope');
        $this->assertSame(['stale', 'changed_units', 'snapshot_age_s'], array_keys($st), 'exactly the contract keys');
        $this->assertFalse($st['stale'], 'nothing changed on disk since the build');
        $this->assertSame([], $st['changed_units']);
        $this->assertIsInt($st['snapshot_age_s']);
        $this->assertGreaterThanOrEqual(0, $st['snapshot_age_s']);
    }

    public function test_staleness_bites_when_a_scope_file_changes_after_the_build(): void
    {
        $root = $this->copyFixture();
        $query = new AtlasLoopScopeComprehensionQuery(new AtlasLoopScopeComprehensionModelBuilder, $root, ['docs_roots' => ['docs']]);
        $query->model('app/Scope');

        // Touch a scope file to a future mtime — the live scope drifted past the snapshot.
        touch($root.'/app/Scope/Callee.php', time() + 120);

        $st = $query->staleness('app/Scope');
        $this->assertTrue($st['stale'], 'a changed scope file makes the memoized snapshot stale (never served silently)');
        $this->assertContains('app/Scope/Callee.php', $st['changed_units']);
    }

    public function test_staleness_of_a_never_built_scope_reports_stale(): void
    {
        // No model built yet => there is no fresh snapshot to serve => honestly stale.
        $st = $this->query()->staleness('app/Scope');
        $this->assertTrue($st['stale']);
        $this->assertSame([], $st['changed_units']);
        $this->assertSame(0, $st['snapshot_age_s']);
    }

    // --- Item 3 (structural part): level_vector booleans + the fixed fact->transition map ------------------

    public function test_level_vector_is_only_booleans_no_scalar_rank(): void
    {
        $lv = $this->comprehended()->levelVector('App\\Scope\\Orphan');
        $this->assertSame(
            ['has_test', 'gate_clean', 'orphan', 'in_clone', 'doc_gap_open', 'last_merge_clean'],
            array_keys($lv),
            'the exact level_vector fields, in order',
        );
        foreach ($lv as $field => $value) {
            $this->assertIsBool($value, "level_vector.$field must be a boolean (FACTS, never a number)");
        }
    }

    public function test_level_vector_structural_facts_match_the_model(): void
    {
        $q = $this->comprehended();

        $orphan = $q->levelVector('App\\Scope\\Orphan');
        $this->assertTrue($orphan['orphan']);
        $this->assertFalse($orphan['in_clone']);
        $this->assertFalse($orphan['doc_gap_open']);

        $clone = $q->levelVector('App\\Scope\\CloneOne');
        $this->assertTrue($clone['in_clone']);
        $this->assertFalse($clone['orphan']);

        // A doc-stated gap (named by the docs, no symbol provides it) is an open gap.
        $gap = $q->levelVector('App\\Scope\\MissingCapability');
        $this->assertTrue($gap['doc_gap_open']);
        $this->assertFalse($gap['orphan']);
        $this->assertFalse($gap['in_clone']);
    }

    public function test_transitions_for_an_orphan_name_the_orphan_to_wired_transition(): void
    {
        $tr = $this->comprehended()->transitionsFor('App\\Scope\\Orphan');
        $names = array_column($tr, 'transition');
        $this->assertContains('orphan->wired', $names);

        // Every transition carries a from_fact + grounded evidence; NONE carries a score/rank (anti-Goodhart:
        // the substrate says WHICH transitions exist, never which is worth more).
        foreach ($tr as $t) {
            $this->assertSame(['transition', 'from_fact', 'evidence'], array_keys($t));
            $this->assertArrayNotHasKey('score', $t);
        }
        $orphanT = array_values(array_filter($tr, static fn (array $t): bool => $t['transition'] === 'orphan->wired'))[0];
        $this->assertSame('orphan', $orphanT['from_fact']);
    }

    public function test_transitions_for_a_clone_member_name_the_clone_to_unified_transition(): void
    {
        $names = array_column($this->comprehended()->transitionsFor('App\\Scope\\CloneOne'), 'transition');
        $this->assertContains('clone->unified', $names);
    }

    public function test_transitions_for_a_doc_gap_name_the_gap_to_satisfied_transition(): void
    {
        $names = array_column($this->comprehended()->transitionsFor('App\\Scope\\MissingCapability'), 'transition');
        $this->assertContains('gap->satisfied', $names);
    }

    public function test_transitions_for_a_healthy_wired_class_emit_no_structural_transition(): void
    {
        // Callee is wired (not orphan), not a clone, not a gap => no orphan/clone/gap transition.
        $names = array_column($this->comprehended()->transitionsFor('App\\Scope\\Callee'), 'transition');
        $this->assertNotContains('orphan->wired', $names);
        $this->assertNotContains('clone->unified', $names);
        $this->assertNotContains('gap->satisfied', $names);
    }

    // --- Item 4: runtime facts wire gate_clean / last_merge_clean (+ their transitions) -------------------

    /** A fake ScopeRuntimeFacts so the runtime wiring is provable with NO database. */
    private function fakeFacts(array $gateBlocked, array $mergeClean): ScopeRuntimeFacts
    {
        return new class($gateBlocked, $mergeClean) implements ScopeRuntimeFacts
        {
            public function __construct(private array $gateBlocked, private array $mergeClean) {}

            public function hasGateBlock(string $relPath): bool
            {
                return in_array($relPath, $this->gateBlocked, true);
            }

            public function lastMergeClean(string $relPath): bool
            {
                return in_array($relPath, $this->mergeClean, true);
            }
        };
    }

    public function test_runtime_facts_flip_gate_clean_and_emit_regressed_to_green(): void
    {
        $facts = $this->fakeFacts(['app/Scope/Callee.php'], ['app/Scope/Caller.php']);
        $q = new AtlasLoopScopeComprehensionQuery(new AtlasLoopScopeComprehensionModelBuilder, $this->fixtureRoot(), ['docs_roots' => ['docs']], $facts);
        $q->model('app/Scope');

        // gate-blocked file => gate_clean FALSE => the regressed->green transition fires (the re-green lane).
        $callee = $q->levelVector('App\\Scope\\Callee');
        $this->assertFalse($callee['gate_clean']);
        $this->assertContains('regressed->green', array_column($q->transitionsFor('App\\Scope\\Callee'), 'transition'));

        // merge-clean file => last_merge_clean TRUE (informational; not a transition trigger).
        $caller = $q->levelVector('App\\Scope\\Caller');
        $this->assertTrue($caller['last_merge_clean']);

        // a file with neither runtime fact keeps the safe defaults.
        $orphan = $q->levelVector('App\\Scope\\Orphan');
        $this->assertTrue($orphan['gate_clean']);
        $this->assertFalse($orphan['last_merge_clean']);
    }

    public function test_without_runtime_facts_the_runtime_fields_degrade_safely(): void
    {
        // No runtime source => gate_clean true (no spurious regressed->green), last_merge_clean false.
        $q = $this->comprehended();
        $lv = $q->levelVector('App\\Scope\\Callee');
        $this->assertTrue($lv['gate_clean']);
        $this->assertFalse($lv['last_merge_clean']);
        $this->assertNotContains('regressed->green', array_column($q->transitionsFor('App\\Scope\\Callee'), 'transition'));
    }

    /** @var list<string> */
    private array $tmp = [];

    /** A throwaway copy of the fixture (never mutate the committed fixture). */
    private function copyFixture(): string
    {
        $dst = sys_get_temp_dir().'/atlas-comp-query-'.bin2hex(random_bytes(5));
        $this->tmp[] = $dst;
        (new Process(['cp', '-R', $this->fixtureRoot(), $dst]))->run();

        return $dst;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }
}
