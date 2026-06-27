<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainNextCycleProjector;
use Tests\TestCase;

final class AtlasBrainNextCycleProjectorTest extends TestCase
{
    public function test_approves_uses_recommended_path(): void
    {
        $r = (new AtlasBrainNextCycleProjector)->project(
            'compounding',
            ['verdict' => 'approve', 'recommended_path' => 'compounding', 'alternative_path' => null, 'reason' => 'yield_ok'],
            ['compounding' => ['trend' => 'improving', 'delta' => 0.2]],
        );
        self::assertSame('compounding', $r['projected_path']);
        self::assertSame('plan_adviser', $r['source']);
        self::assertSame('high', $r['confidence']);
    }

    public function test_veto_uses_alternative_path(): void
    {
        $r = (new AtlasBrainNextCycleProjector)->project(
            'frontier-harvest',
            ['verdict' => 'veto', 'recommended_path' => 'frontier-harvest', 'alternative_path' => 'simulation-twin', 'reason' => 'low_yield'],
            ['simulation-twin' => ['trend' => 'declining', 'delta' => -0.3]],
        );
        self::assertSame('simulation-twin', $r['projected_path']);
        self::assertSame('red_team_alternative', $r['source']);
        self::assertSame('low', $r['confidence']);
    }

    public function test_confidence_medium_when_path_missing_from_momentum(): void
    {
        $r = (new AtlasBrainNextCycleProjector)->project(
            'pattern-design',
            ['verdict' => 'veto', 'recommended_path' => 'pattern-design', 'alternative_path' => 'metrics-optimization', 'reason' => 'low_yield'],
            [],
        );
        self::assertSame('medium', $r['confidence']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainNextCycleProjector.php',
                true
            )
        );
    }
}
