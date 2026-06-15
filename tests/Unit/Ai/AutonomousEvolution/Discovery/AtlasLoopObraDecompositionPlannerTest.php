<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraDecompositionPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Lever 1 — the decomposition planner. Iterate-to-READY against the REAL readiness gate (structural
 * validator + per-node spec + pre-verified acceptance): an impeccable DAG is returned ready; a vague one
 * REPLANs (gaps fed back); junk is refused without spending execution budget.
 */
final class AtlasLoopObraDecompositionPlannerTest extends TestCase
{
    /** @return array<string,mixed> */
    private function validPlan(): array
    {
        return [
            'plan_id' => 'split-god-service',
            'nodes' => [
                [
                    'id' => 'n1',
                    'request' => 'Extract the validation cluster from app/Services/Foo.php into a cohesive new helper class',
                    'target_area' => 'app/Services/Foo.php',
                    'acceptance' => ['commands' => ['./vendor/bin/phpunit tests/FooTest.php']],
                ],
                [
                    'id' => 'n2',
                    'request' => 'Delegate from app/Services/Bar.php to the helper extracted in node n1, keeping behaviour identical',
                    'target_area' => 'app/Services/Bar.php',
                    'depends_on' => ['n1'],
                    'acceptance' => ['commands' => ['./vendor/bin/phpunit tests/BarTest.php']],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function vaguePlan(): array
    {
        return [
            'plan_id' => 'vague',
            'nodes' => [
                ['id' => 'a', 'request' => 'fix it', 'target_area' => 'app/Services/Foo.php'],
                ['id' => 'b', 'request' => 'do stuff', 'target_area' => 'app/Services/Bar.php'],
            ],
        ];
    }

    private function allowed(): array
    {
        return ['app/Services/Foo.php', 'app/Services/Bar.php'];
    }

    public function test_returns_ready_when_the_first_plan_is_impeccable(): void
    {
        $planner = new AtlasLoopObraDecompositionPlanner;
        $out = $planner->plan('Split the god service', [], $this->allowed(), fn () => $this->validPlan(), 3);

        $this->assertTrue($out['ready'], json_encode($out['assessment']));
        $this->assertSame(1, $out['attempts']);
        $this->assertSame('implement', $out['assessment']['decision']);
        $this->assertSame('split-god-service', $out['plan']['plan_id']);
        $this->assertCount(2, $out['plan']['nodes']);
    }

    public function test_iterates_to_ready_feeding_gaps_back_on_replan(): void
    {
        $calls = 0;
        $gapsSeenOnSecond = [];
        $planner = new AtlasLoopObraDecompositionPlanner;
        $out = $planner->plan('Split the god service', [], $this->allowed(), function (string $goal, array $ctx, array $priorGaps) use (&$calls, &$gapsSeenOnSecond) {
            $calls++;
            if ($calls === 1) {
                return $this->vaguePlan(); // first try is vague -> REPLAN
            }
            $gapsSeenOnSecond = $priorGaps; // the generator receives the exact gaps to fix

            return $this->validPlan();
        }, 3);

        $this->assertTrue($out['ready']);
        $this->assertSame(2, $out['attempts'], 'replanned once after the vague first attempt');
        $this->assertNotEmpty($gapsSeenOnSecond, 'the gaps from the rejected plan are fed back to the generator');
    }

    public function test_refuses_after_budget_when_plan_never_becomes_ready(): void
    {
        $planner = new AtlasLoopObraDecompositionPlanner;
        $out = $planner->plan('Split the god service', [], $this->allowed(), fn () => $this->vaguePlan(), 2);

        $this->assertFalse($out['ready'], 'a never-ready plan is refused — no execution budget spent');
        $this->assertSame(2, $out['attempts']);
        $this->assertNull($out['plan']);
        $this->assertNotEmpty($out['gaps']);
    }

    public function test_refuses_a_plan_that_targets_a_petreous_file(): void
    {
        // Even a well-specified node may not decompose ONTO the loop's own gates (pétreo). The validator
        // (inside the readiness gate) refuses it -> never ready.
        $planner = new AtlasLoopObraDecompositionPlanner;
        $petreous = [
            'plan_id' => 'attack-the-judge',
            'nodes' => [
                [
                    'id' => 'n1',
                    'request' => 'Modify app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php to relax the gate',
                    'target_area' => 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
                    'acceptance' => ['commands' => ['./vendor/bin/phpunit tests/X.php']],
                ],
                [
                    'id' => 'n2',
                    'request' => 'Delegate from app/Services/Bar.php to the change made in node n1 keeping behaviour identical',
                    'target_area' => 'app/Services/Bar.php',
                    'depends_on' => ['n1'],
                    'acceptance' => ['commands' => ['./vendor/bin/phpunit tests/BarTest.php']],
                ],
            ],
        ];
        $allowed = ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php', 'app/Services/Bar.php'];
        $out = $planner->plan('attack', [], $allowed, fn () => $petreous, 1);

        $this->assertFalse($out['ready'], 'the defendant can never decompose onto the judge');
        $this->assertNotEmpty($out['gaps']);
    }
}
