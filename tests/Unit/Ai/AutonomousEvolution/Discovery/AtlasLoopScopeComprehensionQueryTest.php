<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionQuery;
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
}
