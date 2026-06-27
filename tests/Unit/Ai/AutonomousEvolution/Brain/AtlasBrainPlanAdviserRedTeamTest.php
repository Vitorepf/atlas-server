<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPlanAdviserRedTeam;
use Tests\TestCase;

final class AtlasBrainPlanAdviserRedTeamTest extends TestCase
{
    private const PORTFOLIO = ['frontier-harvest', 'metrics-optimization', 'pattern-design', 'simulation-twin', 'comprehension-deepening', 'adversarial-critique', 'compounding'];

    public function test_approves_when_history_insufficient(): void
    {
        $r = (new AtlasBrainPlanAdviserRedTeam)->review('frontier-harvest', [], self::PORTFOLIO);
        self::assertSame('approve', $r['verdict']);
        self::assertSame('insufficient_history', $r['reason']);
    }

    public function test_approves_when_yield_above_threshold(): void
    {
        $rollup = ['frontier-harvest' => ['accepted' => 4, 'refused' => 6, 'total' => 10]];
        $r = (new AtlasBrainPlanAdviserRedTeam)->review('frontier-harvest', $rollup, self::PORTFOLIO);
        self::assertSame('approve', $r['verdict']);
    }

    public function test_vetoes_when_yield_below_threshold(): void
    {
        $rollup = [
            'frontier-harvest' => ['accepted' => 1, 'refused' => 19, 'total' => 20],
            'compounding' => ['accepted' => 5, 'refused' => 5, 'total' => 10],
        ];
        $r = (new AtlasBrainPlanAdviserRedTeam)->review('frontier-harvest', $rollup, self::PORTFOLIO);
        self::assertSame('veto', $r['verdict']);
        self::assertNotNull($r['alternative_path']);
        self::assertNotSame('frontier-harvest', $r['alternative_path']);
    }

    public function test_alternative_prefers_least_sampled_path(): void
    {
        $rollup = [
            'frontier-harvest' => ['accepted' => 0, 'refused' => 10, 'total' => 10],
            'compounding' => ['accepted' => 5, 'refused' => 5, 'total' => 10],
            'pattern-design' => ['accepted' => 1, 'refused' => 1, 'total' => 2],
        ];
        $r = (new AtlasBrainPlanAdviserRedTeam)->review('frontier-harvest', $rollup, self::PORTFOLIO);
        // simulation-twin/metrics-optimization/etc have 0 samples, must be picked over compounding(10) or pattern-design(2)
        self::assertContains($r['alternative_path'], ['metrics-optimization', 'simulation-twin', 'comprehension-deepening', 'adversarial-critique']);
    }

    public function test_red_team_organ_is_a_petreo_forbidden_self_target(): void
    {
        $v = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPlanAdviserRedTeam.php',
            true
        );
        self::assertSame('forbidden', $v);
    }
}
