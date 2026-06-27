<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCoverageMatrix;
use Tests\TestCase;

final class AtlasBrainCoverageMatrixTest extends TestCase
{
    public function test_coverage_by_path_covers_all_seven_portfolio_paths(): void
    {
        $cov = (new AtlasBrainCoverageMatrix)->coverageByPath();
        foreach (['frontier-harvest', 'metrics-optimization', 'pattern-design', 'simulation-twin', 'comprehension-deepening', 'adversarial-critique', 'compounding'] as $path) {
            self::assertArrayHasKey($path, $cov);
            self::assertGreaterThan(0, $cov[$path], "$path has zero organs");
        }
    }

    public function test_underbuilt_paths_returns_those_below_threshold(): void
    {
        $under = (new AtlasBrainCoverageMatrix)->underbuiltPaths(3);
        // adversarial-critique=2, compounding=2, metrics-optimization=2, pattern-design=2 should be flagged at threshold 3
        self::assertContains('adversarial-critique', $under);
        self::assertContains('compounding', $under);
        self::assertContains('metrics-optimization', $under);
        self::assertContains('pattern-design', $under);
    }

    public function test_matrix_classes_all_exist(): void
    {
        foreach ((new AtlasBrainCoverageMatrix)->matrix() as $path => $organs) {
            foreach ($organs as $organ) {
                self::assertTrue(class_exists($organ), "$organ class for $path must exist");
            }
        }
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCoverageMatrix.php',
                true
            )
        );
    }
}
