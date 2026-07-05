<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroQueuePressureBatchSizer;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroQueuePressureBatchSizerTest extends TestCase
{
    private AtlasMaestroQueuePressureBatchSizer $sizer;

    protected function setUp(): void
    {
        $this->sizer = new AtlasMaestroQueuePressureBatchSizer;
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'claimable_depth' => 10,
            'active_workers' => 2,
            'serve_rate_per_minute' => 1.0,
            'malformed_count' => 0,
            'drain_confidence' => 0.5,
        ], $overrides);
    }

    // ── AC: low pressure selects 5 ──

    public function test_low_pressure_selects_five(): void
    {
        $result = $this->sizer->size($this->input([
            'claimable_depth' => 3,
            'active_workers' => 2,
        ]));

        $this->assertSame(5, $result['batch_size']);
        $this->assertSame('low_pressure', $result['batch_kind']);
    }

    // ── AC: normal pressure selects 8-10 ──

    public function test_normal_pressure_with_low_confidence_selects_eight(): void
    {
        $result = $this->sizer->size($this->input([
            'claimable_depth' => 6,
            'active_workers' => 2,
            'drain_confidence' => 0.5,
        ]));

        $this->assertSame(8, $result['batch_size']);
        $this->assertSame('normal_pressure', $result['batch_kind']);
    }

    public function test_normal_pressure_with_high_confidence_selects_ten(): void
    {
        $result = $this->sizer->size($this->input([
            'claimable_depth' => 6,
            'active_workers' => 2,
            'drain_confidence' => 0.8,
        ]));

        $this->assertSame(10, $result['batch_size']);
        $this->assertSame('normal_pressure', $result['batch_kind']);
    }

    // ── AC: dry queue selects 12 ──

    public function test_dry_queue_selects_twelve(): void
    {
        $result = $this->sizer->size($this->input([
            'claimable_depth' => 0,
            'active_workers' => 2,
        ]));

        $this->assertSame(12, $result['batch_size']);
        $this->assertSame('max_replenishment', $result['batch_kind']);
    }

    // ── AC: malformed pressure selects repair-only batches ──

    public function test_malformed_pressure_selects_repair_only(): void
    {
        $result = $this->sizer->size($this->input([
            'malformed_count' => 3,
        ]));

        $this->assertSame(0, $result['batch_size']);
        $this->assertSame('repair_only', $result['batch_kind']);
        $this->assertStringContainsString('malformed_count=3', $result['reasons'][0]);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->sizer->size($this->input());

        $this->assertSame(AtlasMaestroQueuePressureBatchSizer::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('batch_size', $result);
        $this->assertArrayHasKey('batch_kind', $result);
        $this->assertArrayHasKey('reasons', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = $this->input();
        $a = $this->sizer->size($input);
        $b = $this->sizer->size($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
