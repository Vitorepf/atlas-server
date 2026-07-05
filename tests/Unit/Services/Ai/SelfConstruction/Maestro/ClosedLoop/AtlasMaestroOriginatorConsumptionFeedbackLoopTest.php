<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroOriginatorConsumptionFeedbackLoop;
use Tests\TestCase;

final class AtlasMaestroOriginatorConsumptionFeedbackLoopTest extends TestCase
{
    private function loop(): AtlasMaestroOriginatorConsumptionFeedbackLoop
    {
        return new AtlasMaestroOriginatorConsumptionFeedbackLoop;
    }

    // ── AC: low consumption reduces batch size ──

    public function test_low_consumption_reduces_batch_size(): void
    {
        $result = $this->loop()->feedback([
            'total_emitted' => 10,
            'total_consumed' => 2,
            'completed_count' => 1,
            'give_back_count' => 1,
            'current_batch_size' => 10,
        ]);

        $this->assertSame('reduce', $result['batch_adjustment']);
        $this->assertLessThan(10, $result['adjusted_batch_size']);
    }

    // ── AC: high completion quality expands the winning vein ──

    public function test_high_completion_quality_expands_winning_vein(): void
    {
        $result = $this->loop()->feedback([
            'total_emitted' => 10,
            'total_consumed' => 10,
            'completed_count' => 8,
            'give_back_count' => 1,
            'current_batch_size' => 5,
            'winning_vein' => 'implementation',
        ]);

        $this->assertSame('expand', $result['vein_adjustment']);
        $this->assertSame('expansion_first', $result['dispatch_hint']);
    }

    // ── AC: give_back spikes force repair-first ──

    public function test_give_back_spike_forces_repair_first(): void
    {
        $result = $this->loop()->feedback([
            'total_emitted' => 10,
            'total_consumed' => 10,
            'completed_count' => 3,
            'give_back_count' => 5,
            'current_batch_size' => 5,
        ]);

        $this->assertSame('repair_first', $result['dispatch_hint']);
        $this->assertSame('pivot', $result['vein_adjustment']);
    }

    // ── no adjustment needed ──

    public function test_balanced_consumption_maintains(): void
    {
        $result = $this->loop()->feedback([
            'total_emitted' => 10,
            'total_consumed' => 8,
            'completed_count' => 5,
            'give_back_count' => 2,
            'current_batch_size' => 5,
        ]);

        $this->assertSame('maintain', $result['batch_adjustment']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->loop()->feedback([]);

        $this->assertSame(AtlasMaestroOriginatorConsumptionFeedbackLoop::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('batch_adjustment', $result);
        $this->assertArrayHasKey('vein_adjustment', $result);
        $this->assertArrayHasKey('dispatch_hint', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'total_emitted' => 10,
            'total_consumed' => 5,
            'completed_count' => 3,
            'give_back_count' => 1,
            'current_batch_size' => 5,
        ];

        $a = $this->loop()->feedback($input);
        $b = $this->loop()->feedback($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
