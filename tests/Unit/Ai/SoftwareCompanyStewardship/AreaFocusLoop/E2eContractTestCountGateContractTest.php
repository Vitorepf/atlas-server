<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\E2eContractTestCountGateContract;
use Tests\TestCase;

final class E2eContractTestCountGateContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/E2eContractTestCountGateContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(E2eContractTestCountGateContract::class));
    }

    public function test_default_shape_declares_e2e_contract_test_count_gate(): void
    {
        $shape = E2eContractTestCountGateContract::defaults()->toArray();

        $this->assertSame(E2eContractTestCountGateContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('e2e_contract_test_count_gate', $shape['gate_id']);
        $this->assertTrue($shape['informational_signal_only']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
            $shape['quality_bar_matrix_canonical'],
        );
        $this->assertSame(10, $shape['min_contract_test_count_l3']);
        $this->assertSame(30, $shape['min_contract_test_count_l4']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'department_id' => 'qa',
            'contract_test_count' => 0,
        ], $shape['inputs']);
        $this->assertFalse($shape['outputs']['meets_l3_contract_test_floor']);
        $this->assertFalse($shape['outputs']['meets_l4_contract_test_floor']);
        $this->assertTrue($shape['outputs']['below_l4_contract_test_floor']);
        $this->assertFalse($shape['outputs']['blocks_long_run_readiness']);
    }

    public function test_from_array_meets_l4_floor_when_count_at_matrix_threshold(): void
    {
        $shape = E2eContractTestCountGateContract::fromArray([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'department_id' => 'qa',
            'contract_test_count' => 30,
        ])->toArray();

        $this->assertSame('qa', $shape['inputs']['department_id']);
        $this->assertSame(30, $shape['inputs']['contract_test_count']);
        $this->assertTrue($shape['outputs']['meets_l3_contract_test_floor']);
        $this->assertTrue($shape['outputs']['meets_l4_contract_test_floor']);
        $this->assertFalse($shape['outputs']['below_l4_contract_test_floor']);
        $this->assertFalse($shape['outputs']['blocks_long_run_readiness']);
    }

    public function test_from_array_reports_below_l4_floor_as_informational_signal(): void
    {
        $shape = E2eContractTestCountGateContract::fromArray([
            'department_id' => 'qa',
            'contract_test_count' => 12,
        ])->toArray();

        $this->assertTrue($shape['outputs']['meets_l3_contract_test_floor']);
        $this->assertFalse($shape['outputs']['meets_l4_contract_test_floor']);
        $this->assertTrue($shape['outputs']['below_l4_contract_test_floor']);
        $this->assertFalse($shape['outputs']['blocks_long_run_readiness']);
    }
}
