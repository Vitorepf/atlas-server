<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroMuscleThroughputContinuityModel;
use Tests\TestCase;

final class AtlasMaestroMuscleThroughputContinuityModelProjectionTest extends TestCase
{
    private function model(): AtlasMaestroMuscleThroughputContinuityModel
    {
        return new AtlasMaestroMuscleThroughputContinuityModel;
    }

    public function test_direct_serve_telemetry_produces_throughput_mode_direct(): void
    {
        $result = $this->model()->model([
            'servable_now' => 10,
            'serve_total' => 50,
        ]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::THROUGHPUT_MODE_DIRECT, $result['throughput_mode']);
    }

    public function test_recent_completions_alone_also_produces_throughput_mode_direct(): void
    {
        $result = $this->model()->model([
            'servable_now' => 10,
            'recent_completions' => 3,
        ]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::THROUGHPUT_MODE_DIRECT, $result['throughput_mode']);
    }

    public function test_projection_history_with_deltas_but_zero_serve_total_produces_estimated(): void
    {
        $result = $this->model()->model([
            'servable_now' => 10,
            'serve_total' => 0,
            'projection_history' => [
                ['claim_delta' => 4, 'completion_delta' => 2],
                ['claim_delta' => 3, 'completion_delta' => 1],
            ],
        ]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::THROUGHPUT_MODE_ESTIMATED, $result['throughput_mode']);
        $this->assertTrue((bool) array_filter(
            $result['reasons'],
            static fn (string $r): bool => str_contains($r, 'throughput_mode_estimated_from_projection_history'),
        ));
    }

    public function test_no_direct_or_estimated_signal_produces_blind_with_caution_reason(): void
    {
        $result = $this->model()->model([
            'servable_now' => 0,
        ]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::THROUGHPUT_MODE_BLIND, $result['throughput_mode']);
        $this->assertContains('caution:zero_telemetry_does_not_mean_zero_real_work', $result['reasons']);
    }
}
