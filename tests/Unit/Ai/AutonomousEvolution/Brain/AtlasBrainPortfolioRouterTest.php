<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPortfolioRouter;
use Tests\TestCase;

/**
 * Frozen contract for the portfolio router — proves the deterministic signal→path mapping, the priority order
 * when multiple signals are present (orphans dominates clones dominates docStatedGaps), the empty digest ⇒ null
 * (never force a path), and that the organ is pétreo (same priorizador principle as NextWorkDecider).
 */
final class AtlasBrainPortfolioRouterTest extends TestCase
{
    public function test_orphans_route_to_comprehension_deepening(): void
    {
        $r = (new AtlasBrainPortfolioRouter)->route(['orphans' => ['App\\Foo'], 'clone_clusters' => [], 'doc_stated_gaps' => []]);

        self::assertSame(AtlasBrainPortfolioRouter::PATH_COMPREHENSION_DEEPENING, $r['recommended_path']);
        self::assertSame('orphans', $r['signal_class']);
    }

    public function test_clone_clusters_route_to_pattern_design(): void
    {
        $r = (new AtlasBrainPortfolioRouter)->route(['orphans' => [], 'clone_clusters' => ['c1'], 'doc_stated_gaps' => []]);

        self::assertSame(AtlasBrainPortfolioRouter::PATH_PATTERN_DESIGN, $r['recommended_path']);
        self::assertSame('clone_clusters', $r['signal_class']);
    }

    public function test_doc_stated_gaps_route_to_frontier_harvest(): void
    {
        $r = (new AtlasBrainPortfolioRouter)->route(['orphans' => [], 'clone_clusters' => [], 'doc_stated_gaps' => ['auth has no integration test']]);

        self::assertSame(AtlasBrainPortfolioRouter::PATH_FRONTIER_HARVEST, $r['recommended_path']);
        self::assertSame('doc_stated_gaps', $r['signal_class']);
    }

    public function test_priority_orphans_beats_clones_beats_gaps(): void
    {
        $allThree = ['orphans' => ['App\\Foo'], 'clone_clusters' => ['c1'], 'doc_stated_gaps' => ['x']];
        self::assertSame('orphans', (new AtlasBrainPortfolioRouter)->route($allThree)['signal_class']);

        $clonesAndGaps = ['orphans' => [], 'clone_clusters' => ['c1'], 'doc_stated_gaps' => ['x']];
        self::assertSame('clone_clusters', (new AtlasBrainPortfolioRouter)->route($clonesAndGaps)['signal_class']);
    }

    public function test_empty_digest_recommends_null(): void
    {
        $r = (new AtlasBrainPortfolioRouter)->route(['orphans' => [], 'clone_clusters' => [], 'doc_stated_gaps' => []]);

        self::assertNull($r['recommended_path']);
        self::assertNull($r['signal_class']);
    }

    public function test_signal_strength_counts_present_axes(): void
    {
        $router = new AtlasBrainPortfolioRouter;
        self::assertSame(0, $router->route(['orphans' => [], 'clone_clusters' => [], 'doc_stated_gaps' => []])['signal_strength']);
        self::assertSame(1, $router->route(['orphans' => ['A']])['signal_strength']);
        self::assertSame(2, $router->route(['orphans' => ['A'], 'clone_clusters' => ['c1']])['signal_strength']);
        self::assertSame(3, $router->route(['orphans' => ['A'], 'clone_clusters' => ['c1'], 'doc_stated_gaps' => ['g']])['signal_strength']);
    }

    public function test_router_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPortfolioRouter.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
