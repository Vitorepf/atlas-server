<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DepartmentMaturityCheckContract;
use Tests\TestCase;

final class DepartmentMaturityCheckContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DepartmentMaturityCheckContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(DepartmentMaturityCheckContract::class));
    }

    public function test_default_shape_declares_department_maturity_check(): void
    {
        $shape = DepartmentMaturityCheckContract::defaults()->toArray();

        $this->assertSame(DepartmentMaturityCheckContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('department_maturity_check', $shape['check_id']);
        $this->assertSame('atlas.aaeos.department_maturity.v1', $shape['maturity_report_schema']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md',
            $shape['maturity_matrix_canonical'],
        );
        $this->assertSame('L2', $shape['min_routing_level']);
        $this->assertSame('R3+', $shape['routing_tier_r3_plus']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'routing_tier' => 'R3+',
            'required_department_ids' => ['dev'],
            'department_maturity_snapshot' => ['dev' => 'L2'],
        ], $shape['inputs']);
        $this->assertFalse($shape['outputs']['blocks_preflight_cycle']);
        $this->assertSame([], $shape['outputs']['blocker_ids']);
        $this->assertSame([], $shape['outputs']['immature_department_ids']);
    }

    public function test_from_array_blocks_preflight_when_required_department_at_l1(): void
    {
        $shape = DepartmentMaturityCheckContract::fromArray([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'routing_tier' => 'R3+',
            'required_department_ids' => ['dev'],
            'department_maturity_snapshot' => ['dev' => 'L1'],
        ])->toArray();

        $this->assertSame('L1', $shape['inputs']['department_maturity_snapshot']['dev']);
        $this->assertTrue($shape['outputs']['blocks_preflight_cycle']);
        $this->assertSame(['required_department_below_l2'], $shape['outputs']['blocker_ids']);
        $this->assertSame(['dev'], $shape['outputs']['immature_department_ids']);
    }

    public function test_from_array_blocks_preflight_when_required_department_missing_from_snapshot(): void
    {
        $shape = DepartmentMaturityCheckContract::fromArray([
            'routing_tier' => 'R3',
            'required_department_ids' => ['dev'],
            'department_maturity_snapshot' => [],
        ])->toArray();

        $this->assertTrue($shape['outputs']['blocks_preflight_cycle']);
        $this->assertSame(['required_department_below_l2'], $shape['outputs']['blocker_ids']);
        $this->assertSame(['dev'], $shape['outputs']['immature_department_ids']);
    }

    public function test_from_array_does_not_block_r3_plus_when_all_required_departments_at_l2_or_above(): void
    {
        $shape = DepartmentMaturityCheckContract::fromArray([
            'routing_tier' => 'R3+',
            'required_department_ids' => ['dev', 'forge'],
            'department_maturity_snapshot' => [
                'dev' => 'L2',
                'forge' => 'L4',
            ],
        ])->toArray();

        $this->assertFalse($shape['outputs']['blocks_preflight_cycle']);
        $this->assertSame([], $shape['outputs']['blocker_ids']);
        $this->assertSame([], $shape['outputs']['immature_department_ids']);
    }

    public function test_from_array_does_not_block_when_routing_tier_below_r3(): void
    {
        $shape = DepartmentMaturityCheckContract::fromArray([
            'routing_tier' => 'R2',
            'required_department_ids' => ['dev'],
            'department_maturity_snapshot' => ['dev' => 'L1'],
        ])->toArray();

        $this->assertFalse($shape['outputs']['blocks_preflight_cycle']);
        $this->assertSame([], $shape['outputs']['blocker_ids']);
        $this->assertSame([], $shape['outputs']['immature_department_ids']);
    }
}
