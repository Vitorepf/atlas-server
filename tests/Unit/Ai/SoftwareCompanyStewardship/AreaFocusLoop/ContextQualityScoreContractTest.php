<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Context\AtlasContextQualityCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ContextQualityScoreContract;
use Tests\TestCase;

final class ContextQualityScoreContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ContextQualityScoreContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(ContextQualityScoreContract::class));
    }

    public function test_default_shape_declares_context_quality_score_input_seam(): void
    {
        $shape = ContextQualityScoreContract::defaults()->toArray();

        $this->assertSame(ContextQualityScoreContract::SCHEMA, $shape['schema_version']);
        $this->assertSame(AtlasContextQualityCertificationService::SCHEMA_VERSION, $shape['certification_schema']);
        $this->assertSame('context_memory_retrieval_gap', $shape['finding_kind_context_memory_retrieval_gap']);
        $this->assertSame(9.8, $shape['default_target_score']);
        $this->assertSame(25, $shape['priority_boost_when_degraded']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
            $shape['gap_matrix_canonical'],
        );
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'certification_quality_score' => 9.8,
            'certification_target_score' => 9.8,
            'certification_status' => 'ready',
            'finding_kind' => '',
        ], $shape['inputs']);
        $this->assertSame(9.8, $shape['outputs']['context_quality_score']);
        $this->assertFalse($shape['outputs']['context_quality_degraded']);
        $this->assertFalse($shape['outputs']['boosts_context_memory_retrieval_gap_finding']);
        $this->assertSame(0, $shape['outputs']['priority_boost_points']);
    }

    public function test_from_array_boosts_context_gap_finding_when_certification_degraded_by_score(): void
    {
        $shape = ContextQualityScoreContract::fromArray([
            'certification_quality_score' => 8.5,
            'certification_target_score' => 9.8,
            'certification_status' => 'ready',
            'finding_kind' => ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP,
        ])->toArray();

        $this->assertSame(8.5, $shape['inputs']['certification_quality_score']);
        $this->assertTrue($shape['outputs']['context_quality_degraded']);
        $this->assertTrue($shape['outputs']['boosts_context_memory_retrieval_gap_finding']);
        $this->assertSame(25, $shape['outputs']['priority_boost_points']);
    }

    public function test_from_array_boosts_context_gap_finding_when_certification_blocked(): void
    {
        $shape = ContextQualityScoreContract::fromArray([
            'certification_quality_score' => 9.8,
            'certification_target_score' => 9.8,
            'certification_status' => 'blocked',
            'finding_kind' => ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP,
        ])->toArray();

        $this->assertTrue($shape['outputs']['context_quality_degraded']);
        $this->assertTrue($shape['outputs']['boosts_context_memory_retrieval_gap_finding']);
        $this->assertSame(25, $shape['outputs']['priority_boost_points']);
    }

    public function test_from_array_does_not_boost_non_context_gap_findings_when_degraded(): void
    {
        $shape = ContextQualityScoreContract::fromArray([
            'certification_quality_score' => 7.0,
            'finding_kind' => 'runtime_gap',
        ])->toArray();

        $this->assertTrue($shape['outputs']['context_quality_degraded']);
        $this->assertFalse($shape['outputs']['boosts_context_memory_retrieval_gap_finding']);
        $this->assertSame(0, $shape['outputs']['priority_boost_points']);
    }

    public function test_from_array_clamps_certification_quality_score(): void
    {
        $shape = ContextQualityScoreContract::fromArray([
            'certification_quality_score' => 15.0,
            'certification_target_score' => -1.0,
        ])->toArray();

        $this->assertSame(10.0, $shape['inputs']['certification_quality_score']);
        $this->assertSame(0.0, $shape['inputs']['certification_target_score']);
    }
}
