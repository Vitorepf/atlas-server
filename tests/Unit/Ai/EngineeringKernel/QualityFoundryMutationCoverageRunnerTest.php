<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryMutationCoverageRunner;
use App\Services\Ai\Programming\AtlasDev\Mutation\PerFileMutationStats;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Mutation\FakeMutationCommandRunner;

final class QualityFoundryMutationCoverageRunnerTest extends TestCase
{
    public function test_runner_executes_and_aggregates_one_real_receipt_per_canonical_surface(): void
    {
        $sourceFiles = [
            'app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php',
            'app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php',
            'app/Services/Ai/SelfConstruction/AtlasTaskServingService.php',
            'app/Services/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedSupervisorCycle.php',
            'app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php',
            'app/Services/Ai/EngineeringKernel/MergeActuator.php',
        ];
        $runner = new FakeMutationCommandRunner;
        foreach ($sourceFiles as $index => $source) {
            $runner->queueOk(
                msi: 100.0,
                summaryPath: '/tmp/quality-foundry-surface-'.$index.'.json',
                perFileStats: [new PerFileMutationStats(base_path($source), 100.0, 1, 1)],
            );
        }

        $evidence = (new QualityFoundryMutationCoverageRunner(base_path(), $runner))
            ->run('quality-foundry-surface-test');

        self::assertSame('observed', $evidence['status']);
        self::assertSame(EngineeringExecutionSurfaceRegistry::ids(), $evidence['registered_mutation_surfaces']);
        $expectedTested = EngineeringExecutionSurfaceRegistry::ids();
        sort($expectedTested, SORT_STRING);
        self::assertSame($expectedTested, $evidence['tested_mutation_surfaces']);
        self::assertSame(6, $evidence['total_mutants']);
        self::assertSame(6, $evidence['killed_mutants']);
        self::assertSame(0, $evidence['surviving_mutants']);
        self::assertSame(100.0, $evidence['mutation_score_percent']);
        self::assertCount(6, $runner->calls);
    }
}
