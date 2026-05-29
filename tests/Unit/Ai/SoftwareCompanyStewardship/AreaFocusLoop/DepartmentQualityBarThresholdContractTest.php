<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AgenticEngineeringOs\QualityBarTelemetryContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DepartmentQualityBarThresholdContract;
use Tests\TestCase;

final class DepartmentQualityBarThresholdContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DepartmentQualityBarThresholdContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(DepartmentQualityBarThresholdContract::class));
    }

    public function test_default_shape_declares_department_quality_bar_l3_threshold_contract(): void
    {
        $shape = DepartmentQualityBarThresholdContract::defaults()->toArray();

        $this->assertSame(DepartmentQualityBarThresholdContract::SCHEMA, $shape['schema_version']);
        $this->assertSame(QualityBarTelemetryContract::QUALITY_BAR_SCHEMA, $shape['quality_bar_schema']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
            $shape['quality_bar_matrix_canonical'],
        );
        $this->assertSame(QualityBarTelemetryContract::BREACH_SIGNAL, $shape['breach_signal']);
        $this->assertSame('L3', $shape['promotion_floor_level']);
        $this->assertSame(QualityBarTelemetryContract::EVALUATED_WINDOW_DAYS, $shape['evaluated_window_days']);
        $this->assertTrue($shape['informational_signal_only']);
        $this->assertArrayHasKey('dev', $shape['l3_thresholds']);
        $this->assertArrayHasKey('forge', $shape['l3_thresholds']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame(['department_metrics_snapshot' => []], $shape['inputs']);
        $this->assertSame(8, $shape['outputs']['breach_count']);
        $this->assertTrue($shape['outputs']['would_block_ladder_promotion']);
        $this->assertFalse($shape['outputs']['blocks_ladder_promotion']);
        $this->assertSame('dept_quality_bar_l3_threshold_breach', $shape['outputs']['blocker_id']);
    }

    public function test_from_array_reports_no_breach_when_all_l3_metrics_meet_matrix_floor(): void
    {
        $shape = DepartmentQualityBarThresholdContract::fromArray([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'department_metrics_snapshot' => [
                'dev' => [
                    'latency_p95' => 45.0,
                    'tests_pass_rate' => 0.97,
                    'scope_violation_rate' => 0.005,
                    'repair_loop_avg' => 0.8,
                ],
                'forge' => [
                    'obra_completion_rate' => 0.90,
                    'cert_pass_rate' => 0.95,
                    'rollback_rate' => 0.02,
                    'multi_agent_collision_rate' => 0.01,
                ],
            ],
        ])->toArray();

        $this->assertSame(0, $shape['outputs']['breach_count']);
        $this->assertSame([], $shape['outputs']['threshold_breaches']);
        $this->assertFalse($shape['outputs']['would_block_ladder_promotion']);
        $this->assertFalse($shape['outputs']['blocks_ladder_promotion']);
        $this->assertNull($shape['outputs']['blocker_id']);
    }

    public function test_from_array_reports_breach_when_dev_p95_latency_exceeds_l3_floor(): void
    {
        $shape = DepartmentQualityBarThresholdContract::fromArray([
            'department_metrics_snapshot' => [
                'dev' => [
                    'latency_p95' => 75.0,
                    'tests_pass_rate' => 0.97,
                    'scope_violation_rate' => 0.005,
                    'repair_loop_avg' => 0.8,
                ],
                'forge' => [
                    'obra_completion_rate' => 0.90,
                    'cert_pass_rate' => 0.95,
                    'rollback_rate' => 0.02,
                    'multi_agent_collision_rate' => 0.01,
                ],
            ],
        ])->toArray();

        $this->assertSame(1, $shape['outputs']['breach_count']);
        $this->assertSame('dev', $shape['outputs']['threshold_breaches'][0]['department_id']);
        $this->assertSame('latency_p95', $shape['outputs']['threshold_breaches'][0]['metric']);
        $this->assertSame('<=', $shape['outputs']['threshold_breaches'][0]['comparator']);
        $this->assertSame(60.0, $shape['outputs']['threshold_breaches'][0]['threshold']);
        $this->assertSame(75.0, $shape['outputs']['threshold_breaches'][0]['observed']);
        $this->assertTrue($shape['outputs']['would_block_ladder_promotion']);
        $this->assertFalse($shape['outputs']['blocks_ladder_promotion']);
        $this->assertSame('dept_quality_bar_l3_threshold_breach', $shape['outputs']['blocker_id']);
    }

    public function test_from_array_reports_breach_when_forge_rollback_rate_exceeds_l3_floor(): void
    {
        $shape = DepartmentQualityBarThresholdContract::fromArray([
            'department_metrics_snapshot' => [
                'dev' => [
                    'latency_p95' => 45.0,
                    'tests_pass_rate' => 0.97,
                    'scope_violation_rate' => 0.005,
                    'repair_loop_avg' => 0.8,
                ],
                'forge' => [
                    'obra_completion_rate' => 0.90,
                    'cert_pass_rate' => 0.95,
                    'rollback_rate' => 0.06,
                    'multi_agent_collision_rate' => 0.01,
                ],
            ],
        ])->toArray();

        $this->assertSame(1, $shape['outputs']['breach_count']);
        $this->assertSame('forge', $shape['outputs']['threshold_breaches'][0]['department_id']);
        $this->assertSame('rollback_rate', $shape['outputs']['threshold_breaches'][0]['metric']);
        $this->assertTrue($shape['outputs']['would_block_ladder_promotion']);
        $this->assertFalse($shape['outputs']['blocks_ladder_promotion']);
    }
}
