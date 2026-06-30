<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroDrainContinuitySloCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroDrainContinuitySloCompilerProjectionTest extends TestCase
{
    private function compiler(): AtlasMaestroDrainContinuitySloCompiler
    {
        return new AtlasMaestroDrainContinuitySloCompiler();
    }

    public function test_claimable_per_active_worker_at_least_five_returns_sufficient_depth(): void
    {
        $result = $this->compiler()->compileFromProjection([
            'claimable_per_active_worker' => 5.0,
            'active_workers' => 4,
            'telemetry_confidence' => 'high',
        ]);

        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::VERDICT_SUFFICIENT_DEPTH, $result['verdict']);
    }

    public function test_active_workers_with_blind_telemetry_returns_telemetry_blind(): void
    {
        $result = $this->compiler()->compileFromProjection([
            'claimable_per_active_worker' => 10.0,
            'active_workers' => 3,
            'telemetry_confidence' => 'blind',
        ]);

        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::VERDICT_TELEMETRY_BLIND, $result['verdict']);
    }

    public function test_below_worker_floor_returns_replenish_soon_with_worker_floor_reason(): void
    {
        $result = $this->compiler()->compileFromProjection([
            'claimable_per_active_worker' => 1.5,
            'active_workers' => 4,
            'telemetry_confidence' => 'high',
        ]);

        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::VERDICT_REPLENISH_SOON, $result['verdict']);
        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::REASON_WORKER_FLOOR, $result['reason']);
    }

    public function test_telemetry_blind_takes_priority_over_worker_floor(): void
    {
        $result = $this->compiler()->compileFromProjection([
            'claimable_per_active_worker' => 1.0,
            'active_workers' => 4,
            'telemetry_confidence' => 'blind',
        ]);

        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::VERDICT_TELEMETRY_BLIND, $result['verdict']);
    }

    public function test_no_active_workers_with_blind_telemetry_does_not_trigger_blind_verdict(): void
    {
        $result = $this->compiler()->compileFromProjection([
            'claimable_per_active_worker' => 10.0,
            'active_workers' => 0,
            'telemetry_confidence' => 'blind',
        ]);

        $this->assertNotSame(AtlasMaestroDrainContinuitySloCompiler::VERDICT_TELEMETRY_BLIND, $result['verdict']);
    }

    public function test_mid_range_buffer_returns_watch(): void
    {
        $result = $this->compiler()->compileFromProjection([
            'claimable_per_active_worker' => 3.0,
            'active_workers' => 4,
            'telemetry_confidence' => 'high',
        ]);

        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::VERDICT_WATCH, $result['verdict']);
    }
}
