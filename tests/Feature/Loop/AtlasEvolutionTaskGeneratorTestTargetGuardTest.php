<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MATERIAL-ONLY TARGETS (proxy-drift fix, 2026-06-23). The objective-producer must originate
 * behaviour-changing PRODUCTION work, never coverage. Live soak drift: 3 of 5 tasks targeted *Test.php and
 * emitted "Assert that …" proposals — adding test assertions = characterization/coverage = PROXY (forbidden).
 * Worse, since the generator stamps red_required, such a task would launder coverage as a real bug_fix in the
 * scorecard. The generator now REFUSES a test-file target before the model is ever invoked.
 */
final class AtlasEvolutionTaskGeneratorTestTargetGuardTest extends TestCase
{
    private function generator(): AtlasEvolutionTaskGenerator
    {
        return app(AtlasEvolutionTaskGenerator::class);
    }

    /**
     * @return list<array{0:string}>
     */
    public static function provideTestFilePaths(): array
    {
        return [
            ['tests/Feature/Ai/Obra/AtlasObraExecutorTest.php'],
            ['tests/Unit/SomethingTest.php'],
            ['app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBugReproductionLaneTest.php'],
            ['src/AtlasObraExecutorTest.php'],
        ];
    }

    #[DataProvider('provideTestFilePaths')]
    public function test_a_test_file_target_is_refused_before_the_model_runs(string $testPath): void
    {
        $res = $this->generator()->generateForTarget(sys_get_temp_dir().'/atlas-nonexistent-base', $testPath);

        $this->assertFalse($res['generated'], "a test file target must not generate material work: {$testPath}");
        $this->assertSame('test_file_target_not_material', $res['reason']);
    }

    public function test_a_production_target_passes_the_test_guard(): void
    {
        // It is NOT refused by the test guard (it fails later for a missing base, proving the guard let it
        // through rather than short-circuiting on the test-file reason).
        $res = $this->generator()->generateForTarget(sys_get_temp_dir().'/atlas-nonexistent-base', 'app/Services/Ai/AutonomousEvolution/AtlasLoopConfidenceCalibrator.php');

        $this->assertNotSame('test_file_target_not_material', $res['reason'] ?? null, 'a production target is not blocked by the test guard');
    }
}
