<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\QualityBarTelemetryContract;
use Tests\TestCase;

final class QualityBarTelemetryContractTest extends TestCase
{
    private const CONTRACT_PATH = __DIR__.'/../../../../app/Services/Ai/AgenticEngineeringOs/QualityBarTelemetryContract.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(QualityBarTelemetryContract::class, false)) {
            require_once self::CONTRACT_PATH;
        }
    }

    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $this->assertFileExists(self::CONTRACT_PATH);
        $this->assertTrue(class_exists(QualityBarTelemetryContract::class));
    }

    public function test_default_shape_declares_quality_bar_telemetry_immune_gate(): void
    {
        $shape = QualityBarTelemetryContract::defaults()->toArray();

        $this->assertSame(QualityBarTelemetryContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('atlas.aaeos.quality_bar.v1', $shape['quality_bar_schema']);
        $this->assertSame('quality_bar_auto_block', $shape['immune_gate_id']);
        $this->assertSame('dept_quality_bar_breach_count', $shape['breach_signal']);
        $this->assertSame('atlas.aaeos.quality_bar', $shape['canonical_source']);
        $this->assertSame(30, $shape['evaluated_window_days']);
        $this->assertTrue($shape['auto_block_on_breach']);
        $this->assertSame([
            'quality_bar_report_hash',
            'breach_metrics',
            'evaluated_at',
        ], $shape['evidence_required']);
        $this->assertSame([
            'department_id',
            'breach_count',
            'threshold_breaches',
            'evaluated_window_days',
            'evidence_hash',
        ], $shape['telemetry_fields']);
        $this->assertSame([
            'department_id' => '',
            'breach_count' => 0,
            'evaluated_window_days' => 30,
            'evidence_hash' => '',
            'threshold_breaches' => [],
        ], $shape['inputs']);
    }

    public function test_from_array_preserves_breach_telemetry_inputs(): void
    {
        $shape = QualityBarTelemetryContract::fromArray([
            'department_id' => 'dev',
            'breach_count' => 2,
            'evaluated_window_days' => 30,
            'evidence_hash' => 'sha256:abc',
            'threshold_breaches' => [
                [
                    'metric' => 'gate_pass_rate',
                    'comparator' => '>=',
                    'value' => 0.95,
                    'observed' => 0.88,
                    'unit' => 'ratio',
                ],
            ],
        ])->toArray();

        $this->assertSame('dev', $shape['inputs']['department_id']);
        $this->assertSame(2, $shape['inputs']['breach_count']);
        $this->assertSame('sha256:abc', $shape['inputs']['evidence_hash']);
        $this->assertCount(1, $shape['inputs']['threshold_breaches']);
        $this->assertSame('gate_pass_rate', $shape['inputs']['threshold_breaches'][0]['metric']);
    }
}
