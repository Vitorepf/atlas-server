<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainComposedSelfKnowledgeReport;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCoverageMatrix;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierMethodCatalog;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOrganDependencyGraph;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSchemaContractRegistry;
use Tests\TestCase;

final class AtlasBrainComposedSelfKnowledgeReportTest extends TestCase
{
    public function test_build_consolidates_all_layers(): void
    {
        $report = new AtlasBrainComposedSelfKnowledgeReport(
            new AtlasBrainCoverageMatrix,
            new AtlasBrainOrganDependencyGraph,
            new AtlasBrainFrontierMethodCatalog,
            new AtlasBrainSchemaContractRegistry,
        );
        $out = $report->build();
        self::assertSame(AtlasBrainComposedSelfKnowledgeReport::SCHEMA, $out['schema']);
        self::assertNotEmpty($out['coverage']);
        self::assertNotEmpty($out['dependency_graph']);
        self::assertArrayHasKey('total', $out['frontier']);
        self::assertGreaterThan(0, $out['schema_count']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainComposedSelfKnowledgeReport.php',
                true
            )
        );
    }
}
