<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CrossReviewAutomaticGateContract;
use Tests\TestCase;

final class CrossReviewAutomaticGateContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/CrossReviewAutomaticGateContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(CrossReviewAutomaticGateContract::class));
    }

    public function test_default_shape_declares_review_cross_review_automatic_gate(): void
    {
        $shape = CrossReviewAutomaticGateContract::defaults()->toArray();

        $this->assertSame(CrossReviewAutomaticGateContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('cross_review_automatic_gate', $shape['gate_id']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md',
            $shape['maturity_matrix_canonical'],
        );
        $this->assertSame('review', $shape['department_id']);
        $this->assertSame('R4', $shape['maturity_target']);
        $this->assertTrue($shape['mandatory_secondary_review_on_cross_system']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame([
            'cross_system' => false,
            'changed_files' => [],
        ], $shape['inputs']);
        $this->assertFalse($shape['outputs']['cross_system']);
        $this->assertFalse($shape['outputs']['requires_mandatory_secondary_review']);
        $this->assertNull($shape['outputs']['automatic_secondary_review_route']);
    }

    public function test_from_array_tags_cross_system_and_requires_mandatory_secondary_review(): void
    {
        $shape = CrossReviewAutomaticGateContract::fromArray([
            'area_id' => 'agentic_engineering_os',
            'cross_system' => true,
            'changed_files' => ['app/Services/Ai/Mission/MissionService.php', 'atlas-desktop/src/lib.rs'],
        ])->toArray();

        $this->assertTrue($shape['inputs']['cross_system']);
        $this->assertSame([
            'app/Services/Ai/Mission/MissionService.php',
            'atlas-desktop/src/lib.rs',
        ], $shape['inputs']['changed_files']);
        $this->assertTrue($shape['outputs']['cross_system']);
        $this->assertTrue($shape['outputs']['requires_mandatory_secondary_review']);
        $this->assertSame('cross_review_lane', $shape['outputs']['automatic_secondary_review_route']);
    }

    public function test_from_array_does_not_require_secondary_review_when_not_cross_system(): void
    {
        $shape = CrossReviewAutomaticGateContract::fromArray([
            'cross_system' => false,
            'changed_files' => ['docs/README.md'],
        ])->toArray();

        $this->assertFalse($shape['inputs']['cross_system']);
        $this->assertSame(['docs/README.md'], $shape['inputs']['changed_files']);
        $this->assertFalse($shape['outputs']['cross_system']);
        $this->assertFalse($shape['outputs']['requires_mandatory_secondary_review']);
        $this->assertNull($shape['outputs']['automatic_secondary_review_route']);
    }
}
