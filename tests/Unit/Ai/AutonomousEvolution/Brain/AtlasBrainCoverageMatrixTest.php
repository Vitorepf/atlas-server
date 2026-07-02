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
        // Prova o MECANISMO (count < threshold ⇒ flagged) derivando as expectativas do
        // próprio matrix — contagens hardcoded apodrecem toda vez que um organ novo nasce
        // (a versão anterior pinava compounding=2 de uma era em que todos os paths tinham 6+).
        $matrix = new AtlasBrainCoverageMatrix;
        $cov = $matrix->coverageByPath();

        self::assertSame([], $matrix->underbuiltPaths(0), 'nada fica abaixo de zero');
        self::assertSame(
            array_keys($cov),
            array_values($matrix->underbuiltPaths(max($cov) + 1)),
            'threshold acima do máximo flagra todos os paths'
        );

        $threshold = max($cov); // paths estritamente abaixo do máximo são flagrados
        $under = $matrix->underbuiltPaths($threshold);
        foreach ($cov as $path => $count) {
            $count < $threshold
                ? self::assertContains($path, $under, "$path ($count) abaixo de $threshold")
                : self::assertNotContains($path, $under, "$path ($count) não está abaixo de $threshold");
        }
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
