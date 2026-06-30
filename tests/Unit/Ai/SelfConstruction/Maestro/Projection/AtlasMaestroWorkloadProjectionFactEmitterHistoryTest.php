<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadProjectionFactEmitter;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroWorkloadProjectionFactEmitterHistoryTest extends TestCase
{
    private string $logPath;

    private AtlasMaestroWorkloadProjectionFactEmitter $emitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = sys_get_temp_dir().'/atlas-maestro-workload-projection-fact-test-'.uniqid('', true).'.jsonl';
        $this->emitter = new AtlasMaestroWorkloadProjectionFactEmitter();
        $this->emitter->setFactLogPathForTesting($this->logPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            @unlink($this->logPath);
        }
        parent::tearDown();
    }

    public function test_fact_emission_appends_one_jsonl_row_with_required_fields(): void
    {
        $result = $this->emitter->emitFactRow([
            'claimable_depth' => 12,
            'active_workers' => 4,
            'telemetry_confidence' => 0.9,
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertArrayHasKey('generated_at', $result['row']);
        $this->assertSame(12, $result['row']['claimable_depth']);
        $this->assertSame(4, $result['row']['active_workers']);
        $this->assertSame(0.9, $result['row']['telemetry_confidence']);

        $history = $this->emitter->factHistory();
        $this->assertCount(1, $history);
    }

    public function test_repeated_emissions_append_new_rows_without_overwriting_older_history(): void
    {
        $this->emitter->emitFactRow(['claimable_depth' => 5, 'active_workers' => 2, 'telemetry_confidence' => 0.8]);
        $this->emitter->emitFactRow(['claimable_depth' => 10, 'active_workers' => 3, 'telemetry_confidence' => 0.7]);
        $this->emitter->emitFactRow(['claimable_depth' => 15, 'active_workers' => 4, 'telemetry_confidence' => 0.6]);

        $history = $this->emitter->factHistory();

        $this->assertCount(3, $history);
        $this->assertSame(5, $history[0]['claimable_depth']);
        $this->assertSame(10, $history[1]['claimable_depth']);
        $this->assertSame(15, $history[2]['claimable_depth']);
    }

    public function test_malformed_input_is_rejected_with_missing_fields_and_does_not_append(): void
    {
        $result = $this->emitter->emitFactRow(['claimable_depth' => 5]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('active_workers', $result['missing_fields']);
        $this->assertContains('telemetry_confidence', $result['missing_fields']);
        $this->assertNull($result['row']);
        $this->assertSame([], $this->emitter->factHistory());
    }

    public function test_empty_input_is_rejected_with_all_three_missing_fields(): void
    {
        $result = $this->emitter->emitFactRow([]);

        $this->assertFalse($result['accepted']);
        $this->assertCount(3, $result['missing_fields']);
    }

    public function test_valid_row_after_rejected_row_still_appends_correctly(): void
    {
        $this->emitter->emitFactRow(['claimable_depth' => 5]);
        $this->emitter->emitFactRow(['claimable_depth' => 7, 'active_workers' => 2, 'telemetry_confidence' => 1.0]);

        $history = $this->emitter->factHistory();
        $this->assertCount(1, $history);
        $this->assertSame(7, $history[0]['claimable_depth']);
    }
}
