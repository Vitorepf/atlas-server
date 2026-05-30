<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\MetricLedgerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MetricLedgerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_metric_ledger_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): MetricLedgerService
    {
        $service = new MetricLedgerService();
        $service->setStorageRootForTesting($this->tmp);

        return $service;
    }

    public function test_normalize_contract_rejects_incomplete_or_non_numeric_contracts(): void
    {
        $s = $this->service();
        $this->assertNull($s->normalizeContract([]));
        $this->assertNull($s->normalizeContract(['metric_id' => 'm', 'baseline' => 1, 'target_delta' => 1]));
        $this->assertNull($s->normalizeContract([
            'metric_id' => 'm', 'baseline' => 'x', 'target_delta' => 1, 'measure_command' => 'echo',
        ]));
        $this->assertNull($s->normalizeContract([
            'metric_id' => '', 'baseline' => 1, 'target_delta' => 1, 'measure_command' => 'echo',
        ]));

        $ok = $s->normalizeContract([
            'metric_id' => 'cov', 'baseline' => '45.3', 'target_delta' => '5', 'measure_command' => 'echo',
        ]);
        $this->assertSame('cov', $ok['metric_id']);
        $this->assertSame(45.3, $ok['baseline']);
        $this->assertSame(5.0, $ok['target_delta']);
        $this->assertSame('metric', $ok['metric_json_path']);
    }

    public function test_measured_delta_meeting_target_is_outcome_met(): void
    {
        $s = $this->service();
        $s->setMeasurerForTesting(fn (): array => ['ok' => true, 'exit_code' => 0, 'out' => '{"metric": 50.0}', 'err' => '']);

        $event = $s->measureOutcome(
            (array) $s->normalizeContract(['metric_id' => 'cov', 'baseline' => 45.0, 'target_delta' => 5.0, 'measure_command' => 'echo']),
            ['area_id' => 'area', 'merge_hash' => 'abc'],
        );

        $this->assertSame(MetricLedgerService::STATUS_OUTCOME_MET, $event['status']);
        $this->assertTrue($event['outcome_met']);
        $this->assertEquals(5.0, $event['measured_delta']);
        $this->assertFileExists($s->ledgerPath('area'));
    }

    public function test_measured_delta_below_target_is_outcome_not_met(): void
    {
        $s = $this->service();
        $s->setMeasurerForTesting(fn (): array => ['ok' => true, 'exit_code' => 0, 'out' => '49.0', 'err' => '']);

        $event = $s->measureOutcome(
            (array) $s->normalizeContract(['metric_id' => 'cov', 'baseline' => 45.0, 'target_delta' => 5.0, 'measure_command' => 'echo']),
            ['area_id' => 'area'],
        );

        $this->assertSame(MetricLedgerService::STATUS_OUTCOME_NOT_MET, $event['status']);
        $this->assertFalse($event['outcome_met']);
    }

    public function test_failed_measurement_is_never_a_pass(): void
    {
        $s = $this->service();
        $s->setMeasurerForTesting(fn (): array => ['ok' => false, 'exit_code' => 1, 'out' => '', 'err' => 'boom']);

        $event = $s->measureOutcome(
            (array) $s->normalizeContract(['metric_id' => 'cov', 'baseline' => 45.0, 'target_delta' => 5.0, 'measure_command' => 'echo']),
            ['area_id' => 'area'],
        );

        $this->assertSame(MetricLedgerService::STATUS_MEASUREMENT_FAILED, $event['status']);
        $this->assertFalse($event['outcome_met']);
        $this->assertNull($event['measured_value']);
    }

    public function test_unparseable_metric_output_is_measurement_failed_not_pass(): void
    {
        $s = $this->service();
        $s->setMeasurerForTesting(fn (): array => ['ok' => true, 'exit_code' => 0, 'out' => 'no number here', 'err' => '']);

        $event = $s->measureOutcome(
            (array) $s->normalizeContract(['metric_id' => 'cov', 'baseline' => 45.0, 'target_delta' => 5.0, 'measure_command' => 'echo']),
            ['area_id' => 'area'],
        );

        $this->assertSame(MetricLedgerService::STATUS_MEASUREMENT_FAILED, $event['status']);
        $this->assertFalse($event['outcome_met']);
    }
}
