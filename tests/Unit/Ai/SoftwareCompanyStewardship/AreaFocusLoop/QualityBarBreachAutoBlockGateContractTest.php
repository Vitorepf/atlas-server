<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AgenticEngineeringOs\QualityBarTelemetryContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\QualityBarBreachAutoBlockGateContract;
use Tests\TestCase;

final class QualityBarBreachAutoBlockGateContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/QualityBarBreachAutoBlockGateContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(QualityBarBreachAutoBlockGateContract::class));
    }

    public function test_default_shape_declares_stewardship_quality_bar_auto_block_gate(): void
    {
        $shape = QualityBarBreachAutoBlockGateContract::defaults()->toArray();

        $this->assertSame(QualityBarBreachAutoBlockGateContract::SCHEMA, $shape['schema_version']);
        $this->assertSame(QualityBarTelemetryContract::IMMUNE_GATE_ID, $shape['immune_gate_id']);
        $this->assertSame(QualityBarTelemetryContract::BREACH_SIGNAL, $shape['breach_signal']);
        $this->assertTrue($shape['auto_block_on_breach']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
            $shape['quality_bar_matrix_canonical'],
        );
        $this->assertSame(0, $shape['max_allowed_dept_quality_bar_breach_count']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'department_id' => '',
            'breach_count' => 0,
            'evaluated_window_days' => QualityBarTelemetryContract::EVALUATED_WINDOW_DAYS,
        ], $shape['inputs']);
        $this->assertFalse($shape['outputs']['blocks_24h_autonomy']);
        $this->assertNull($shape['outputs']['blocker_id']);
    }

    public function test_from_array_blocks_24h_autonomy_when_breach_count_exceeds_matrix_floor(): void
    {
        $shape = QualityBarBreachAutoBlockGateContract::fromArray([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'department_id' => 'dev',
            'breach_count' => 1,
        ])->toArray();

        $this->assertSame('dev', $shape['inputs']['department_id']);
        $this->assertSame(1, $shape['inputs']['breach_count']);
        $this->assertTrue($shape['outputs']['blocks_24h_autonomy']);
        $this->assertSame('quality_bar_auto_block', $shape['outputs']['blocker_id']);
    }

    public function test_from_array_does_not_block_when_breach_count_at_matrix_floor(): void
    {
        $shape = QualityBarBreachAutoBlockGateContract::fromArray([
            'department_id' => 'forge',
            'breach_count' => 0,
        ])->toArray();

        $this->assertFalse($shape['outputs']['blocks_24h_autonomy']);
        $this->assertNull($shape['outputs']['blocker_id']);
    }
}
