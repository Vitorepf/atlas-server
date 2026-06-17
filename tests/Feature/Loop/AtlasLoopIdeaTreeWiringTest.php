<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopConstraintsBlockAssembler;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHypothesisTreeProducer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSelectAdjuster;
use Tests\TestCase;

/**
 * ARBOR-GRAFT WIRING — frozen proof that the AppServiceProvider binding for AtlasLoopQueueRefiller
 * actually CONSTRUCTS the idea-tree advisory trio (args 14-16). Before the fix the binding closure
 * stopped at arg 13 (the work-class prior), so SELECT re-rank, the constraints-block, and tree
 * materialization defaulted to null and were DEAD in production regardless of their flags.
 *
 * This is a pure container-resolution + reflection test (no DB, no flags). The three producers are
 * read-only ADVISORY (walled off from every gate by AtlasLoopAdvisoryFirewallTest), so binding them
 * is byte-identical until the operator arms the flags — but they MUST be non-null for the flags to
 * have any effect at all.
 */
final class AtlasLoopIdeaTreeWiringTest extends TestCase
{
    private function prop(object $obj, string $name): mixed
    {
        $p = new \ReflectionProperty($obj, $name);
        $p->setAccessible(true);

        return $p->getValue($obj);
    }

    public function test_container_binding_constructs_the_idea_tree_trio(): void
    {
        $refiller = app(AtlasLoopQueueRefiller::class);

        $this->assertInstanceOf(
            AtlasLoopSelectAdjuster::class,
            $this->prop($refiller, 'selectAdjuster'),
            'arg 14 (SELECT re-rank) must be wired by the AppServiceProvider binding',
        );
        $this->assertInstanceOf(
            AtlasLoopConstraintsBlockAssembler::class,
            $this->prop($refiller, 'constraintsBlockAssembler'),
            'arg 15 (constraints-block) must be wired by the AppServiceProvider binding',
        );
        $this->assertInstanceOf(
            AtlasLoopHypothesisTreeProducer::class,
            $this->prop($refiller, 'treeProducer'),
            'arg 16 (hypothesis-tree producer) must be wired by the AppServiceProvider binding',
        );
    }

    public function test_pre_existing_args_remain_wired(): void
    {
        // Regression guard: appending args 14-16 must not have disturbed args 6-13. Spot-check the
        // two that anchor the live lanes (harness guard + next-work decider).
        $refiller = app(AtlasLoopQueueRefiller::class);

        $this->assertNotNull($this->prop($refiller, 'harnessGuard'), 'arg 7 (harness guard) still wired');
        $this->assertNotNull($this->prop($refiller, 'nextWorkDecider'), 'arg 12 (next-work decider) still wired');
        $this->assertNotNull($this->prop($refiller, 'workClassPrior'), 'arg 13 (work-class prior) still wired');
    }
}
