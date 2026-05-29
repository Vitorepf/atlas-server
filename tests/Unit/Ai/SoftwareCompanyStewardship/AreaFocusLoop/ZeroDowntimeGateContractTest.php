<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ZeroDowntimeGateContract;
use Tests\TestCase;

final class ZeroDowntimeGateContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ZeroDowntimeGateContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(ZeroDowntimeGateContract::class));
    }

    public function test_default_shape_declares_delivery_zero_downtime_gate(): void
    {
        $shape = ZeroDowntimeGateContract::defaults()->toArray();

        $this->assertSame(ZeroDowntimeGateContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('zero_downtime_gate', $shape['gate_id']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md',
            $shape['maturity_matrix_canonical'],
        );
        $this->assertSame([
            'no_migration_without_rollback',
            'no_breaking_schema_change_without_feature_flag',
        ], $shape['invariants']);
        $this->assertSame(0, $shape['max_allowed_migrations_without_rollback']);
        $this->assertSame(0, $shape['max_allowed_breaking_schema_changes_without_feature_flag']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'department_id' => 'delivery',
            'migrations_without_rollback_count' => 0,
            'breaking_schema_changes_without_feature_flag_count' => 0,
        ], $shape['inputs']);
        $this->assertTrue($shape['outputs']['satisfies_zero_downtime_invariant']);
        $this->assertFalse($shape['outputs']['blocks_dev_forge_release']);
        $this->assertSame([], $shape['outputs']['blocker_ids']);
    }

    public function test_from_array_blocks_release_when_migration_lacks_rollback(): void
    {
        $shape = ZeroDowntimeGateContract::fromArray([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'department_id' => 'delivery',
            'migrations_without_rollback_count' => 1,
        ])->toArray();

        $this->assertSame(1, $shape['inputs']['migrations_without_rollback_count']);
        $this->assertFalse($shape['outputs']['satisfies_zero_downtime_invariant']);
        $this->assertTrue($shape['outputs']['blocks_dev_forge_release']);
        $this->assertSame(['no_migration_without_rollback'], $shape['outputs']['blocker_ids']);
    }

    public function test_from_array_blocks_release_when_breaking_schema_change_lacks_feature_flag(): void
    {
        $shape = ZeroDowntimeGateContract::fromArray([
            'department_id' => 'delivery',
            'breaking_schema_changes_without_feature_flag_count' => 1,
        ])->toArray();

        $this->assertSame(1, $shape['inputs']['breaking_schema_changes_without_feature_flag_count']);
        $this->assertFalse($shape['outputs']['satisfies_zero_downtime_invariant']);
        $this->assertTrue($shape['outputs']['blocks_dev_forge_release']);
        $this->assertSame(
            ['no_breaking_schema_change_without_feature_flag'],
            $shape['outputs']['blocker_ids'],
        );
    }

    public function test_from_array_does_not_block_when_release_plan_satisfies_invariants(): void
    {
        $shape = ZeroDowntimeGateContract::fromArray([
            'department_id' => 'delivery',
            'migrations_without_rollback_count' => 0,
            'breaking_schema_changes_without_feature_flag_count' => 0,
        ])->toArray();

        $this->assertTrue($shape['outputs']['satisfies_zero_downtime_invariant']);
        $this->assertFalse($shape['outputs']['blocks_dev_forge_release']);
        $this->assertSame([], $shape['outputs']['blocker_ids']);
    }
}
